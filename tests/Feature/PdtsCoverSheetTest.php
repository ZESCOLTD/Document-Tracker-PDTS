<?php

use App\Livewire\Pdts\Documents\Show;
use App\Models\PdtsDocument;
use App\Models\PdtsDocumentType;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;


beforeEach(function () {
    Permission::firstOrCreate(['name' => 'documents.view', 'guard_name' => 'web']);

    $this->registrar = User::factory()->create(['man_no' => 'CVR-0001', 'name' => 'Registry Clerk']);
    $this->registrar->givePermissionTo('documents.view');

    $type = PdtsDocumentType::create(['name' => 'Letter', 'is_active' => true]);

    $this->document = PdtsDocument::create([
        'reference_number' => 'OMTS-2026-0200',
        'title' => 'Tender clarification from supplier',
        'subject' => 'Tender clarification',
        'document_type_id' => $type->id,
        'originating_directorate' => 'Procurement',
        'originating_office' => 'Registry',
        'registered_by' => $this->registrar->id,
        'current_custodian_id' => $this->registrar->id,
        'status' => 'registered',
        'registered_at' => now(),
    ]);
});

test('the registrar prints an A4 cover sheet with the QR code and details', function () {
    $this->actingAs($this->registrar)
        ->get(route('pdts.documents.cover-sheet', $this->document))
        ->assertOk()
        ->assertSee('PDTS DOCUMENT COVER SHEET')
        ->assertSee('OMTS-2026-0200')
        ->assertSee('Tender clarification from supplier')
        ->assertSee('Registry Clerk')
        ->assertSee('data:image/png;base64,', false)
        ->assertSee('@page { size: A4', false)
        ->assertSee('Printed');

    $log = $this->document->auditLogs()->where('action', 'cover_sheet_printed')->sole();
    expect($log->details)->toBe(['confidential' => false, 'reprint' => false]);
});

test('the confidential version shows only the QR code and reference', function () {
    $this->actingAs($this->registrar)
        ->get(route('pdts.documents.cover-sheet', [$this->document, 'confidential' => 1]))
        ->assertOk()
        ->assertSee('OMTS-2026-0200')
        ->assertSee('CONFIDENTIAL')
        ->assertDontSee('Tender clarification from supplier')
        ->assertDontSee('Registry Clerk');
});

test('printing again is recorded as a reprint', function () {
    $this->actingAs($this->registrar);
    $this->get(route('pdts.documents.cover-sheet', $this->document))->assertOk();
    $this->get(route('pdts.documents.cover-sheet', $this->document))->assertOk()->assertSee('Reprinted');

    expect($this->document->auditLogs()->where('action', 'cover_sheet_printed')->pluck('details')->pluck('reprint')->all())
        ->toBe([false, true]);
});

test('the current holder can reprint, other people cannot print', function () {
    $holder = User::factory()->create(['man_no' => 'CVR-0002']);
    $holder->givePermissionTo('documents.view');
    $this->document->update(['current_custodian_id' => $holder->id]);

    $this->actingAs($holder)->get(route('pdts.documents.cover-sheet', $this->document))->assertOk();

    $other = User::factory()->create(['man_no' => 'CVR-0003']);
    $other->givePermissionTo('documents.view');
    $this->actingAs($other)->get(route('pdts.documents.cover-sheet', $this->document))->assertForbidden();
});

test('a draft has no cover sheet until it is registered', function () {
    $this->document->update(['status' => 'draft']);

    $this->actingAs($this->registrar)->get(route('pdts.documents.cover-sheet', $this->document))->assertNotFound();
});

test('the document page offers print, then reprint, to the registrar', function () {
    Livewire::actingAs($this->registrar)->test(Show::class, ['document' => $this->document])
        ->assertSee('Print cover sheet')
        ->assertSee('Confidential version');

    $this->actingAs($this->registrar)->get(route('pdts.documents.cover-sheet', $this->document));

    Livewire::actingAs($this->registrar)->test(Show::class, ['document' => $this->document->fresh()])
        ->assertSee('Reprint cover sheet');

    $other = User::factory()->create(['man_no' => 'CVR-0004']);
    $other->givePermissionTo('documents.view');
    Livewire::actingAs($other)->test(Show::class, ['document' => $this->document->fresh()])
        ->assertDontSee('Print cover sheet');
});
