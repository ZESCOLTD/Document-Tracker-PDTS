<?php

use App\Enums\DocumentStatus;
use App\Enums\MovementStatus;
use App\Models\DocumentMovement;
use App\Models\PdtsDocument;
use App\Models\PdtsDocumentType;
use App\Models\User;
use App\Notifications\DocumentHandoverNotification;
use App\Services\Documents\CustodyException;
use App\Services\Documents\CustodyService;
use App\Services\Pdts\RegisterPdtsDocument;
use Database\Seeders\PdtsAccessSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(PdtsAccessSeeder::class);

    $this->custody = app(CustodyService::class);
    $this->sender = custodyUser('Originating Employee');
    $this->recipient = custodyUser('Supervisor');
    $this->document = PdtsDocument::factory()->create(['current_custodian_id' => $this->sender->id]);
});

function custodyUser(string $role): User
{
    return User::factory()->create()->assignRole($role);
}

describe('forward', function () {
    test('records a pending handover and keeps the sender as custodian', function () {
        $movement = $this->custody->forward($this->document, $this->sender, $this->recipient, 'Please review');

        $document = $this->document->fresh();

        expect($document->statusEnum())->toBe(DocumentStatus::AwaitingReceipt)
            ->and((int) $document->current_custodian_id)->toBe($this->sender->id)
            ->and($movement->status)->toBe(MovementStatus::AwaitingReceipt)
            ->and((int) $movement->sender_id)->toBe($this->sender->id)
            ->and((int) $movement->recipient_id)->toBe($this->recipient->id)
            ->and($movement->remarks)->toBe('Please review')
            ->and($movement->forwarded_at)->not->toBeNull()
            ->and($movement->acknowledged_at)->toBeNull();
    });

    test('is rejected when the user is not the custodian', function () {
        $this->custody->forward($this->document, $this->recipient, custodyUser('Manager'));
    })->throws(CustodyException::class, 'Only the current custodian');

    test('is rejected without the forward permission', function () {
        $auditor = custodyUser('Management / Auditor');
        $this->document->update(['current_custodian_id' => $auditor->id]);

        $this->custody->forward($this->document, $auditor, $this->recipient);
    })->throws(CustodyException::class, 'do not have permission');

    test('is rejected while another handover is pending', function () {
        $this->custody->forward($this->document, $this->sender, $this->recipient);

        $this->custody->forward($this->document, $this->sender, custodyUser('Manager'));
    })->throws(CustodyException::class, 'already has a handover');

    test('uses the current database state, not a stale copy of the document', function () {
        $stale = PdtsDocument::find($this->document->id);

        $this->custody->forward($this->document, $this->sender, $this->recipient);

        $this->custody->forward($stale, $this->sender, custodyUser('Manager'));
    })->throws(CustodyException::class, 'already has a handover');

    test('is rejected when forwarding to yourself', function () {
        $this->custody->forward($this->document, $this->sender, $this->sender);
    })->throws(CustodyException::class, 'cannot receive documents');

    test('is rejected for a banned recipient', function () {
        $this->recipient->update(['is_banned' => true]);

        $this->custody->forward($this->document, $this->sender, $this->recipient);
    })->throws(CustodyException::class, 'cannot receive documents');

    test('is rejected for a recipient banned until a future date', function () {
        $this->recipient->update(['banned_until' => now()->addDay()]);

        $this->custody->forward($this->document, $this->sender, $this->recipient);
    })->throws(CustodyException::class, 'cannot receive documents');

    test('is allowed once a temporary ban has ended', function () {
        $this->recipient->update(['banned_until' => now()->subDay()]);

        expect($this->custody->forward($this->document, $this->sender, $this->recipient))
            ->toBeInstanceOf(DocumentMovement::class);
    });

    test('is rejected for a deleted recipient', function () {
        $this->recipient->delete();

        $this->custody->forward($this->document, $this->sender, $this->recipient);
    })->throws(CustodyException::class, 'cannot receive documents');

    test('is rejected for a recipient who cannot acknowledge', function (string $role) {
        $this->custody->forward($this->document, $this->sender, custodyUser($role));
    })->with(['Management / Auditor'])
        ->throws(CustodyException::class, 'cannot receive documents');

    test('is rejected for a recipient with no role', function () {
        $this->custody->forward($this->document, $this->sender, User::factory()->create());
    })->throws(CustodyException::class, 'cannot receive documents');

    test('allows peer to peer forwarding', function () {
        $director = custodyUser('Director / Final Recipient');
        $otherDirector = custodyUser('Director / Final Recipient');
        $this->document->update(['current_custodian_id' => $director->id]);

        expect($this->custody->forward($this->document, $director, $otherDirector))
            ->toBeInstanceOf(DocumentMovement::class);
    });

    test('rejects remarks that are too long', function () {
        $this->custody->forward($this->document, $this->sender, $this->recipient, str_repeat('a', 1001));
    })->throws(CustodyException::class, '1000 characters');

    test('stores blank remarks as null', function () {
        $movement = $this->custody->forward($this->document, $this->sender, $this->recipient, '   ');

        expect($movement->remarks)->toBeNull();
    });

    test('the database rejects a second pending handover', function () {
        $this->custody->forward($this->document, $this->sender, $this->recipient);

        DocumentMovement::create([
            'pdts_document_id' => $this->document->id,
            'sender_id' => $this->sender->id,
            'recipient_id' => $this->recipient->id,
            'open_document_id' => $this->document->id,
        ]);
    })->throws(QueryException::class);
});

