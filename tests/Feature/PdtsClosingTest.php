<?php

use App\Livewire\Pdts\Documents\Archive;
use App\Livewire\Pdts\Documents\Show;
use App\Livewire\Pdts\Organization\DocumentTypes;
use App\Models\PdtsDocument;
use App\Models\PdtsDocumentType;
use App\Models\User;
use App\Services\Documents\CustodyService;
use App\Services\Pdts\Closing\DocumentClosure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach (['documents.view', 'documents.forward', 'documents.acknowledge', 'documents.cancel', 'document_types.manage'] as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $this->holder = User::factory()->create(['man_no' => 'CLS-01', 'name' => 'Mary Banda']);
    $this->holder->givePermissionTo(['documents.view', 'documents.forward', 'documents.acknowledge']);
    $this->director = User::factory()->create(['man_no' => 'CLS-02', 'name' => 'Grace Mwila']);
    $this->admin = User::factory()->create(['man_no' => 'CLS-03', 'name' => 'System Admin']);
    $this->admin->givePermissionTo(['documents.view', 'documents.cancel', 'document_types.manage']);

    $this->type = PdtsDocumentType::create(['name' => 'Memorandum', 'is_active' => true, 'retention_years' => 7]);
    $this->actingAs($this->holder);
});

function closingDocument(array $attributes = []): PdtsDocument
{
    static $n = 0;
    $n++;

    return PdtsDocument::create(array_merge([
        'reference_number' => 'OMTS-2026-09'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
        'title' => 'Memo '.$n, 'subject' => 'Memo '.$n, 'document_type_id' => test()->type->id,
        'originating_office' => 'ICT Office', 'registered_by' => test()->holder->id, 'current_custodian_id' => test()->holder->id,
        'status' => 'received', 'registered_at' => now(), 'origin' => 'internal',
    ], $attributes));
}

test('a received document is completed and filed, with where it is kept and until when', function () {
    $document = closingDocument(['recipient_user_id' => $this->director->id]);

    app(CustodyService::class)->complete($document, $this->holder, 'filed', 'Filed', 'ICT/ADM/12');

    $document->refresh();
    expect($document->status)->toBe('completed')
        ->and($document->completed_at)->not->toBeNull()
        ->and($document->completed_by)->toBe($this->holder->id)
        ->and($document->file_reference)->toBe('ICT/ADM/12')
        ->and($document->retainUntil()->year)->toBe(now()->addYears(7)->year);

    Livewire::test(Show::class, ['document' => $document])->assertSee('filed at')->assertSee('ICT/ADM/12')->assertSee('kept until');
});

test('the archive lists completed documents with where they are filed, those due for review, and cancelled ones', function () {
    $recent = closingDocument(['status' => 'completed', 'completed_at' => now()->subYear(), 'file_reference' => 'Shelf A']);
    $old = closingDocument(['status' => 'completed', 'completed_at' => now()->subYears(8), 'file_reference' => 'Basement 3']);
    $cancelled = closingDocument(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $this->admin->id, 'cancel_reason' => 'Issued in error']);

    Livewire::test(Archive::class)
        ->assertSee($recent->reference_number)->assertSee('Shelf A')->assertSee($old->reference_number)
        ->set('view', 'review')->assertSee($old->reference_number)->assertDontSee($recent->reference_number)
        ->set('view', 'cancelled')->assertSee($cancelled->reference_number)->assertSee('Issued in error')
        ->set('view', 'completed')->set('search', 'Basement')->assertSee($old->reference_number)->assertDontSee($recent->reference_number);
});

test('an administrator can cancel a document issued in error; it can then no longer be sent', function () {
    $document = closingDocument(['status' => 'registered']);

    expect(app(DocumentClosure::class)->cancelBlocker($document, $this->holder))->toContain('Only an administrator');
    Livewire::actingAs($this->holder)->test(Show::class, ['document' => $document])->assertDontSeeHtml('data-test="cancel-document"');

    $page = Livewire::actingAs($this->admin)->test(Show::class, ['document' => $document])->assertSeeHtml('data-test="cancel-document"');
    $page->call('cancelDocument')->assertHasErrors('cancelReason');
    $page->set('cancelReason', 'Issued in error, replaced by OMTS-2026-0100')->call('cancelDocument')->assertHasNoErrors();

    $document->refresh();
    expect($document->status)->toBe('cancelled')
        ->and($document->cancelled_by)->toBe($this->admin->id)
        ->and($document->auditLogs->pluck('action'))->toContain('document_cancelled')
        ->and(app(CustodyService::class)->forwardBlockedReason($document, $this->holder))->not->toBeNull();

    Livewire::actingAs($this->admin)->test(Show::class, ['document' => $document])->assertSee('Cancelled')->assertSee('Issued in error');
    $this->actingAs($this->admin)->get(route('dashboard'))->assertOk();
});

test('each document type can have a retention period', function () {
    Livewire::actingAs($this->admin)->test(DocumentTypes::class)
        ->call('edit', $this->type->id)
        ->set('retentionYears', '10')
        ->call('save')->assertHasNoErrors();

    expect($this->type->fresh()->retention_years)->toBe(10);
});

test('the archive leaves out the filed and keep-until columns when no document on the page has them', function () {
    $untyped = PdtsDocumentType::create(['name' => 'Letter', 'is_active' => true, 'retention_years' => null]);
    closingDocument(['status' => 'completed', 'completed_at' => now(), 'document_type_id' => $untyped->id]);

    Livewire::test(Archive::class)->assertDontSee('Filed at')->assertDontSee('Keep until')->assertDontSee('Not set');

    closingDocument(['status' => 'completed', 'completed_at' => now(), 'file_reference' => 'Shelf B']);
    Livewire::test(Archive::class)->assertSee('Filed at')->assertSee('Shelf B')->assertSee('Keep until');
});
