<?php

use App\Enums\DocumentStatus;
use App\Enums\MovementStatus;
use App\Livewire\Movements\Custody;
use App\Livewire\Movements\ForwardDocument;
use App\Livewire\Movements\History;
use App\Livewire\Movements\Pending;
use App\Models\PdtsDocument;
use App\Models\User;
use App\Services\Documents\CustodyService;
use Database\Seeders\PdtsAccessSeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('private');
    $this->seed(PdtsAccessSeeder::class);

    $this->custody = app(CustodyService::class);
    $this->sender = screenUser('Originating Employee', ['name' => 'Sam Sender']);
    $this->recipient = screenUser('Supervisor', ['name' => 'Rita Recipient', 'man_no' => 'M1001', 'job_title' => 'Supervisor']);
    $this->document = PdtsDocument::factory()->create([
        'current_custodian_id' => $this->sender->id,
        'reference_number' => 'PDTS-2026-0001',
        'title' => 'Budget request',
    ]);
});

function screenUser(string $role, array $attributes = []): User
{
    return User::factory()->create($attributes)->assignRole($role);
}

/**
 * Stores a scanned copy the way RegisterPdtsDocument does.
 */
function addScan(PdtsDocument $document): void
{
    Storage::disk('private')->put($path = 'pdts/documents/scan-'.$document->id.'.pdf', 'scan contents');

    $document->attachments()->create([
        'uploaded_by' => $document->registered_by,
        'storage_path' => $path,
        'original_filename' => 'scan.pdf',
        'mime_type' => 'application/pdf',
        'file_size' => 13,
        'is_primary' => true,
    ]);
}