describe('acknowledge', function () {
    beforeEach(function () {
        $this->movement = $this->custody->forward($this->document, $this->sender, $this->recipient);
    });

    test('transfers custody to the recipient', function () {
        $movement = $this->custody->acknowledge($this->movement, $this->recipient);

        $document = $this->document->fresh();

        expect((int) $document->current_custodian_id)->toBe($this->recipient->id)
            ->and($document->statusEnum())->toBe(DocumentStatus::Received)
            ->and($movement->status)->toBe(MovementStatus::Acknowledged)
            ->and($movement->acknowledged_at)->not->toBeNull()
            ->and((int) $movement->acknowledged_by)->toBe($this->recipient->id)
            ->and($movement->open_document_id)->toBeNull();
    });

    test('is rejected for anyone but the recipient', function () {
        $this->custody->acknowledge($this->movement, custodyUser('Manager'));
    })->throws(CustodyException::class, 'Only the intended recipient');

    test('is rejected for the sender', function () {
        $this->custody->acknowledge($this->movement, $this->sender);
    })->throws(CustodyException::class, 'Only the intended recipient');

    test('is rejected for administrators who are not the recipient', function () {
        $this->custody->acknowledge($this->movement, custodyUser('System Administrator'));
    })->throws(CustodyException::class, 'Only the intended recipient');

    test('leaves custody unchanged when rejected', function () {
        try {
            $this->custody->acknowledge($this->movement, custodyUser('Manager'));
        } catch (CustodyException) {
        }

        expect((int) $this->document->fresh()->current_custodian_id)->toBe($this->sender->id);
    });

    test('is rejected if the recipient has lost the permission', function () {
        $this->recipient->syncRoles([]);

        $this->custody->acknowledge($this->movement, $this->recipient->fresh());
    })->throws(CustodyException::class, 'do not have permission');

    test('works only once', function () {
        $stale = DocumentMovement::find($this->movement->id);

        $this->custody->acknowledge($this->movement, $this->recipient);
        $this->custody->acknowledge($stale, $this->recipient);
    })->throws(CustodyException::class, 'no longer awaiting');

    test('is rejected after the handover is cancelled', function () {
        $stale = DocumentMovement::find($this->movement->id);

        $this->custody->cancel($this->movement, $this->sender, 'Sent in error');
        $this->custody->acknowledge($stale, $this->recipient);
    })->throws(CustodyException::class, 'no longer awaiting');
});

