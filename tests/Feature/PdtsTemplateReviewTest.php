<?php

use App\Livewire\Pdts\Documents\InternalDocument;
use App\Livewire\Pdts\Organization\DocumentTemplates;
use App\Livewire\Pdts\Organization\TemplateQrSetup;
use App\Livewire\Pdts\Organization\TemplateReview;
use App\Models\PdtsDepartment;
use App\Models\PdtsDirectorate;
use App\Models\PdtsDocument;
use App\Models\PdtsDocumentTemplate;
use App\Models\PdtsDocumentType;
use App\Models\PdtsOffice;
use App\Models\User;
use App\Services\Pdts\InternalDocuments;
use App\Services\Pdts\Signatures\PdfConverter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/../Support/docx.php';

uses(RefreshDatabase::class);

/** Stands in for LibreOffice: keeps the Word file it was given. */
class ReviewTestPdfConverter extends PdfConverter
{
    public static ?string $lastWord = null;

    public function engine(): ?string
    {
        return 'fake';
    }

    public function convert(string $docx): string
    {
        self::$lastWord = $docx;

        return "%PDF-1.4\n% sample\n%%EOF\n";
    }
}

beforeEach(function () {
    Storage::fake('private');
    app()->instance(PdfConverter::class, new ReviewTestPdfConverter);

    foreach (['documents.register', 'documents.view', 'documents.forward', 'documents.acknowledge', 'document_types.manage'] as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $this->admin = User::factory()->create(['man_no' => 'REV-0001', 'name' => 'Chanda Phiri', 'job_title' => 'Principal Officer']);
    $this->admin->givePermissionTo(['documents.register', 'documents.view', 'documents.forward', 'document_types.manage']);
    $this->actingAs($this->admin);

    $this->type = PdtsDocumentType::create(['name' => 'Letter', 'is_active' => true]);
    $this->directorate = PdtsDirectorate::create(['name' => 'Corporate Services', 'is_active' => true]);
    $this->department = PdtsDepartment::create(['name' => 'ICT', 'directorate_id' => $this->directorate->id, 'is_active' => true]);
    $this->office = PdtsOffice::create(['name' => 'ICT Office', 'department_id' => $this->department->id, 'is_active' => true]);
});

/** A small letter: a heading with nothing under it PDTS would recognise, and an "Approved:" line. */
function reviewLetter(): string
{
    $b = fn ($t) => '<w:r><w:rPr><w:b/></w:rPr><w:t xml:space="preserve">'.$t.'</w:t></w:r>';
    $n = fn ($t) => '<w:r><w:t xml:space="preserve">'.$t.'</w:t></w:r>';

    return $b('TO:').$n(' ________').'</w:p><w:p>'
        .$b('Background').'</w:p><w:p>'             // line 1: a heading PDTS does not know
        .$n('Notes for the reader').'</w:p><w:p>'   // line 2
        .$b('Approved').'</w:p><w:p>'               // line 3: where the officer signs
        .$n('Account code').'</w:p><w:p>'           // line 4: a field PDTS does not know
        .$n('End of letter.');
}

function uploadReviewTemplate(string $name, array $marks = []): PdtsDocumentTemplate
{
    Livewire::test(DocumentTemplates::class)
        ->call('openCreate')
        ->set('name', $name)
        ->set('documentTypeId', (string) test()->type->id)
        ->set('file', UploadedFile::fake()->createWithContent('letter.docx', pdtsTestDocx(reviewLetter())))
        ->call('save')->assertHasNoErrors();

    $template = PdtsDocumentTemplate::where('name', $name)->latest('id')->firstOrFail();
    Livewire::test(TemplateQrSetup::class, ['template' => $template])
        ->call('save', ['page' => 'all', 'x' => 170, 'y' => 10, 'size' => 22], $marks)
        ->assertHasNoErrors()
        ->assertRedirect(route('pdts.organization.document-templates.review', $template));

    return $template->fresh();
}

test('what PDTS found is on the QR code page; the review page keeps only what is missing, with tips', function () {
    $template = uploadReviewTemplate('Plain Letter');

    expect($template->supportsElectronicSignature())->toBeFalse();

    $this->get(route('pdts.organization.document-templates.qr', $template))
        ->assertOk()
        ->assertSee('What PDTS found')
        ->assertSee('No signature spot: not offered to authors yet');

    $this->get(route('pdts.organization.document-templates.review', $template))
        ->assertOk()
        ->assertDontSee('What PDTS found')
        ->assertSee('No signature spot found')
        ->assertSee('There is no space to write the main text in PDTS')
        ->assertSee('They are signed: mark the signature line');
});

test('lines marked on the preview become a writing space, a field and the signature spot', function () {
    $template = uploadReviewTemplate('Marked Letter', [
        ['line' => 1, 'text' => 'Background', 'kind' => 'writing', 'label' => 'Background'],
        ['line' => 3, 'text' => 'Approved', 'kind' => 'signature', 'label' => ''],
        ['line' => 4, 'text' => 'Account code', 'kind' => 'field', 'label' => 'Account code'],
    ]);

    expect($template->manual_marks)->toHaveCount(3)
        ->and($template->placeholderNames())->toContain('text_background', 'signature', 'account_code')
        ->and($template->supportsElectronicSignature())->toBeTrue()
        ->and($template->customFields())->toMatchArray(['text_background' => 'Background', 'account_code' => 'Account code']);

    $xml = pdtsZipPart(Storage::disk('private')->get($template->storage_path), 'word/document.xml');
    $text = strip_tags(str_replace('</w:p>', "\n", $xml));
    expect($text)->toMatch('/Background\s*\n\$\{text_background\}/')  // a new line under the heading
        ->toMatch('/Approved \$\{signature\}/')
        ->toMatch('/Account code \$\{account_code\}/');

    $this->get(route('pdts.organization.document-templates.qr', $template))
        ->assertSee('Signature spot found: can be signed in PDTS')
        ->assertSee('Background');
});

test('a mark that no longer matches its line is skipped and reported', function () {
    $template = uploadReviewTemplate('Stale Letter', [
        ['line' => 2, 'text' => 'Something else entirely', 'kind' => 'field', 'label' => 'Reader'],
    ]);

    expect($template->placeholderNames())->not->toContain('reader');
    $this->get(route('pdts.organization.document-templates.review', $template))->assertSee('no longer matches the file and was skipped');
});

test('form settings change how the form looks and what goes on the document', function () {
    $template = uploadReviewTemplate('Settings Letter', [
        ['line' => 1, 'text' => 'Background', 'kind' => 'writing', 'label' => 'Background'],
        ['line' => 2, 'text' => 'Notes for the reader', 'kind' => 'field', 'label' => 'Prepared by'],
        ['line' => 3, 'text' => 'Approved', 'kind' => 'signature', 'label' => ''],
        ['line' => 4, 'text' => 'Account code', 'kind' => 'field', 'label' => 'Account code'],
    ]);

    Livewire::test(TemplateReview::class, ['template' => $template])
        ->set('settings.account_code.type', 'choice')
        ->call('saveSettings')->assertHasErrors('settings.account_code.choices') // choices are needed
        ->set('settings.account_code.choices', '4410, 4420, 4430')
        ->set('settings.account_code.label', 'Cost centre')
        ->set('settings.account_code.required', '1')
        ->set('settings.account_code.help', 'From your budget sheet')
        ->set('settings.prepared_by.auto', 'author_name')
        ->set('settings.to.hidden', true)
        ->call('saveSettings')->assertHasNoErrors();

    $template->refresh();
    expect($template->fieldSetting('account_code'))->toMatchArray(['label' => 'Cost centre', 'type' => 'choice', 'required' => true, 'choices' => ['4410', '4420', '4430']]);

    $form = Livewire::test(InternalDocument::class)->set('template_id', (string) $template->id);
    $form->assertSee('Cost centre')->assertSee('From your budget sheet')->assertSeeHtml('<option value="4420">')
        ->assertDontSeeHtml('id="internal-to"')                  // hidden
        ->assertSet('custom.prepared_by', 'Chanda Phiri');      // filled in automatically

    $form->set('subject', 'Laptops')
        ->set('directorate_id', (string) $this->directorate->id)->set('department_id', (string) $this->department->id)->set('office_id', (string) $this->office->id)
        ->call('save')->assertHasErrors('custom.account_code')   // required
        ->set('custom.account_code', '9999')->call('save')->assertHasErrors('custom.account_code') // not one of the choices
        ->set('custom.account_code', '4420')->call('save')->assertHasNoErrors();

    $xml = strip_tags(pdtsZipPart(app(InternalDocuments::class)->wordFile(PdtsDocument::sole())['contents'], 'word/document.xml'));
    expect($xml)->toContain('Account code 4420')->toContain('Notes for the reader')->not->toContain('${');
});

test('"Try it" shows the template as the exact PDF, with sample details and a sample signature', function () {
    $template = uploadReviewTemplate('Try Letter', [
        ['line' => 1, 'text' => 'Background', 'kind' => 'writing', 'label' => 'Background'],
        ['line' => 3, 'text' => 'Approved', 'kind' => 'signature', 'label' => ''],
    ]);

    $this->get(route('pdts.organization.document-templates.review', $template))->assertSee('Show it with sample details');
    $this->get(route('pdts.organization.document-templates.sample-pdf', $template))->assertOk()->assertHeader('Content-Type', 'application/pdf');

    $xml = pdtsZipPart(ReviewTestPdfConverter::$lastWord, 'word/document.xml');
    expect($xml)->toContain('This is sample text for the background')->toContain('name="Signature"')->not->toContain('${');
});

test('replacing the file of a template in use makes a new version; documents keep the old one', function () {
    $signHere = [['line' => 3, 'text' => 'Approved', 'kind' => 'signature', 'label' => '']];
    $v1 = uploadReviewTemplate('Versioned Letter', $signHere);
    $document = PdtsDocument::create([
        'reference_number' => 'OMTS-2026-0700', 'title' => 'Old', 'subject' => 'Old', 'document_type_id' => $this->type->id,
        'originating_office' => 'ICT Office', 'registered_by' => $this->admin->id, 'current_custodian_id' => $this->admin->id,
        'status' => 'draft', 'registered_at' => now(), 'origin' => 'internal', 'template_id' => $v1->id, 'template_data' => ['to' => 'X'],
    ]);

    Livewire::test(DocumentTemplates::class)
        ->call('edit', $v1->id)
        ->assertSeeHtml('data-test="new-version-note"')
        ->set('file', UploadedFile::fake()->createWithContent('letter-v2.docx', pdtsTestDocx(reviewLetter())))
        ->call('save')->assertHasNoErrors();

    $v2 = PdtsDocumentTemplate::where('previous_version_id', $v1->id)->sole();
    expect($v2->version)->toBe(2)
        ->and($v1->fresh()->is_active)->toBeTrue()      // still in use until version 2 is saved
        ->and($v1->fresh()->superseded_at)->toBeNull();

    // Saved without a signature spot: version 2 is not ready, so version 1 stays offered.
    Livewire::test(TemplateQrSetup::class, ['template' => $v2])->call('save', ['page' => 'all', 'x' => 170, 'y' => 10, 'size' => 22])->assertHasNoErrors();
    expect($v2->fresh()->is_active)->toBeFalse()
        ->and($v1->fresh()->is_active)->toBeTrue()
        ->and($v1->fresh()->superseded_at)->toBeNull();

    Livewire::test(TemplateQrSetup::class, ['template' => $v2->fresh()])->call('save', ['page' => 'all', 'x' => 170, 'y' => 10, 'size' => 22], $signHere)->assertHasNoErrors();

    expect($v1->fresh()->superseded_at)->not->toBeNull()
        ->and($v1->fresh()->is_active)->toBeFalse()
        ->and($v2->fresh()->is_active)->toBeTrue()
        ->and($document->fresh()->template_id)->toBe($v1->id);

    // The list shows the latest version only; the old one still serves its documents.
    Livewire::test(DocumentTemplates::class)->assertSee('v2');
    expect(Livewire::test(DocumentTemplates::class)->viewData('templates')->pluck('id')->all())->toBe([$v2->id]);
    expect(app(InternalDocuments::class)->wordFile($document->fresh())['contents'])->toStartWith('PK');
});

test('the guide explains how to prepare a template', function () {
    $this->get(route('pdts.organization.document-templates.guide'))
        ->assertOk()
        ->assertSee('How to prepare a template for PDTS')
        ->assertSee('Two columns on one line: use a tab')
        ->assertSee('Where the signature goes')
        ->assertSee('Build it in PDTS')->assertSee('No signature spot found?')->assertSee('a line drawn under the CC block stays', false)
        ->assertSee("ZESCO's font: Tahoma", false);
    $this->get(route('pdts.organization.document-templates'))->assertSee('How to prepare a template');
});
