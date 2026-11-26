<?php

use App\Livewire\Pdts\Documents\InternalDocument;
use App\Livewire\Pdts\Documents\Show;
use App\Livewire\Pdts\Organization\DocumentTemplates;
use App\Livewire\Pdts\Organization\TemplateReview;
use App\Models\PdtsDepartment;
use App\Models\PdtsDirectorate;
use App\Models\PdtsDocument;
use App\Models\PdtsDocumentTemplate;
use App\Models\PdtsDocumentType;
use App\Models\PdtsOffice;
use App\Models\User;
use App\Services\Pdts\InternalDocuments;
use App\Services\Pdts\Reports\DocumentOrigins;
use App\Services\Pdts\Signatures\PdfConverter;
use App\Services\Pdts\Templates\TemplateSetup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/../Support/docx.php';

uses(RefreshDatabase::class);

/** Stands in for LibreOffice. */
class UnsignedTestPdfConverter extends PdfConverter
{
    public function engine(): ?string
    {
        return 'fake';
    }

    public function convert(string $docx): string
    {
        return "%PDF-1.4\n% ".hash('sha256', $docx)."\n%%EOF\n";
    }
}

beforeEach(function () {
    Storage::fake('private');
    Notification::fake();
    app()->instance(PdfConverter::class, new UnsignedTestPdfConverter);

    foreach (['documents.register', 'documents.view', 'documents.forward', 'documents.acknowledge', 'document_templates.manage', 'reports.view'] as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }
    $this->author = User::factory()->create(['man_no' => 'UNS-01', 'name' => 'Teddy Sikazwe']);
    $this->author->givePermissionTo(['documents.register', 'documents.view', 'documents.forward', 'documents.acknowledge']);
    $this->admin = User::factory()->create(['man_no' => 'UNS-02', 'name' => 'Template Admin']);
    $this->admin->givePermissionTo(['document_templates.manage', 'documents.view']);

    $this->type = PdtsDocumentType::create(['name' => 'Notice', 'is_active' => true]);
    $this->directorate = PdtsDirectorate::create(['name' => 'Corporate Services', 'is_active' => true]);
    $this->department = PdtsDepartment::create(['name' => 'ICT', 'directorate_id' => $this->directorate->id, 'is_active' => true]);
    $this->office = PdtsOffice::create(['name' => 'ICT Office', 'department_id' => $this->department->id, 'is_active' => true]);

    // A notice: a heading and a subject line, and nowhere to sign.
    $file = tempnam(sys_get_temp_dir(), 'notice').'.docx';
    file_put_contents($file, pdtsTestDocx('<w:r><w:t>STAFF NOTICE</w:t></w:r></w:p><w:p><w:r><w:t xml:space="preserve">SUBJECT: ____________</w:t></w:r>', '<w:r><w:t>Zambia</w:t></w:r>'));
    $setup = app(TemplateSetup::class);
    $stored = $setup->storeOriginal($file, 'docx');
    @unlink($file);
    $this->notice = $setup->save(PdtsDocumentTemplate::create([
        'name' => 'Staff Notice', 'kind' => 'docx', 'document_type_id' => $this->type->id,
        'original_path' => $stored['path'], 'original_filename' => 'notice.docx',
    ]), ['page' => 'first', 'x' => 10, 'y' => 10, 'size' => 18])->fresh();
});

function noticeDraft(User $author): PdtsDocument
{
    Livewire::actingAs($author)->test(InternalDocument::class)
        ->set('template_id', (string) test()->notice->id)
        ->set('subject', 'Office closed on Friday')
        ->set('directorate_id', (string) test()->directorate->id)
        ->set('department_id', (string) test()->department->id)
        ->set('office_id', (string) test()->office->id)
        ->call('save')->assertHasNoErrors();

    return PdtsDocument::query()->latest('id')->first();
}