describe('decline', function () {
    beforeEach(function () {
        $this->movement = $this->custody->forward($this->document, $this->sender, $this->recipient);
    });

    test('keeps the sender as custodian and restores the status', function () {
        $movement = $this->custody->decline($this->movement, $this->recipient, 'Wrong person');

        $document = $this->document->fresh();

        expect((int) $document->current_custodian_id)->toBe($this->sender->id)
            ->and($document->statusEnum())->toBe(DocumentStatus::Registered)
            ->and($movement->status)->toBe(MovementStatus::Declined)
            ->and($movement->close_reason)->toBe('Wrong person')
            ->and((int) $movement->closed_by)->toBe($this->recipient->id)
            ->and($movement->closed_at)->not->toBeNull();
    });

    test('requires a reason', function () {
        $this->custody->decline($this->movement, $this->recipient, '   ');
    })->throws(CustodyException::class, 'give a reason');

    test('is rejected for anyone but the recipient', function () {
        $this->custody->decline($this->movement, $this->sender, 'Changed my mind');
    })->throws(CustodyException::class, 'Only the intended recipient');

    test('still works if the recipient has lost the acknowledge permission', function () {
        $this->recipient->syncRoles([]);

        expect($this->custody->decline($this->movement, $this->recipient->fresh(), 'Not my area')->status)
            ->toBe(MovementStatus::Declined);
    });

    test('lets the sender forward again afterwards', function () {
        $this->custody->decline($this->movement, $this->recipient, 'Wrong person');

        expect($this->custody->forward($this->document, $this->sender, custodyUser('Manager')))
            ->toBeInstanceOf(DocumentMovement::class);
    });
});

describe('cancel', function () {
    beforeEach(function () {
        $this->movement = $this->custody->forward($this->document, $this->sender, $this->recipient);
    });

    test('the sender can cancel and custody does not change', function () {
        $movement = $this->custody->cancel($this->movement, $this->sender, 'Sent in error');

        $document = $this->document->fresh();

        expect((int) $document->current_custodian_id)->toBe($this->sender->id)
            ->and($document->statusEnum())->toBe(DocumentStatus::Registered)
            ->and($movement->status)->toBe(MovementStatus::Cancelled)
            ->and($movement->close_reason)->toBe('Sent in error');
    });

    test('a user with documents.cancel can cancel', function () {
        $movement = $this->custody->cancel($this->movement, custodyUser('System Administrator'), 'Stuck handover');

        expect($movement->status)->toBe(MovementStatus::Cancelled);
    });

    test('anyone else cannot cancel', function (string $role) {
        $this->custody->cancel($this->movement, custodyUser($role), 'No');
    })->with(['Manager', 'Management / Auditor'])
        ->throws(CustodyException::class, 'Only the sender');

    test('the recipient cannot cancel', function () {
        $this->custody->cancel($this->movement, $this->recipient, 'No');
    })->throws(CustodyException::class, 'Only the sender');

    test('requires a reason', function () {
        $this->custody->cancel($this->movement, $this->sender, '');
    })->throws(CustodyException::class, 'give a reason');

    test('is rejected once acknowledged', function () {
        $stale = DocumentMovement::find($this->movement->id);

        $this->custody->acknowledge($this->movement, $this->recipient);
        $this->custody->cancel($stale, $this->sender, 'Too late');
    })->throws(CustodyException::class, 'no longer awaiting');

    test('restores Received when the document was held before', function () {
        $this->custody->acknowledge($this->movement, $this->recipient);

        $next = $this->custody->forward($this->document->fresh(), $this->recipient, custodyUser('Manager'));
        $this->custody->cancel($next, $this->recipient, 'Sent in error');

        $document = $this->document->fresh();

        expect($document->statusEnum())->toBe(DocumentStatus::Received)
            ->and((int) $document->current_custodian_id)->toBe($this->recipient->id);
    });
});

