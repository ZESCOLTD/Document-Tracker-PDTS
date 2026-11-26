<?php

use App\Livewire\Pdts\Documents\InternalDocument;
use App\Models\PdtsDocumentType;
use App\Models\User;
use App\Services\Pdts\Templates\TemplateDesigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/../Support/docx.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('private');
    Permission::firstOrCreate(['name' => 'documents.register', 'guard_name' => 'web']);
    $this->author = User::factory()->create(['man_no' => 'CRD-01']);
    $this->author->givePermissionTo('documents.register');
    $this->type = PdtsDocumentType::create(['name' => 'Request', 'is_active' => true]);
});

test('authors choose a template from a dropdown grouped by type; the chosen one shows its description, type and how it is signed', function () {
    $designer = app(TemplateDesigner::class);
    $signed = $designer->save(null, ['name' => 'Leave Request', 'description' => 'For staff applying for leave.', 'document_type_id' => $this->type->id], TemplateDesigner::starter(), null);
    $notice = TemplateDesigner::starter();
    array_pop($notice['items']); // no signature block
    $unsigned = $designer->save(null, ['name' => 'Staff Notice', 'description' => 'Notices to all staff.', 'document_type_id' => null], $notice, null);
    $inactive = $designer->save(null, ['name' => 'Old Form', 'description' => null, 'document_type_id' => null], TemplateDesigner::starter(), null);
    $inactive->update(['is_active' => false]);

    $form = Livewire::actingAs($this->author)->test(InternalDocument::class)
        ->assertSeeHtml('data-test="template-picker"')
        ->assertDontSeeHtml('<optgroup')                               // a plain list: the user does not see template types
        ->assertSee('Leave Request')->assertSee('Staff Notice')->assertDontSee('Old Form')
        // Nothing chosen yet: no details, and no document type to pick.
        ->assertDontSeeHtml('data-test="template-info"')->assertDontSeeHtml('id="document-type"');

    // A template with a type: the type comes from it, nothing to pick.
    $form->set('template_id', (string) $signed->id)
        ->assertSeeHtml('data-test="template-info"')->assertSee('For staff applying for leave.')->assertSee('Signed in PDTS')
        ->assertDontSeeHtml('id="document-type"')
        ->assertSet('document_type_id', (string) $this->type->id);

    // A template without a type: the author picks one.
    $form->set('template_id', (string) $unsigned->id)
        ->assertSee('Notices to all staff.')->assertSee('Not signed: issued in PDTS')
        ->assertSeeHtml('id="document-type"');
});
