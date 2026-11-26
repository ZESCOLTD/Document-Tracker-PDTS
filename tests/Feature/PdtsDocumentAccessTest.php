<?php

use App\Livewire\Movements\History;
use App\Livewire\Movements\Track;
use App\Livewire\Pdts\Documents\Archive;
use App\Livewire\Pdts\Documents\Index;
use App\Livewire\Pdts\Documents\Show;
use App\Models\PdtsDocument;
use App\Models\PdtsDocumentAttachment;
use App\Models\PdtsUserDelegation;
use App\Models\User;
use App\Services\Documents\CustodyService;
use App\Services\Documents\DocumentAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('private');
    foreach (['documents.view', 'documents.register', 'documents.forward', 'documents.acknowledge', 'documents.cancel', 'audit.view'] as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }
    $person = function (string $name, array $extra = []) {
        $user = User::factory()->create(['name' => $name]);
        $user->givePermissionTo(['documents.view', 'documents.register', 'documents.forward', 'documents.acknowledge', ...$extra]);

        return $user;
    };
    $this->author = $person('Ann Author');
    $this->boss = $person('Bob Boss');          // the officer it was prepared for, who signs
    $this->for = $person('Faith For');          // TO
    $this->copied = $person('Carl Copied');     // CC
    $this->receiver = $person('Rita Receiver'); // it is handed to her
    $this->delegate = $person('Dan Delegate');  // receives for Rita
    $this->outsider = $person('Olga Outsider');
    $this->auditor = $person('Audrey Auditor', ['audit.view']);
    $this->admin = $person('Adam Admin', ['documents.cancel']);

    PdtsUserDelegation::create(['principal_user_id' => $this->receiver->id, 'delegate_user_id' => $this->delegate->id, 'can_receive' => true,
        'can_prepare' => false, 'granted_by' => $this->receiver->id, 'starts_at' => now()->subDay()]);

    $this->document = PdtsDocument::factory()->create([
        'reference_number' => 'OMTS-2026-0700', 'title' => 'Budget request', 'origin' => 'internal',
        'registered_by' => $this->author->id, 'current_custodian_id' => $this->author->id, 'signatory_id' => $this->boss->id,
        'recipient_user_id' => $this->for->id, 'cc_user_ids' => [$this->copied->id],
    ]);
    Storage::disk('private')->put('pdts/documents/budget.pdf', "%PDF-1.4\n%%EOF");
    $this->file = PdtsDocumentAttachment::create(['pdts_document_id' => $this->document->id, 'uploaded_by' => $this->author->id,
        'storage_path' => 'pdts/documents/budget.pdf', 'original_filename' => 'budget.pdf', 'mime_type' => 'application/pdf', 'file_size' => 14, 'is_primary' => true]);
    app(CustodyService::class)->forward($this->document, $this->author, $this->receiver);
});

test('everyone involved in a registered document sees it: in the list, its page, its file and its movement history', function () {
    foreach (['author', 'boss', 'for', 'copied', 'receiver', 'delegate', 'auditor', 'admin'] as $who) {
        $user = $this->{$who};
        expect(app(DocumentAccess::class)->canView($this->document, $user))->toBeTrue("{$who} should see it");
        Livewire::actingAs($user)->test(Index::class)->assertSee('OMTS-2026-0700');
        Livewire::actingAs($user)->test(Show::class, ['document' => $this->document])->assertOk();
        $this->actingAs($user)->get(route('pdts.documents.attachments.download', [$this->document, $this->file]))->assertOk();
        Livewire::actingAs($user)->test(History::class, ['document' => $this->document])->assertOk();
    }
});

test('someone not involved does not see it anywhere: not listed, and its page, file and history are refused', function () {
    $user = $this->outsider;
    expect(app(DocumentAccess::class)->canView($this->document, $user))->toBeFalse();

    Livewire::actingAs($user)->test(Index::class)->assertDontSee('OMTS-2026-0700');
    Livewire::actingAs($user)->test(Show::class, ['document' => $this->document])->assertForbidden();
    $this->actingAs($user)->get(route('pdts.documents.show', $this->document))->assertForbidden()->assertSee('You are not involved in this document.');
    $this->actingAs($user)->get(route('pdts.documents.attachments.download', [$this->document, $this->file]))->assertForbidden();
    $this->actingAs($user)->get(route('movements.history', $this->document))->assertForbidden();
    $this->actingAs($user)->get(route('movements.scan', $this->document))->assertForbidden();

    // Filed: still only in the archive of those involved.
    $this->document->update(['status' => 'completed', 'completed_at' => now()]);
    Livewire::actingAs($user)->test(Archive::class)->assertDontSee('OMTS-2026-0700');
    Livewire::actingAs($this->for)->test(Archive::class)->assertSee('OMTS-2026-0700');
});

test('a draft is seen only by who prepares it and the officer who signs it; TO and CC see it once registered', function () {
    $draft = PdtsDocument::factory()->create([
        'reference_number' => 'OMTS-2026-0701', 'status' => 'draft', 'origin' => 'internal',
        'registered_by' => $this->author->id, 'current_custodian_id' => $this->author->id, 'signatory_id' => $this->boss->id,
        'recipient_user_id' => $this->for->id, 'cc_user_ids' => [$this->copied->id],
    ]);

    foreach (['author' => true, 'boss' => true, 'for' => false, 'copied' => false, 'outsider' => false, 'auditor' => true] as $who => $sees) {
        expect(app(DocumentAccess::class)->canView($draft, $this->{$who}))->toBe($sees, $who);
        $list = Livewire::actingAs($this->{$who})->test(Index::class);
        $sees ? $list->assertSee('OMTS-2026-0701') : $list->assertDontSee('OMTS-2026-0701');
    }

    $draft->update(['status' => 'registered']);
    expect(app(DocumentAccess::class)->canView($draft->fresh(), $this->for))->toBeTrue()
        ->and(app(DocumentAccess::class)->canView($draft->fresh(), $this->copied))->toBeTrue();
});

test('Track My Documents follows what you registered and what was prepared for you to sign', function () {
    Livewire::actingAs($this->author)->test(Track::class)->assertSee('OMTS-2026-0700');
    Livewire::actingAs($this->boss)->test(Track::class)->assertSee('OMTS-2026-0700');
    Livewire::actingAs($this->outsider)->test(Track::class)->assertDontSee('OMTS-2026-0700');
});