test('the full employee to director journey keeps one custodian and a complete history', function () {
    $supervisor = $this->recipient;
    $manager = custodyUser('Manager');
    $director = custodyUser('Director / Final Recipient');

    foreach ([[$this->sender, $supervisor], [$supervisor, $manager], [$manager, $director]] as [$from, $to]) {
        $movement = $this->custody->forward($this->document->fresh(), $from, $to);

        expect((int) $this->document->fresh()->current_custodian_id)->toBe($from->id);

        $this->custody->acknowledge($movement, $to);

        expect((int) $this->document->fresh()->current_custodian_id)->toBe($to->id);
    }

    $movements = $this->document->movements()->orderBy('id')->get();

    expect($movements)->toHaveCount(3)
        ->and($movements->pluck('recipient_id')->map(fn ($id) => (int) $id)->all())
        ->toBe([$supervisor->id, $manager->id, $director->id])
        ->and($movements->every(fn ($m) => $m->status === MovementStatus::Acknowledged))->toBeTrue()
        ->and($this->document->fresh()->statusEnum())->toBe(DocumentStatus::Received);
});

test('every action writes an audit record naming the user', function () {
    $first = $this->custody->forward($this->document, $this->sender, $this->recipient);
    $this->custody->acknowledge($first, $this->recipient);

    $second = $this->custody->forward($this->document->fresh(), $this->recipient, custodyUser('Manager'));
    $this->custody->decline($second, $second->recipient, 'Wrong person');

    $third = $this->custody->forward($this->document->fresh(), $this->recipient, custodyUser('Manager'));
    $this->custody->cancel($third, $this->recipient, 'Sent in error');

    $logs = $this->document->auditLogs()->orderBy('id')->get();

    expect($logs->pluck('action')->all())->toBe([
        'handover_forwarded',
        'handover_acknowledged',
        'handover_forwarded',
        'handover_declined',
        'handover_forwarded',
        'handover_cancelled',
    ])
        ->and((int) $logs[1]->actor_id)->toBe($this->recipient->id)
        ->and($logs[3]->details['reason'])->toBe('Wrong person')
        ->and($logs[5]->details['movement_id'])->toBe($third->id);
});

test('a rejected action writes no audit record', function () {
    try {
        $this->custody->forward($this->document, $this->recipient, $this->sender);
    } catch (CustodyException) {
    }

    expect($this->document->auditLogs()->count())->toBe(0);
});