describe('pages', function () {
    test('load for a user with access', function (string $route) {
        $this->actingAs($this->sender)->get(route($route))->assertOk();
    })->with(['movements.pending', 'movements.custody', 'movements.track']);

    test('are refused without the permission', function (string $route) {
        $this->actingAs(screenUser('Management / Auditor'))->get(route($route))->assertForbidden();
    })->with(['movements.pending', 'movements.custody', 'movements.track']);

    test('redirect guests to login', function (string $route) {
        $this->get(route($route))->assertRedirect(route('login'));
    })->with(['movements.pending', 'movements.custody', 'movements.track']);

    test('the sidebar links to them, and the Sent page is gone', function () {
        $this->actingAs($this->sender)->get(route('dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['Dashboard', 'Document Tracking', 'Pending Acknowledgements', 'My Custody', 'Track My Documents', 'Email Notifications'], false)
            ->assertDontSee('Sent, Awaiting Receipt');

        $this->actingAs($this->sender)->get('/movements/sent')->assertNotFound();
    });

    test('the sidebar hides them from users without access', function () {
        $this->actingAs(screenUser('Management / Auditor'))->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(route('movements.pending'));
    });
});

describe('forward form', function () {
    test('finds eligible users and forwards to the one chosen', function () {
        Livewire::actingAs($this->sender)
            ->test(ForwardDocument::class, ['document' => $this->document])
            ->call('openForm')
            ->set('search', 'rita')
            ->assertSee('Rita Recipient')
            ->call('selectRecipient', $this->recipient->id)
            ->set('remarks', 'For your review')
            ->call('forward')
            ->assertHasNoErrors()
            ->assertSee('You remain the custodian')
            ->assertDispatched('handover-changed');

        $document = $this->document->fresh();

        expect($document->statusEnum())->toBe(DocumentStatus::AwaitingReceipt)
            ->and((int) $document->current_custodian_id)->toBe($this->sender->id)
            ->and($document->pendingMovement->remarks)->toBe('For your review');
    });

    test('search leaves out people who cannot receive documents', function () {
        screenUser('Management / Auditor', ['name' => 'Rita Auditor']);
        screenUser('Supervisor', ['name' => 'Rita Banned', 'is_banned' => true]);
        User::factory()->create(['name' => 'Rita Noroles']);

        Livewire::actingAs($this->sender)
            ->test(ForwardDocument::class, ['document' => $this->document])
            ->call('openForm')
            ->set('search', 'rita')
            ->assertSee('Rita Recipient')
            ->assertDontSee('Rita Auditor')
            ->assertDontSee('Rita Banned')
            ->assertDontSee('Rita Noroles');
    });

    test('search matches man number and job title', function (string $term) {
        Livewire::actingAs($this->sender)
            ->test(ForwardDocument::class, ['document' => $this->document])
            ->call('openForm')
            ->set('search', $term)
            ->assertSee('Rita Recipient');
    })->with(['m1001', 'SUPERVISOR']);

    test('requires a recipient', function () {
        Livewire::actingAs($this->sender)
            ->test(ForwardDocument::class, ['document' => $this->document])
            ->call('openForm')
            ->call('forward')
            ->assertHasErrors(['recipientId' => 'required']);
    });

    test('a user cannot forward by sending an ineligible recipient id', function () {
        $auditor = screenUser('Management / Auditor');

        Livewire::actingAs($this->sender)
            ->test(ForwardDocument::class, ['document' => $this->document])
            ->call('openForm')
            ->call('selectRecipient', $auditor->id)
            ->call('forward')
            ->assertSee('cannot receive documents');

        expect($this->document->fresh()->statusEnum())->toBe(DocumentStatus::Registered);
    });

    test('a non-custodian sees no button and cannot forward', function () {
        Livewire::actingAs($this->recipient)
            ->test(ForwardDocument::class, ['document' => $this->document])
            ->assertDontSeeHtml('wire:click="openForm"')
            ->call('openForm')
            ->call('selectRecipient', screenUser('Manager')->id)
            ->call('forward')
            ->assertSee('Only the current custodian');

        expect($this->document->fresh()->statusEnum())->toBe(DocumentStatus::Registered);
    });
});

describe('pending acknowledgements', function () {
    beforeEach(function () {
        $this->movement = $this->custody->forward($this->document, $this->sender, $this->recipient, 'Urgent');
    });

    test('lists only documents waiting for the signed-in user', function () {
        Livewire::actingAs($this->recipient)->test(Pending::class)
            ->assertSee('PDTS-2026-0001')
            ->assertSee('Budget request')
            ->assertSee('Sam Sender')
            ->assertSee('Urgent');

        Livewire::actingAs(screenUser('Manager'))->test(Pending::class)
            ->assertDontSee('PDTS-2026-0001');
    });

    test('acknowledging needs the confirmation and transfers custody', function () {
        Livewire::actingAs($this->recipient)->test(Pending::class)
            ->call('openAcknowledge', $this->movement->id)
            ->assertSee('I confirm I physically have this document.')
            ->call('acknowledge')
            ->assertSee('You are now the custodian');

        expect((int) $this->document->fresh()->current_custodian_id)->toBe($this->recipient->id);
    });

    test('closing the confirmation changes nothing', function () {
        Livewire::actingAs($this->recipient)->test(Pending::class)
            ->call('openAcknowledge', $this->movement->id)
            ->call('closeDialog')
            ->assertDontSee('I confirm I physically have this document.');

        expect($this->movement->fresh()->status)->toBe(MovementStatus::AwaitingReceipt);
    });

    test('someone else cannot act on it, even by sending its id', function () {
        Livewire::actingAs(screenUser('Manager'))->test(Pending::class)
            ->call('openAcknowledge', $this->movement->id)
            ->assertDontSee('I confirm I physically have this document.')
            ->call('acknowledge')
            ->assertSee('Only the intended recipient');

        expect((int) $this->document->fresh()->current_custodian_id)->toBe($this->sender->id);
    });

    test('declining requires a reason', function () {
        Livewire::actingAs($this->recipient)->test(Pending::class)
            ->call('openDecline', $this->movement->id)
            ->call('decline')
            ->assertHasErrors(['reason' => 'required']);

        expect($this->movement->fresh()->status)->toBe(MovementStatus::AwaitingReceipt);
    });

    test('declining with a reason returns it to the sender', function () {
        Livewire::actingAs($this->recipient)->test(Pending::class)
            ->call('openDecline', $this->movement->id)
            ->set('reason', 'Wrong department')
            ->call('decline')
            ->assertSee('You declined PDTS-2026-0001');

        expect($this->movement->fresh()->status)->toBe(MovementStatus::Declined)
            ->and($this->document->fresh()->statusEnum())->toBe(DocumentStatus::Registered);
    });

    test('shows a scan link only when there is a scan', function () {
        Livewire::actingAs($this->recipient)->test(Pending::class)
            ->assertSee('Not attached')
            ->assertDontSeeHtml('href="'.route('movements.scan', $this->document).'"');

        addScan($this->document);

        Livewire::actingAs($this->recipient)->test(Pending::class)
            ->assertDontSee('Not attached')
            ->assertSeeHtml('href="'.route('movements.scan', $this->document).'"');
    });
});

describe('my custody', function () {
    test('lists open documents the user holds', function () {
        Livewire::actingAs($this->sender)->test(Custody::class)
            ->assertSee('PDTS-2026-0001');

        Livewire::actingAs($this->recipient)->test(Custody::class)
            ->assertDontSee('PDTS-2026-0001');
    });

    test('shows who a pending document is waiting on', function () {
        $this->custody->forward($this->document, $this->sender, $this->recipient);

        Livewire::actingAs($this->sender)->test(Custody::class)
            ->assertSee('Sent, not received yet')
            ->assertSee('Forwarded to Rita Recipient. Waiting for them to acknowledge receipt.')
            ->assertSee('Cancel')
            ->assertDontSee('Not available');
    });

    test('explains each status in plain words', function () {
        $declined = PdtsDocument::factory()->create(['current_custodian_id' => $this->sender->id]);
        $this->custody->decline($this->custody->forward($declined, $this->sender, $this->recipient), $this->recipient, 'Wrong department');

        $cancelled = PdtsDocument::factory()->create(['current_custodian_id' => $this->sender->id]);
        $this->custody->cancel($this->custody->forward($cancelled, $this->sender, $this->recipient), $this->sender, 'Sent in error');

        Livewire::actingAs($this->sender)->test(Custody::class)
            ->assertSee('Not yet forwarded.')
            ->assertSee('Rita Recipient declined it and returned it to you. Reason: Wrong department.')
            ->assertSee('The handover to Rita Recipient was cancelled. Reason: Sent in error.');

        $this->custody->acknowledge($this->custody->forward($this->document, $this->sender, $this->recipient), $this->recipient);

        Livewire::actingAs($this->recipient)->test(Custody::class)
            ->assertSee('Received from Sam Sender on');
    });

    test('searches by reference or subject when Search is pressed', function () {
        PdtsDocument::factory()->create(['current_custodian_id' => $this->sender->id, 'reference_number' => 'PDTS-2026-0099', 'title' => 'Fleet report']);

        Livewire::actingAs($this->sender)->test(Custody::class)
            ->set('searchInput', 'budget')
            ->assertSee('PDTS-2026-0099')
            ->call('applySearch')
            ->assertSee('PDTS-2026-0001')
            ->assertDontSee('PDTS-2026-0099')
            ->set('searchInput', '0099')
            ->call('applySearch')
            ->assertSee('Fleet report')
            ->assertDontSee('Budget request')
            ->set('searchInput', 'nothing like this')
            ->call('applySearch')
            ->assertSee('No documents match')
            ->call('clearSearch')
            ->assertSee('PDTS-2026-0001')
            ->assertSee('PDTS-2026-0099');
    });

    test('search only covers the user\'s own documents', function () {
        PdtsDocument::factory()->create(['current_custodian_id' => $this->recipient->id, 'reference_number' => 'PDTS-2026-0500', 'title' => 'Budget elsewhere']);

        Livewire::actingAs($this->sender)->test(Custody::class)
            ->set('searchInput', 'budget')
            ->call('applySearch')
            ->assertSee('PDTS-2026-0001')
            ->assertDontSee('PDTS-2026-0500');
    });

    test('has no tracking column', function () {
        Livewire::actingAs($this->sender)->test(Custody::class)
            ->assertDontSee('History')
            ->assertDontSeeHtml('href="'.route('movements.history', $this->document).'"');
    });
});

describe('cancelling from my custody', function () {
    beforeEach(function () {
        $this->movement = $this->custody->forward($this->document, $this->sender, $this->recipient);
    });

    test('a waiting row shows Cancel instead of Forward', function () {
        Livewire::actingAs($this->sender)->test(Custody::class)
            ->assertSeeHtml('wire:click="openCancel('.$this->movement->id.')"')
            ->assertDontSeeHtml('wire:click="openForm"');
    });

    test('the sender can cancel with a reason, and can then forward again', function () {
        Livewire::actingAs($this->sender)->test(Custody::class)
            ->call('openCancel', $this->movement->id)
            ->assertSee('Cancel handover')
            ->set('reason', 'Sent in error')
            ->call('cancel')
            ->assertHasNoErrors()
            ->assertSee('was cancelled. You can forward it again.')
            ->assertSee('The handover to Rita Recipient was cancelled. Reason: Sent in error.');

        expect($this->movement->fresh()->status)->toBe(MovementStatus::Cancelled)
            ->and($this->document->fresh()->statusEnum())->toBe(DocumentStatus::Registered)
            ->and((int) $this->document->fresh()->current_custodian_id)->toBe($this->sender->id);
    });

    test('cancelling requires a reason', function () {
        Livewire::actingAs($this->sender)->test(Custody::class)
            ->call('openCancel', $this->movement->id)
            ->call('cancel')
            ->assertHasErrors(['reason' => 'required']);

        expect($this->movement->fresh()->isPending())->toBeTrue();
    });

    test('someone else cannot cancel it, even by sending its id', function () {
        Livewire::actingAs(screenUser('Manager'))->test(Custody::class)
            ->call('openCancel', $this->movement->id)
            ->assertDontSee('Cancel handover')
            ->set('reason', 'No')
            ->call('cancel')
            ->assertSee('Only the sender');

        expect($this->movement->fresh()->isPending())->toBeTrue();
    });
});

describe('movement history', function () {
    test('shows every event, including declined handovers and reasons', function () {
        $declined = $this->custody->forward($this->document, $this->sender, $this->recipient, 'First try');
        $this->custody->decline($declined, $this->recipient, 'Wrong person');

        $accepted = $this->custody->forward($this->document->fresh(), $this->sender, $this->recipient);
        $this->custody->acknowledge($accepted, $this->recipient);

        Livewire::actingAs($this->sender)
            ->test(History::class, ['document' => $this->document])
            ->assertSee('Sam Sender')
            ->assertSee('Rita Recipient')
            ->assertSee('First try')
            ->assertSee('Declined')
            ->assertSee('Wrong person')
            ->assertSee('Acknowledged');
    });

    test('still names a person whose account was removed', function () {
        $movement = $this->custody->forward($this->document, $this->sender, $this->recipient);
        $this->custody->acknowledge($movement, $this->recipient);
        $this->recipient->delete();

        $this->actingAs($this->sender)
            ->get(route('movements.history', $this->document))
            ->assertOk()
            ->assertSee('Rita Recipient');
    });

    test('loads for someone involved', function () {
        $this->actingAs($this->sender)
            ->get(route('movements.history', $this->document))
            ->assertOk();
    });

    test('loads for an auditor', function () {
        $this->actingAs(screenUser('Management / Auditor'))
            ->get(route('movements.history', $this->document))
            ->assertOk();
    });

    test('is refused for anyone else', function () {
        $this->actingAs(screenUser('Supervisor'))
            ->get(route('movements.history', $this->document))
            ->assertForbidden();
    });
});

describe('scanned copy', function () {

    test('a pending recipient can open it', function () {
        addScan($this->document);
        $this->custody->forward($this->document, $this->sender, $this->recipient);

        $this->actingAs($this->recipient)
            ->get(route('movements.scan', $this->document))
            ->assertOk();
    });

    test('it is refused for anyone not involved', function () {
        addScan($this->document);

        $this->actingAs($this->recipient)
            ->get(route('movements.scan', $this->document))
            ->assertForbidden();
    });

    test('it returns not found when there is no scan', function () {
        $this->actingAs($this->sender)
            ->get(route('movements.scan', $this->document))
            ->assertNotFound();
    });

    test('guests are sent to login', function () {
        addScan($this->document);

        $this->get(route('movements.scan', $this->document))
            ->assertRedirect(route('login'));
    });
});