test('a Word template without a signature spot is not offered to authors until it is marked as signed or not signed', function () {
    expect($this->notice->supportsElectronicSignature())->toBeFalse()
        ->and($this->notice->is_active)->toBeFalse()
        ->and($this->notice->signingBlocker())->toContain('No signature spot');

    // It cannot be switched on from the list either.
    Livewire::actingAs($this->admin)->test(DocumentTemplates::class)->call('toggleActive', $this->notice->id);
    expect($this->notice->fresh()->is_active)->toBeFalse();

    // Not on the New Internal Document form.
    Livewire::actingAs($this->author)->test(InternalDocument::class)->assertDontSee('Staff Notice');

    // The review page offers the two choices; "not signed" makes it available.
    Livewire::actingAs($this->admin)->test(TemplateReview::class, ['template' => $this->notice])
        ->assertSeeHtml('data-test="signing-choice"')->assertSee('No signature spot found')
        ->call('setUnsigned', true)
        ->assertSeeHtml('data-test="unsigned-note"')->assertDontSeeHtml('data-test="signing-choice"');

    expect($this->notice->fresh()->is_active)->toBeTrue()->and($this->notice->fresh()->isUnsigned())->toBeTrue();
    Livewire::actingAs($this->author)->test(InternalDocument::class)->assertSee('Staff Notice');

    // Undo takes it away from authors again.
    Livewire::actingAs($this->admin)->test(TemplateReview::class, ['template' => $this->notice->fresh()])->call('setUnsigned', false);
    expect($this->notice->fresh()->is_active)->toBeFalse();
});

test('a template with a signature spot cannot be marked as not signed', function () {
    $setup = app(TemplateSetup::class);
    $stored = $setup->storeOriginal(database_path('seeders/templates/ZESCO_Memorandum_Template.docx'), 'docx');
    $memo = $setup->save(PdtsDocumentTemplate::create([
        'name' => 'Internal Memorandum', 'kind' => 'docx', 'original_path' => $stored['path'], 'original_filename' => 'memo.docx',
    ]), ['page' => 'first', 'x' => 10, 'y' => 10, 'size' => 18])->fresh();

    expect($memo->is_active)->toBeTrue();
    expect(fn () => $setup->markUnsigned($memo, true))->toThrow(RuntimeException::class, 'has a signature spot');
});

test('a document from a not-signed template is issued in PDTS: no signing, no printing or scanning', function () {
    app(TemplateSetup::class)->markUnsigned($this->notice, true);
    $draft = noticeDraft($this->author);

    $page = Livewire::actingAs($this->author)->test(Show::class, ['document' => $draft])
        ->assertSeeHtml('data-test="issue-unsigned"')
        ->assertDontSee('Upload signed scan')->assertDontSeeHtml('data-test="sign-electronically"');

    expect(fn () => app(InternalDocuments::class)->attachSignedScan($draft, $this->author, UploadedFile::fake()->create('scan.pdf', 20, 'application/pdf')))
        ->toThrow(RuntimeException::class, 'not printed and scanned');

    // Someone else cannot issue it.
    expect(app(InternalDocuments::class)->issueBlocker($draft, $this->admin))->toContain('Only the author');

    $page->call('issueUnsigned')->assertHasErrors('reviewed');
    $page->set('reviewed', true)->call('issueUnsigned')->assertHasNoErrors();

    $draft->refresh();
    expect($draft->status)->toBe('registered')
        ->and($draft->signature_status)->toBe(PdtsDocument::SIGNATURE_NOT_REQUIRED)
        ->and($draft->primaryAttachment->mime_type)->toBe('application/pdf')
        ->and($draft->auditLogs->firstWhere('action', 'registered')->details['issued_without_signature'])->toBeTrue()
        ->and(app(DocumentOrigins::class)->summary()['overall'])->toMatchArray(['electronic' => 1, 'paper_signed' => 0]);
});

test('a not-signed template talks about issuing, not signing, from the form to the draft', function () {
    // Marked as not signed by the administrator, as on the template's Form settings.
    Livewire::actingAs($this->admin)->test(TemplateReview::class, ['template' => $this->notice])->call('setUnsigned', true);

    // The form: step 3 is to issue it.
    Livewire::actingAs($this->author)->test(InternalDocument::class)->set('template_id', (string) $this->notice->id)
        ->assertSee('Issue it in PDTS')->assertDontSee('Sign it in PDTS with your signature PIN');

    // The message after saving, and the draft: issue it, read it on the page first.
    $document = noticeDraft($this->author);
    expect(session('success'))->toBe("Draft {$document->reference_number} created. Check it, then issue it here.");

    Livewire::actingAs($this->author)->test(Show::class, ['document' => $document])
        ->assertSee('Draft: waiting to be issued')->assertSee('Not signed: read it, tick the box and issue it.')
        ->assertSeeHtml('data-test="issue-read"')->assertSee('Issue the document')
        ->assertDontSee('signature PIN')->assertDontSee('Download it to read');
});