describe('notifications', function () {
    test('each action notifies the right person', function () {
        Notification::fake();
        $admin = custodyUser('System Administrator');

        $first = $this->custody->forward($this->document, $this->sender, $this->recipient);
        Notification::assertSentTo($this->recipient, DocumentHandoverNotification::class, fn ($n) => $n->event === 'forwarded');

        $this->custody->acknowledge($first, $this->recipient);
        Notification::assertSentTo($this->sender, DocumentHandoverNotification::class, fn ($n) => $n->event === 'acknowledged');

        $manager = custodyUser('Manager');
        $second = $this->custody->forward($this->document->fresh(), $this->recipient, $manager);
        $this->custody->decline($second, $manager, 'Wrong person');
        Notification::assertSentTo($this->recipient, DocumentHandoverNotification::class, fn ($n) => $n->event === 'declined');

        $third = $this->custody->forward($this->document->fresh(), $this->recipient, $manager);
        $this->custody->cancel($third, $admin, 'Stuck');
        Notification::assertSentTo($manager, DocumentHandoverNotification::class, fn ($n) => $n->event === 'cancelled');
        Notification::assertSentTo($this->recipient, DocumentHandoverNotification::class, fn ($n) => $n->event === 'cancelled');
    });

    test('forwarding and acknowledgement are also emailed, other events are not', function () {
        $movement = $this->custody->forward($this->document, $this->sender, $this->recipient);

        $notification = $this->recipient->notifications()->first();

        expect($notification->data['title'])->toBe('Document awaiting your receipt')
            ->and($notification->data['url'])->toBe(route('movements.pending'))
            ->and($notification->data['message'])->toContain($this->document->reference_number);

        $forwarded = new DocumentHandoverNotification($movement, 'forwarded');

        expect($forwarded->via($this->recipient))->toBe(['database', 'mail'])
            ->and((new DocumentHandoverNotification($movement, 'declined'))->via($this->recipient))->toBe(['database']);

        $mail = $forwarded->toMail($this->recipient);

        expect($mail->subject)->toBe('PDTS | Document awaiting your receipt: '.$this->document->reference_number)
            ->and($mail->actionUrl)->toBe(route('movements.pending'));
    });

    test('a user who muted handovers gets none, but the handover still exists', function () {
        $this->recipient->update(['notify_categories' => ['handovers' => false]]);

        $movement = $this->custody->forward($this->document, $this->sender, $this->recipient);

        expect($this->recipient->notifications()->count())->toBe(0)
            ->and($movement->isPending())->toBeTrue();
    });

    test('a failed notification does not undo the custody change', function () {
        Notification::shouldReceive('send')->andThrow(new RuntimeException('Notification store is down'));

        $movement = $this->custody->forward($this->document, $this->sender, $this->recipient);

        expect($movement->isPending())->toBeTrue()
            ->and($this->document->fresh()->statusEnum())->toBe(DocumentStatus::AwaitingReceipt);
    });
});

describe('history access', function () {
    test('is limited to people involved and those with audit rights', function () {
        $movement = $this->custody->forward($this->document, $this->sender, $this->recipient);
        $this->custody->acknowledge($movement, $this->recipient);

        $document = $this->document->fresh();

        expect($this->custody->canViewHistory($document, $this->sender))->toBeTrue()
            ->and($this->custody->canViewHistory($document, $this->recipient))->toBeTrue()
            ->and($this->custody->canViewHistory($document, custodyUser('Management / Auditor')))->toBeTrue()
            ->and($this->custody->canViewHistory($document, custodyUser('System Administrator')))->toBeTrue()
            ->and($this->custody->canViewHistory($document, custodyUser('Supervisor')))->toBeFalse();
    });

    test('a pending recipient can already view it', function () {
        $this->custody->forward($this->document, $this->sender, $this->recipient);

        expect($this->custody->canViewHistory($this->document, $this->recipient))->toBeTrue();
    });
});

test('movements cannot be deleted', function () {
    $this->custody->forward($this->document, $this->sender, $this->recipient)->delete();
})->throws(LogicException::class);

test('a document registered through Registration can be forwarded and acknowledged', function () {
    Storage::fake('private');
    $type = PdtsDocumentType::firstOrCreate(['name' => 'Memorandum'], ['is_active' => true]);

    $document = app(RegisterPdtsDocument::class)->handle([
        'title' => 'Fleet report',
        'subject' => 'Quarterly fleet usage',
        'document_type_id' => $type->id,
        'originating_department' => 'Operations',
        'originating_office' => 'Lusaka Main Office',
    ], $this->sender, UploadedFile::fake()->create('fleet.pdf', 10, 'application/pdf'));

    $movement = $this->custody->forward($document, $this->sender, $this->recipient);
    $this->custody->acknowledge($movement, $this->recipient);

    expect($document->fresh()->current_custodian_id)->toBe($this->recipient->id)
        ->and($document->fresh()->status)->toBe('received')
        ->and($document->auditLogs()->pluck('action')->all())
        ->toBe(['registered', 'handover_forwarded', 'handover_acknowledged']);
});

test('statuses have plain-English labels for the tracking pages', function () {
    expect(array_map(fn (DocumentStatus $status) => $status->plainLabel(), DocumentStatus::cases()))->toBe([
        'Not sent',
        'Sent, not received yet',
        'Received',
    ]);
});
