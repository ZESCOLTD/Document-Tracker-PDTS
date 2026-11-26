<?php

use App\Livewire\Pdts\Documents\InternalDocument;
use App\Livewire\Pdts\Organization\DocumentTemplates;
use App\Livewire\Pdts\Organization\TemplateBuilder;
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
use App\Services\Pdts\Templates\DocxPackage;
use App\Services\Pdts\Templates\TemplateDesigner;
use App\Services\Pdts\Templates\TemplateSetup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/../Support/docx.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('private');
    foreach (['documents.register', 'documents.view', 'document_types.manage'] as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }
    $this->admin = User::factory()->create(['man_no' => 'BLD-01', 'name' => 'Template Admin']);
    $this->admin->givePermissionTo(['document_types.manage', 'documents.register', 'documents.view']);
    $this->type = PdtsDocumentType::create(['name' => 'Request', 'is_active' => true]);
    $this->directorate = PdtsDirectorate::create(['name' => 'Corporate Services', 'is_active' => true]);
    $this->department = PdtsDepartment::create(['name' => 'ICT', 'directorate_id' => $this->directorate->id, 'is_active' => true]);
    $this->office = PdtsOffice::create(['name' => 'ICT Office', 'department_id' => $this->department->id, 'is_active' => true]);
    $this->actingAs($this->admin);
});

/** Builds a leave request the way an administrator would, by adding and arranging items. */
function buildLeaveRequest(string $layout = 'memo')
{
    // Starts with TO, FROM, DATE, SUBJECT, Body and the signature block.
    $builder = Livewire::test(TemplateBuilder::class)
        ->set('name', 'Leave Request')
        ->set('documentTypeId', (string) test()->type->id)
        ->set('design.layout', $layout)
        ->call('addItem', 'question', 'choice', 4)       // dropped after SUBJECT
        ->set('design.items.4.label', 'Type of leave')
        ->set('design.items.4.choices', 'Annual, Sick, Compassionate')
        ->set('design.items.4.required', true)
        ->call('addItem', 'question', 'number')         // clicked: added at the end
        ->set('design.items.7.label', 'Number of days')
        ->call('moveItem', 7, 5)                        // dragged above the body
        ->call('addItem', 'section', null, 4)
        ->set('design.items.4.label', 'Leave details');

    return $builder;
}

test('an administrator builds a template by adding, dragging and setting up items; it is ready for authors', function () {
    $builder = buildLeaveRequest();
    expect(collect($builder->get('design.items'))->map(fn ($i) => $i['kind'] === 'standard' ? $i['name'] : ($i['label'] ?: $i['kind']))->all())
        ->toBe(['to', 'from', 'date', 'subject', 'Leave details', 'Type of leave', 'Number of days', 'Body', 'signature']);

    $builder->call('save')->assertHasNoErrors();
    $template = PdtsDocumentTemplate::sole();
    $builder->assertRedirect(route('pdts.organization.document-templates.review', $template));

    expect($template->isBuilt())->toBeTrue()
        ->and($template->is_active)->toBeTrue()
        ->and($template->supportsElectronicSignature())->toBeTrue()
        ->and($template->isConfigured())->toBeTrue()
        ->and($template->customFields())->toBe(['type_of_leave' => 'Type of leave', 'number_of_days' => 'Number of days', 'text_body' => 'Body'])
        ->and($template->fieldSetting('type_of_leave'))->toMatchArray(['type' => 'choice', 'required' => true, 'choices' => ['Annual', 'Sick', 'Compassionate']])
        ->and($template->fieldSetting('number_of_days')['type'])->toBe('number')
        ->and(array_count_values($template->placeholderNames())['signature'])->toBe(1);

    // The review page of a built template: one "Edit in the builder", no form-settings section, no "mark the line" note.
    $review = Livewire::test(TemplateReview::class, ['template' => $template])
        ->assertSee('Edit in the builder')->assertSee('1. The form authors fill in')->assertSee('2. Try it')
        ->assertDontSeeHtml('data-test="field-settings"')->assertDontSee('How the form looks')->assertDontSee('Mark the line on the template');
    expect(substr_count($review->html(), 'Edit in the builder'))->toBe(1);

    // Authors get the form the administrator designed, and the answers go on the document.
    $form = Livewire::test(InternalDocument::class)->set('template_id', (string) $template->id)
        ->assertSee('Type of leave')->assertSeeHtml('<option value="Compassionate">')->assertSee('Number of days');
    $form->set('to', 'Director ICT')->set('subject', 'Annual leave in December')
        ->set('custom.type_of_leave', 'Annual')->set('custom.number_of_days', '10')
        ->set('custom.text_body', 'I would like to take my annual leave.')
        ->set('directorate_id', (string) $this->directorate->id)->set('department_id', (string) $this->department->id)->set('office_id', (string) $this->office->id)
        ->call('save')->assertHasNoErrors();

    $text = strip_tags(pdtsZipPart(app(InternalDocuments::class)->wordFile(PdtsDocument::sole())['contents'], 'word/document.xml'));
    expect($text)->toContain('Type of leave:')->toContain('Annual')->toContain('Number of days:')->toContain('10')
        ->toContain('I would like to take my annual leave.')->toContain('LEAVE DETAILS')->toContain('Director ICT')->not->toContain('${');
});

test('the review page previews the form authors fill in; checking the answers saves nothing', function () {
    buildLeaveRequest()->call('save')->assertHasNoErrors();
    $template = PdtsDocumentTemplate::sole();
    $template->update(['is_active' => false]);   // works whatever the status

    Livewire::test(TemplateReview::class, ['template' => $template])
        ->assertSeeHtml('data-test="form-preview"')->assertSeeHtml('data-test="show-form"');

    $preview = Livewire::test(InternalDocument::class, ['previewTemplateId' => $template->id])
        ->assertSee('Type of leave')->assertSeeHtml('<option value="Compassionate">')->assertSee('Number of days')
        ->assertSeeHtml('data-test="preview-actions"')
        ->assertDontSee('Originating office')->assertDontSee('Create draft');

    // The form's own rules, without saving.
    $preview->call('checkPreview')->assertHasErrors(['subject', 'custom.type_of_leave'])->assertSet('previewPassed', false);
    $preview->set('to', 'Director ICT')->set('subject', 'Annual leave')
        ->set('custom.type_of_leave', 'Annual')->set('custom.number_of_days', '10')
        ->set('custom.text_body', 'I would like to take my annual leave.')
        ->call('checkPreview')->assertHasNoErrors()->assertSet('previewPassed', true)
        ->assertSeeHtml('data-test="preview-passed"');
    $preview->call('save')->assertHasNoErrors();
    expect(PdtsDocument::count())->toBe(0);

    // Changing an answer clears the "all good" message.
    $preview->set('subject', 'Changed')->assertSet('previewPassed', false);

    // Only template administrators can preview.
    $author = User::factory()->create(['man_no' => 'BLD-02']);
    $author->givePermissionTo(['documents.register', 'documents.view']);
    $this->actingAs($author);
    Livewire::test(InternalDocument::class, ['previewTemplateId' => $template->id])->assertForbidden();
});

test('the review page shows the document filled with the answers typed on its form; nothing is saved', function () {
    // Stands in for LibreOffice: the "PDF" carries the filled Word file, so what was filled in can be read.
    app()->instance(PdfConverter::class, new class extends PdfConverter
    {
        public function engine(): ?string
        {
            return 'fake';
        }

        public function convert(string $docx): string
        {
            return '%PDF-1.4 '.base64_encode($docx);
        }
    });
    buildLeaveRequest()->call('save')->assertHasNoErrors();
    $template = PdtsDocumentTemplate::sole();

    $preview = Livewire::test(InternalDocument::class, ['previewTemplateId' => $template->id])
        ->assertSeeHtml('data-test="preview-document"')->assertDontSeeHtml('data-test="preview-pdf"');
    // The answers are checked first, as with "Check my answers".
    $preview->call('previewDocument')->assertHasErrors(['subject'])->assertSet('previewPdfUrl', null);

    $preview->set('to', 'Director ICT')->set('subject', 'Annual leave in December')
        ->set('custom.type_of_leave', 'Annual')->set('custom.number_of_days', '10')
        ->set('custom.text_body', 'I would like to take my annual leave.')
        ->call('previewDocument')->assertHasNoErrors()
        ->assertSeeHtml('data-test="preview-pdf"');

    $url = $preview->get('previewPdfUrl');
    $pdf = $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent();
    $xml = strip_tags(pdtsZipPart(base64_decode(substr($pdf, strlen('%PDF-1.4 '))), 'word/document.xml'));
    expect($xml)->toContain('Director ICT')->toContain('Annual leave in December')->toContain('Annual')->toContain('10')
        ->toContain('I would like to take my annual leave.')->toContain('TEMPLATE ADMIN')   // the signatory's name, under the signature
        ->not->toContain('${')
        ->and(PdtsDocument::count())->toBe(0);

    // Done looking: the preview closes, the answers stay.
    $preview->assertSeeHtml('data-test="close-preview"')->call('closePreview')
        ->assertSet('previewPdfUrl', null)->assertDontSeeHtml('data-test="preview-pdf"')
        ->assertSet('custom.text_body', 'I would like to take my annual leave.');
});

test('a new template is built on the ZESCO memo letterhead: its logo, title style, footer and QR place', function () {
    $setup = app(TemplateSetup::class);
    $stored = $setup->storeOriginal(database_path('seeders/templates/ZESCO_Memorandum_Template.docx'), 'docx');
    $memo = $setup->save(PdtsDocumentTemplate::create([
        'name' => 'Internal Memorandum', 'kind' => 'docx', 'original_path' => $stored['path'], 'original_filename' => 'memo.docx',
    ]), ['page' => 'first', 'x' => 10, 'y' => 10, 'size' => 16])->fresh();

    // The memo is the same letterhead as the standard ZESCO one: nothing to choose.
    $builder = Livewire::test(TemplateBuilder::class)
        ->assertSet('design.letterhead', null)
        ->assertDontSeeHtml('data-test="letterhead-choice"')
        ->assertSeeHtml('data-test="letterhead-preview"')->assertSeeHtml('src="data:image/png;base64,')
        ->assertSee('Powering The Nation')->assertSee('TEMPLATE TITLE')
        ->set('name', 'Circular')
        ->call('addItem', 'standard', 'office_reference', 2);
    // TO beside FROM, then REF beside DATE, as in the memo.
    expect($builder->viewData('rows'))->toBe([[0, 1], [2, 3], [4], [5], [6]]);
    $builder->call('save')->assertHasNoErrors();

    $circular = PdtsDocumentTemplate::where('name', 'Circular')->sole();
    $parts = DocxPackage::read(Storage::disk('private')->path($circular->original_path));
    $text = strip_tags(str_replace('</w:p>', "\n", $parts['word/document.xml']));
    expect($text)->toContain('Powering The Nation')->toContain('CIRCULAR')->not->toContain('MEMORANDUM')
        ->toContain('TO: ${to}')->toContain('REF: ${office_reference}')->toContain('${author_upper}')
        ->and($parts['word/document.xml'])->toContain('<w:caps/>')                 // the subject, in capitals
        ->and(collect(array_keys($parts))->filter(fn ($p) => str_starts_with($p, 'word/footer'))->isNotEmpty())->toBeTrue()
        ->and(collect(array_keys($parts))->filter(fn ($p) => str_starts_with($p, 'word/media/'))->count())->toBeGreaterThanOrEqual(2)
        ->and($circular->placement())->toBe($memo->placement())
        ->and($circular->supportsElectronicSignature())->toBeTrue();

    // A template with a really different letterhead makes the choice appear (standard first).
    $file = tempnam(sys_get_temp_dir(), 'letter').'.docx';
    file_put_contents($file, pdtsTestDocx('<w:pPr><w:jc w:val="center"/></w:pPr><w:r><w:t>OFFICIAL LETTER</w:t></w:r></w:p><w:p><w:r><w:t xml:space="preserve">Dear ${to},</w:t></w:r>', '<w:r><w:t>Letter footer</w:t></w:r>'));
    $other = $setup->storeOriginal($file, 'docx');
    @unlink($file);
    PdtsDocumentTemplate::create(['name' => 'Official Letter', 'kind' => 'docx', 'original_path' => $other['path'], 'original_filename' => 'letter.docx']);

    Livewire::test(TemplateBuilder::class)
        ->assertSet('design.letterhead', null)
        ->assertSeeHtml('data-test="letterhead-choice"')->assertSee('ZESCO (standard)')->assertSee('From: Official Letter')
        ->assertDontSee('From: Internal Memorandum');
});

test('undo and redo in the builder: each change is a step, typing in one field is one step', function () {
    $builder = Livewire::test(TemplateBuilder::class)->assertViewHas('canUndo', false)->assertViewHas('canRedo', false);
    $start = count($builder->get('design.items'));

    $builder->call('addItem', 'question', 'text')->assertViewHas('canUndo', true);
    $added = count($builder->get('design.items')) - 1;
    $label = $builder->get("design.items.$added.label");
    $builder->set("design.items.$added.label", 'Am')->set("design.items.$added.label", 'Amount');   // typing

    // Undo: the typing as one step, then the added question.
    $builder->call('undo')->assertSet("design.items.$added.label", $label)->assertViewHas('canRedo', true);
    $builder->call('undo');
    expect(count($builder->get('design.items')))->toBe($start);
    $builder->assertViewHas('canUndo', false);

    // Redo brings them back; a new change after an undo clears redo.
    $builder->call('redo')->call('redo')->assertSet("design.items.$added.label", 'Amount');
    $builder->call('undo')->call('remove', 0)->assertViewHas('canRedo', false);

    $this->get(route('pdts.organization.document-templates.build'))->assertSeeHtml('data-test="undo"')->assertSeeHtml('data-test="preview"');
});

test('the QR code is placed in the builder: dragged, put in a corner, sized; saving puts it there', function () {
    $builder = Livewire::test(TemplateBuilder::class)->set('name', 'Circular')
        ->assertSet('design.qr', TemplateDesigner::BUILT_IN_QR)   // the letterhead's standard spot
        ->assertSeeHtml('data-test="qr-spot"')->assertDontSeeHtml('data-test="qr-settings"')
        ->assertSeeHtml('data-test="settings-layout"')                  // the layout is in the template settings
        ->call('selectQr')->assertSeeHtml('data-test="qr-settings"');   // the QR code's settings show when it is clicked

    $builder->call('qrCorner', 'top-right')->assertSet('design.qr.x', 184.0)->assertSet('design.qr.y', 10.0);
    $builder->set('design.qr.size', '60')->assertSet('design.qr.size', 45.0)
        ->set('design.qr.size', '5')->assertSet('design.qr.size', 15.0)
        ->set('design.qr.size', '22')->set('design.qr.page', 'all');
    $builder->call('placeQr', 150.04, 255.0)->assertSet('design.qr.x', 150.0)->assertSet('design.qr.y', 255.0);
    $builder->call('undo')->assertSet('design.qr.x', 184.0);   // undo covers it too
    $builder->call('redo')->call('save')->assertHasNoErrors();

    $template = PdtsDocumentTemplate::sole();
    expect($template->placement())->toBe(['page' => 'all', 'x' => 150.0, 'y' => 255.0, 'size' => 22.0])
        ->and($template->design['qr'])->toEqual(['page' => 'all', 'x' => 150.0, 'y' => 255.0, 'size' => 22.0]);

    // Opened again, the builder shows it where it is; back to the standard spot in one click.
    Livewire::test(TemplateBuilder::class, ['template' => $template])->assertSet('design.qr.x', 150.0)
        ->call('qrStandard')->assertSet('design.qr', TemplateDesigner::BUILT_IN_QR);
});

test('Preview shows the design as the exact PDF with sample details, saving nothing', function () {
    $converter = Mockery::mock(PdfConverter::class);
    $converter->shouldReceive('available')->andReturn(true);
    $converter->shouldReceive('convert')->once()->with(Mockery::on(function (string $docx) {
        $text = strip_tags(pdtsZipPart($docx, 'word/document.xml'));

        return str_contains($text, 'SAMPLE SUBJECT LINE') && str_contains($text, 'Director - Corporate Affairs') && ! str_contains($text, '${');
    }))->andReturn('%PDF-1.4 preview');
    $this->app->instance(PdfConverter::class, $converter);

    $url = null;
    Livewire::test(TemplateBuilder::class)->set('name', 'Circular')
        ->call('preview')->assertHasNoErrors()
        ->assertDispatched('builder-preview', function ($event, $params) use (&$url) {
            $url = $params['url'];

            return true;
        });

    $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertSee('%PDF-1.4 preview', false);
    expect(PdtsDocumentTemplate::count())->toBe(0)
        ->and(collect(Storage::disk('private')->allFiles())->filter(fn ($f) => str_contains($f, 'preview-')))->toBeEmpty();

    // Only for the person who made it.
    $other = User::factory()->create(['man_no' => 'BLD-09']);
    $other->givePermissionTo('document_types.manage');
    $this->actingAs($other)->get($url)->assertNotFound();
});

test('a CC line in a built memo lines up further names under the first, with no line under it', function () {
    Livewire::test(TemplateBuilder::class)->set('name', 'Circular')
        ->call('addItem', 'standard', 'cc', 2)            // after TO and FROM
        ->call('save')->assertHasNoErrors();
    $template = PdtsDocumentTemplate::sole();

    $xml = DocxPackage::read(Storage::disk('private')->path($template->storage_path))['word/document.xml'];
    preg_match('#<w:p[ >](?:(?!</w:p>).)*\$\{cc\}.*?</w:p>#s', $xml, $cc);
    expect($cc[0] ?? '')->toContain('<w:ind w:left="560" w:hanging="560"/>')->not->toContain('<w:pBdr>');

    // Two people copied: the second goes under the first. Nobody copied: no "CC:".
    $make = function (string $cc, string $subject) use ($template) {
        Livewire::test(InternalDocument::class)->set('template_id', (string) $template->id)
            ->set('to', 'Director ICT')->set('from', 'Manager ICT')->set('cc', $cc)->set('subject', $subject)
            ->set('custom.text_body', 'Body text.')
            ->set('directorate_id', (string) test()->directorate->id)->set('department_id', (string) test()->department->id)->set('office_id', (string) test()->office->id)
            ->call('save')->assertHasNoErrors();

        return pdtsZipPart(app(InternalDocuments::class)->wordFile(PdtsDocument::where('subject', $subject)->sole())['contents'], 'word/document.xml');
    };
    $two = $make("Chief Accountant\nHead of HR", 'Copied');
    expect($two)->toMatch('#<w:ind w:left="560" w:hanging="560"/>.*Chief Accountant.*<w:br/>.*Head of HR#s');

    $none = $make('', 'Not copied');
    expect(strip_tags($none))->not->toContain('CC:');
});

test('a Line item can be thin, medium or thick, in the builder and on the document', function () {
    $builder = Livewire::test(TemplateBuilder::class)->set('name', 'Circular')
        ->call('addItem', 'line', null, 2)->call('addItem', 'line', null, 3)->call('addItem', 'line', null, 4);
    $builder->assertSeeHtml('data-test="line-thin"')                    // a new line is thin, as before
        ->set('design.items.3.weight', 'medium')->set('design.items.4.weight', 'thick')
        ->assertSeeHtml('data-test="line-medium"')->assertSeeHtml('data-test="line-thick"')
        ->call('select', 4)->assertSeeHtml('data-test="line-weight"')->assertSee('Thick (3 pt)')
        ->call('save')->assertHasNoErrors();

    $template = PdtsDocumentTemplate::sole();
    $xml = DocxPackage::read(Storage::disk('private')->path($template->storage_path))['word/document.xml'];
    expect($xml)->toContain('<w:bottom w:val="single" w:sz="8" ')->toContain('<w:bottom w:val="single" w:sz="12" ')->toContain('<w:bottom w:val="single" w:sz="24" ')
        ->and(collect($template->design['items'])->where('kind', 'line')->pluck('weight')->all())->toBe(['thin', 'medium', 'thick']);
});

test('every built template carries the ZESCO logo and footer, even when no template has been uploaded', function () {
    Livewire::test(TemplateBuilder::class)->assertSet('design.letterhead', null)
        ->assertSeeHtml('data-test="letterhead-preview"')
        ->set('name', 'Staff Notice')->set('design.layout', 'form')->call('save')->assertHasNoErrors();

    $template = PdtsDocumentTemplate::sole();
    $parts = DocxPackage::read(Storage::disk('private')->path($template->storage_path));
    $footer = collect($parts)->filter(fn ($xml, $name) => str_starts_with($name, 'word/footer'))->implode('');

    expect(strip_tags($parts['word/document.xml']))->toContain('Powering The Nation')->toContain('STAFF NOTICE')
        ->and($parts['word/document.xml'])->toContain('<w:drawing')                      // the logo
        ->and(strip_tags($footer))->toContain('All correspondence to be addressed to the Managing Director')
        ->and($template->placement())->toMatchArray(['page' => 'first', 'x' => 10.0, 'y' => 10.0]);
});

test('the letter layout is laid out like the ZESCO letter, and keeps its own page apart from the memo', function () {
    $builder = Livewire::test(TemplateBuilder::class)->set('name', 'Offer Letter')
        ->assertSeeHtml('data-test="layout-letter"')
        ->set('design.layout', 'letter');

    // Its own lines: OUR REF, the date, the addressee, "Dear", the subject, the text, the signature and cc. No title.
    $lines = fn () => collect($builder->get('design.items'))->map(fn ($i) => $i['kind'] === 'standard' ? $i['name'] : ($i['label'] ?: $i['kind']))->all();
    expect($lines())->toBe(['office_reference', 'date', 'Addressee', 'Dear', 'subject', 'Body', 'signature', 'cc']);
    $builder->assertSee('Yours faithfully,')->assertDontSee('TEMPLATE TITLE')->assertDontSee('OFFER LETTER');

    // The memo is untouched, and the letter comes back as it was left.
    $builder->call('remove', 7)->set('design.layout', 'memo');
    expect($lines())->toBe(['to', 'from', 'date', 'subject', 'Body', 'signature']);
    $builder->set('design.layout', 'letter');
    expect($lines())->toBe(['office_reference', 'date', 'Addressee', 'Dear', 'subject', 'Body', 'signature']);

    $builder->call('addItem', 'standard', 'cc')->call('save')->assertHasNoErrors();
    $template = PdtsDocumentTemplate::sole();
    $parts = DocxPackage::read(Storage::disk('private')->path($template->storage_path));
    $text = strip_tags(str_replace('</w:p>', "\n", $parts['word/document.xml']));

    expect($template->design['layout'])->toBe('letter')
        ->and($text)->toContain('Powering The Nation')->toContain('OUR REF: ${office_reference}')->toContain("\${date}\n")
        ->toContain('${text_addressee}')->toContain('Dear ${dear}')->toContain('${subject}')->toContain('${text_body}')
        ->toContain("Yours faithfully,\nZESCO LIMITED\n\${signature}\n\${author_upper}\n\${author_job_title}")
        ->toContain('CC: ${cc}')                                                  // added from the palette: the standard label
        ->not->toContain('OFFER LETTER')->not->toContain('MEMORANDUM')
        ->and(array_keys($template->customFields()))->toBe(['text_addressee', 'dear', 'text_body'])
        ->and($template->supportsElectronicSignature())->toBeTrue();
});

test('any document can be built from scratch on a blank page: no title, "Dear" with no colon, tight lines, job title, page break', function () {
    $builder = Livewire::test(TemplateBuilder::class)->set('name', 'Notice of Award')->set('design.layout', 'custom')
        ->assertSeeHtml('data-test="show-title"')->set('design.show_title', false)
        ->assertDontSee('NOTICE OF AWARD');

    // Addressee lines close together; "Dear" printed with no colon; a closing in two tight lines; a page break before an annex.
    $builder->call('addItemTo', 'question', 'long', 0, 0, 0)
        ->set('design.items.0.label', 'Addressee')->set('design.items.0.style.label', 'hidden')->set('design.items.0.style.tight', true)
        ->call('addRow', 1)->call('addItemTo', 'question', 'text', 1, 0, 0)
        ->set('design.items.1.label', 'Dear')->set('design.items.1.style.label', 'plain')
        ->call('addRow', 1)->call('addItemTo', 'text', null, 2, 0, 0)
        ->set('design.items.2.text', "Yours faithfully,\nZESCO LIMITED")->set('design.items.2.style.tight', true)
        ->call('addRow', 1)->call('addItemTo', 'signature', null, 3, 0, 0)->set('design.items.3.job_title', true)
        ->call('addRow', 1)->call('addItemTo', 'pagebreak', null, 4, 0, 0)
        ->call('addRow', 1)->call('addItemTo', 'section', null, 5, 0, 0)->set('design.items.5.label', 'Annex A');
    $builder->assertSee('page break: a new page starts here')->assertSee('Job title');

    $builder->call('save')->assertHasNoErrors();
    $template = PdtsDocumentTemplate::sole();
    $xml = pdtsZipPart(Storage::disk('private')->get($template->storage_path), 'word/document.xml');
    $text = strip_tags(str_replace('</w:p>', "\n", $xml));

    expect($template->design['show_title'])->toBeFalse()
        ->and($text)->not->toContain('NOTICE OF AWARD')
        ->toContain('Dear ${dear}')->not->toContain('Dear: ')
        ->toContain("\${author_upper}\n\${author_job_title}")
        ->toContain('Annex A')                                                   // the heading (shown in capitals by Word)
        ->and($xml)->toContain('<w:br w:type="page"/>')
        ->toMatch('#<w:spacing w:before="0" w:after="0"/>(?:(?!</w:p>).)*\$\{text_addressee\}#s')   // the addressee's lines close together
        ->toMatch('#w:after="0"/>(?:(?!</w:p>).)*Yours faithfully,#s');
})->skip(fn () => ! TemplateDesigner::builtInLetterhead(), 'needs the ZESCO letterhead file');

test('the report style puts the details in a table and numbers the sections and their paragraphs', function () {
    $builder = Livewire::test(TemplateBuilder::class)->set('name', 'Monthly Report')->set('design.layout', 'report')
        ->call('remove', 4)                                                      // the starter's body
        ->call('addItem', 'section', null, 4)->set('design.items.4.label', 'Introduction')
        ->call('addItem', 'question', 'long', 5)->set('design.items.5.label', 'Background')
        ->call('addItem', 'question', 'long', 6)->set('design.items.6.label', 'Purpose')
        ->call('addItem', 'section', null, 7)->set('design.items.7.label', 'Activities')
        ->call('addItem', 'question', 'rich', 8)->set('design.items.8.label', 'Work done');

    $builder->assertSeeHtml('1.&nbsp; Introduction')->assertSeeHtml('data-test="report-number"')->assertSee('Prepared by');
    expect(TemplateDesigner::reportNumbers($builder->get('design.items')))->toBe([4 => '1', 5 => '1.1', 6 => '1.2', 7 => '2', 8 => '2.1']);

    $builder->call('save')->assertHasNoErrors();
    $template = PdtsDocumentTemplate::sole();
    $xml = pdtsZipPart(Storage::disk('private')->get($template->storage_path), 'word/document.xml');

    expect(strip_tags($xml))->toContain('1.  INTRODUCTION')->toContain('1.1  Background')->toContain('1.2  Purpose')->toContain('2.  ACTIVITIES')->toContain('2.1  Work done')
        ->toContain('Prepared by')->toContain('Powering The Nation')
        ->and($xml)->toContain('w:fill="EEF3F3"')                                     // the details table
        ->and($template->placeholderNames())->not->toContain('prepared_by')          // a heading, not a field
        ->and($template->fieldSetting('text_work_done')['type'])->toBe('rich')
        ->and($template->supportsElectronicSignature())->toBeTrue();
});

test('a built template is shown with readable hints, never with ${placeholders}', function () {
    buildLeaveRequest()->call('save')->assertHasNoErrors();
    $template = PdtsDocumentTemplate::sole();

    $shown = $this->get(route('pdts.organization.document-templates.original', $template))->assertOk()->getContent();
    $text = strip_tags(pdtsZipPart($shown, 'word/document.xml'));

    expect($text)->not->toContain('${')
        ->toContain('[Title of main recipient]')->toContain('[SUBJECT IN CAPITAL LETTERS]')
        ->toContain('[Type of leave: Annual / Sick / Compassionate]')->toContain('[Number of days: number]')
        ->toContain('[Write the body here.]')->toContain('[FULL NAME IN CAPITALS]');

    // The template documents are made from keeps its placeholders.
    expect(pdtsZipPart(Storage::disk('private')->get($template->storage_path), 'word/document.xml'))->toContain('${type_of_leave}');
});

test('the custom layout: rows of 1, 2 or 3 columns, items dropped into columns, each in its own style', function () {
    $builder = Livewire::test(TemplateBuilder::class)->set('name', 'Audit Report')->set('design.layout', 'custom');

    // Switching to Custom gives an empty page: one empty row, no lines.
    expect($builder->get('design.items'))->toBe([])
        ->and($builder->get('design.rows'))->toHaveCount(1)
        ->and($builder->get('design.rows.0.cells'))->toBe([[]]);
    // Memo and Custom are separate pages: the memo is untouched, and each comes back as it was left.
    $builder->call('addItemTo', 'standard', 'cc', 0, 0, 0)->set('design.layout', 'memo');
    expect(collect($builder->get('design.items'))->pluck('name')->filter()->values()->all())->toBe(['to', 'from', 'date', 'subject'])
        ->and($builder->get('design.rows'))->toBeNull();
    $builder->set('design.layout', 'custom');
    expect(collect($builder->get('design.items'))->pluck('name')->all())->toBe(['cc']);
    // Cleared again to build the page from scratch.
    $builder->call('remove', 0);
    expect($builder->get('design.items'))->toBe([]);

    // Built from scratch: TO beside FROM, then DATE, the subject, the body and the signature, one a row.
    $builder->call('setRowColumns', 0, 2)
        ->call('addItemTo', 'standard', 'to', 0, 0, 0)
        ->call('addItemTo', 'standard', 'from', 0, 1, 0)
        ->call('addRow', 1)->call('addItemTo', 'standard', 'date', 1, 0, 0)
        ->call('addRow', 1)->call('addItemTo', 'standard', 'subject', 2, 0, 0)
        ->call('addRow', 1)->call('addItemTo', 'question', 'long', 3, 0, 0)
        ->set('design.items.4.label', 'Body')->set('design.items.4.show_label', false)
        ->call('addRow', 1)->call('addItemTo', 'signature', null, 4, 0, 0);
    $ids = fn () => collect($builder->get('design.items'))->pluck('id', 'label')->all();
    $rows = $builder->get('design.rows');
    expect(count($rows))->toBe(5)->and($rows[0]['columns'])->toBe(2)
        ->and($rows[0]['cells'])->toBe([[$ids()['TO']], [$ids()['FROM']]]);

    // A 3-column row at the end: DATE dragged into column 1, a new REF dropped into column 2, File number into column 3.
    $builder->call('addRow', 3)
        ->call('moveItemTo', 2, 5, 0, 0)
        ->call('addItemTo', 'standard', 'office_reference', 5, 1, 0)
        ->call('addItemTo', 'question', 'text', 5, 2, 0)->set('design.items.7.label', 'File number')
        ->call('moveRow', 5, -1)->call('moveRow', 4, -1)->call('moveRow', 3, -1)->call('moveRow', 2, -1); // up under TO/FROM
    // Centred, bold, boxed CONFIDENTIAL on its own row at the top.
    $builder->call('addItem', 'text')->set('design.items.8.text', 'CONFIDENTIAL')
        ->set('design.items.8.style.align', 'center')->set('design.items.8.style.bold', true)->set('design.items.8.style.box', 'border');
    $last = count($builder->get('design.rows')) - 1;
    for ($r = $last; $r > 0; $r--) {
        $builder->call('moveRow', $r, -1);
    }
    // Signed by hand, beside the PDTS signature.
    $sigRow = collect($builder->get('design.rows'))->search(fn ($row) => in_array(collect($builder->get('design.items'))->firstWhere('kind', 'signature')['id'], $row['cells'][0], true));
    $builder->call('setRowColumns', $sigRow, 2)->call('addItemTo', 'signline', null, $sigRow, 1, 0)
        ->call('toggleRowLine', 1)->set('design.rows.1.space', 'small');

    $builder->assertSeeHtml('data-test="add-row"')->assertSeeHtml('data-test="cell-2-2"')->assertSee('Drop items here', false);

    $builder->call('save')->assertHasNoErrors();
    $template = PdtsDocumentTemplate::sole();
    $xml = pdtsZipPart(Storage::disk('private')->get($template->storage_path), 'word/document.xml');
    $text = strip_tags(str_replace('</w:p>', "\n", $xml));

    expect($template->design['rows'])->toHaveCount(6)                              // the emptied DATE row is dropped
        ->and(collect($template->design['rows'])->pluck('columns')->all())->toBe([1, 2, 3, 1, 1, 2])
        ->and($xml)->toContain('<w:gridCol w:w="'.intdiv(9638, 3).'"/>')            // a 3-column row (memo letterhead margins)
        ->and($xml)->toMatch('#<w:jc w:val="center"/></w:pPr><w:r><w:rPr>(?:(?!</w:r>).)*<w:b/>(?:(?!</w:r>).)*CONFIDENTIAL#s')
        ->and($text)->toContain('TO: ${to}')->toContain('REF: ${office_reference}')->toContain('File number: ${file_number}')
        ->toContain('Approved by')->toContain('Name, signature and date')
        // The author's form follows the rows' order.
        ->and(array_keys($template->customFields()))->toBe(['file_number', 'text_body'])
        ->and($template->supportsElectronicSignature())->toBeTrue();
})->skip(fn () => ! TemplateDesigner::builtInLetterhead(), 'needs the ZESCO letterhead file');

test('the form style lays the questions out as a table', function () {
    buildLeaveRequest('form')->call('save')->assertHasNoErrors();
    $template = PdtsDocumentTemplate::sole();

    $xml = Storage::disk('private')->get($template->storage_path);
    expect(pdtsZipPart($xml, 'word/document.xml'))->toContain('w:fill="EEF3F3"')->toContain('${type_of_leave}');
});

test('the builder checks the design: labels, choices, and one of each standard line and signature', function () {
    $builder = Livewire::test(TemplateBuilder::class)->set('name', 'Bad');

    expect($builder->instance()->canAdd('standard', 'to'))->toBeFalse()     // already on the starter
        ->and($builder->instance()->canAdd('signature'))->toBeFalse()
        ->and($builder->instance()->canAdd('standard', 'cc'))->toBeTrue();
    $builder->call('addItem', 'signature')->assertCount('design.items', 6);  // ignored

    $builder->call('addItem', 'question', 'choice')->set('design.items.6.choices', 'Only one')
        ->call('save')->assertHasErrors(['design.items.6.choices'])->assertSet('selected', 6);
    $builder->set('design.items.6.label', '')->call('save')->assertHasErrors(['design.items.6.label']);

    expect(PdtsDocumentTemplate::count())->toBe(0);

    $builder->call('remove', 6)->call('save')->assertHasNoErrors();
    expect(PdtsDocumentTemplate::count())->toBe(1);
});

test('when the template cannot be saved, the message says what is wrong and where', function () {
    // No name, while an item is selected: the settings with the name are brought back on the right.
    Livewire::test(TemplateBuilder::class)->call('select', 2)->call('save')
        ->assertHasErrors('name')->assertSet('selected', null)
        ->assertSeeHtml('data-test="builder-problems"')->assertSee('first:')
        ->assertSee('Template name')->assertSee('Give the template a name.');

    // A problem on an item names the item.
    Livewire::test(TemplateBuilder::class)->set('name', 'Leave Request')
        ->call('addItem', 'question', 'choice')->set('design.items.6.label', 'Type of leave')
        ->call('save')->assertHasErrors('design.items.6.choices')
        ->assertSee('Item 7: Type of leave (choice list)')->assertSee('Give at least two choices');
});

test('a template without a signature block is a "not signed" template', function () {
    $builder = Livewire::test(TemplateBuilder::class)->set('name', 'Staff Notice');
    $builder->call('remove', 5)->call('save')->assertHasNoErrors(); // the signature block

    $template = PdtsDocumentTemplate::sole();
    expect($template->isUnsigned())->toBeTrue()->and($template->is_active)->toBeTrue()->and($template->signingBlocker())->toBeNull();
});

test('editing a template documents were made from makes a new version; the documents keep theirs', function () {
    buildLeaveRequest()->call('save')->assertHasNoErrors();
    $v1 = PdtsDocumentTemplate::sole();
    $document = PdtsDocument::create([
        'reference_number' => 'OMTS-2026-0800', 'title' => 'Leave', 'subject' => 'Leave', 'document_type_id' => $this->type->id,
        'originating_office' => 'ICT Office', 'registered_by' => $this->admin->id, 'current_custodian_id' => $this->admin->id,
        'status' => 'draft', 'registered_at' => now(), 'origin' => 'internal', 'template_id' => $v1->id, 'template_data' => [],
    ]);

    Livewire::test(TemplateBuilder::class, ['template' => $v1])
        ->assertSet('name', 'Leave Request')
        ->assertSee('Saving makes version 2')
        ->set('design.items.5.label', 'Type of leave requested')
        ->call('save')->assertHasNoErrors();

    $v2 = PdtsDocumentTemplate::where('previous_version_id', $v1->id)->sole();
    expect($v2->version)->toBe(2)->and($v2->is_active)->toBeTrue()
        ->and($v1->fresh()->is_active)->toBeFalse()->and($v1->fresh()->superseded_at)->not->toBeNull()
        ->and($document->fresh()->template_id)->toBe($v1->id)
        // The answer keeps its name, so form settings and documents still match.
        ->and($v2->customFields())->toHaveKey('type_of_leave')
        ->and($v2->customFields()['type_of_leave'])->toBe('Type of leave requested')
        ->and($v2->placement())->toBe($v1->placement());
});

test('only template administrators use the builder, and only for templates built in it', function () {
    $author = User::factory()->create(['man_no' => 'BLD-02']);
    $this->actingAs($author)->get(route('pdts.organization.document-templates.build'))->assertForbidden();

    $uploaded = pdtsMemoTemplate();
    $this->actingAs($this->admin)->get(route('pdts.organization.document-templates.edit-design', $uploaded))->assertNotFound();
    $this->actingAs($this->admin)->get(route('pdts.organization.document-templates.build'))->assertOk()->assertSee('Build a template');
});

test('an uploaded file with a typed signature spot keeps just that one', function () {
    $file = tempnam(sys_get_temp_dir(), 'sig').'.docx';
    file_put_contents($file, pdtsTestDocx('<w:r><w:t>SUBJECT: ${subject}</w:t></w:r></w:p><w:p><w:r><w:t>${signature}</w:t></w:r></w:p><w:p><w:r><w:t>[FULL NAME]</w:t></w:r>', '<w:r><w:t>Footer</w:t></w:r>'));
    $setup = app(TemplateSetup::class);
    $stored = $setup->storeOriginal($file, 'docx');
    @unlink($file);
    $template = $setup->save(PdtsDocumentTemplate::create(['name' => 'Typed', 'kind' => 'docx', 'original_path' => $stored['path'], 'original_filename' => 'typed.docx']),
        ['page' => 'first', 'x' => 170, 'y' => 10, 'size' => 20]);

    $xml = pdtsZipPart(Storage::disk('private')->get($template->storage_path), 'word/document.xml');
    expect(substr_count(strip_tags($xml), '${signature}'))->toBe(1);
});

test('the builder offers Memo and Custom layouts only; an older Form or Report template keeps its own', function () {
    Livewire::test(TemplateBuilder::class)
        ->assertSee('Memo style')->assertSee('Blank: start from an empty page')
        ->assertDontSee('Form style: a table of questions and answers')->assertDontSee('Report style: details at the top');

    // A template made earlier in Report style still shows (and keeps) its style.
    Livewire::test(TemplateBuilder::class)->set('name', 'Old report')->set('design.layout', 'report')->call('save')->assertHasNoErrors();
    $old = PdtsDocumentTemplate::sole();
    expect($old->design['layout'])->toBe('report');
    Livewire::test(TemplateBuilder::class, ['template' => $old])->assertSee('Report style: details at the top')->assertDontSee('Form style: a table of questions and answers');
});

test('the template list menu is short: View, the form page, one download, nothing repeated', function () {
    buildLeaveRequest()->call('save')->assertHasNoErrors();
    $built = PdtsDocumentTemplate::sole();
    $uploaded = pdtsMemoTemplate();
    $uploaded->forceFill(['original_path' => $uploaded->storage_path, 'configured_at' => now()])->save();

    $html = Livewire::test(DocumentTemplates::class)->html();
    $row = fn (PdtsDocumentTemplate $t) => Str::betweenFirst($html, 'wire:key="template-'.$t->id.'"', '</tr>');

    // View for a quick look, the review page for the form; one download, not the working copy.
    $viewLink = 'href="'.route('pdts.organization.document-templates.view', $uploaded).'"';
    // A built template's QR code is placed in the builder: no Move QR code for it.
    expect($row($built))->toContain('>View</a>')->toContain('Edit in the builder')->toContain('Check and try it')
        ->not->toContain('Move QR code')->not->toContain('Rename or')->not->toContain('Download the Word file')
        ->and($row($uploaded))->toContain($viewLink)->toContain('Move QR code')->toContain('Form settings and try it')->toContain('Rename or replace the file')
        ->toContain('Download the Word file')->toContain(route('pdts.organization.document-templates.original', $uploaded))
        ->not->toContain(route('pdts.organization.document-templates.download', $uploaded).'"')
        ->not->toContain('Edit in the builder')->not->toContain('target="_blank"');

    // Nothing leads a built template to the QR code page; opened anyway, it goes to the builder.
    $qrLink = 'href="'.route('pdts.organization.document-templates.qr', $built).'"';
    expect(Livewire::test(TemplateReview::class, ['template' => $built])->html())->not->toContain($qrLink)->not->toContain('Move the QR code');
    $this->get(route('pdts.organization.document-templates.qr', $built))->assertRedirect(route('pdts.organization.document-templates.edit-design', $built));
    $this->get(route('pdts.organization.document-templates.edit-design', $built))->assertSeeHtml('data-test="builder-info"');
    Livewire::test(TemplateQrSetup::class, ['template' => $built])
        ->assertRedirect(route('pdts.organization.document-templates.edit-design', $built));
    // The QR code page: the QR code, and marking a line PDTS missed; no list of the lines found.
    $this->get(route('pdts.organization.document-templates.qr', $uploaded))->assertOk()->assertSeeHtml('data-test="mark-lines"')
        ->assertSee('Fix what PDTS missed')->assertDontSeeHtml('data-test="found-lines"');
    $this->get(route('pdts.organization.document-templates.view', $built))->assertOk()->assertSee('Leave Request');

    // Once documents are made from it, a new file becomes a new version.
    PdtsDocument::factory()->create(['template_id' => $uploaded->id]);
    $html = Livewire::test(DocumentTemplates::class)->html();
    expect(Str::betweenFirst($html, 'wire:key="template-'.$uploaded->id.'"', '</tr>'))->toContain('Rename or upload a new version');
});

test('the template list is short: no field and QR column, and what still needs doing is one click away', function () {
    buildLeaveRequest()->call('save')->assertHasNoErrors();
    $ready = PdtsDocumentTemplate::sole();
    $unsigned = TemplateDesigner::starter();
    array_pop($unsigned['items']);
    $notReady = app(TemplateDesigner::class)->save(null, ['name' => 'Notice draft', 'description' => null, 'document_type_id' => null], $unsigned, null);
    $notReady->forceFill(['is_unsigned' => false, 'is_active' => false])->save();   // as if "not signed" was undone

    Livewire::test(DocumentTemplates::class)
        ->assertDontSee('Form asks for')->assertDontSee('(extra field)')
        ->assertSee('Needs a signature spot')->assertSeeHtml(route('pdts.organization.document-templates.review', $notReady))
        ->assertSeeHtml('data-test="templates-help"')->assertDontSee('How templates work');

    $this->get(route('pdts.organization.document-templates.guide'))->assertSeeHtml('data-test="guide-advanced"')->assertSee('${amount}', false);
});

test('a built template is saved exactly as designed: no writing space between two sections, no field from text in brackets', function () {
    $builder = Livewire::test(TemplateBuilder::class)->set('name', 'Notice')->set('design.layout', 'report')
        ->call('remove', 4)                                                      // the starter's body
        ->call('addItem', 'section', null, 4)->set('design.items.4.label', 'Introduction')
        ->call('addItem', 'section', null, 5)->set('design.items.5.label', 'Activities')
        ->call('addItem', 'text', null, 6)->set('design.items.6.text', '[CONFIDENTIAL] Printed on every copy')
        ->call('addItem', 'question', 'long', 7)->set('design.items.7.label', 'Work done');

    $builder->call('save')->assertHasNoErrors();
    $template = PdtsDocumentTemplate::sole();
    $text = strip_tags(pdtsZipPart(Storage::disk('private')->get($template->storage_path), 'word/document.xml'));

    expect($template->placeholderNames())->toContain('text_work_done')
        ->not->toContain('text_introduction')->not->toContain('confidential')
        ->and($text)->toContain('[CONFIDENTIAL] Printed on every copy');
});

test('a page break after the body does not get a second body, and a sign line\'s label stays with its line', function () {
    $builder = Livewire::test(TemplateBuilder::class)->set('name', 'Notice')
        ->call('addItem', 'pagebreak', null, 5)                                 // after the starter's body
        ->call('addItem', 'signline', null, 6)->set('design.items.6.label', 'Approved by');
    $builder->call('save')->assertHasNoErrors();
    $template = PdtsDocumentTemplate::sole();
    $xml = pdtsZipPart(Storage::disk('private')->get($template->storage_path), 'word/document.xml');

    expect(substr_count(strip_tags($xml), '${text_body}'))->toBe(1)
        ->and($xml)->toMatch('#<w:p><w:pPr><w:keepNext/>(?:(?!</w:p>).)*Approved by#s')
        ->and($xml)->toMatch('#<w:p><w:pPr><w:keepNext/>(?:(?!</w:p>).)*______________________________#s');
});

test('removing a row with items in it asks in PDTS\'s own box, never the browser\'s pop-up', function () {
    // Every page carries the box: it asks for every wire:confirm, catching the click on the window first.
    $page = $this->get(route('pdts.organization.document-templates.build'))->assertOk()->getContent();
    expect($page)->toContain('id="pdts-confirm"')->toContain('window.pdtsConfirm = (text, onYes)')->toContain("window.addEventListener('click'");

    // The builder's Blank layout: a row with an item asks through the box; an empty row goes at once.
    $builder = Livewire::test(TemplateBuilder::class)->set('design.layout', 'custom');
    expect($builder->html())->not->toContain('window.pdtsConfirm(');         // empty rows: removed at once, nothing to ask

    $html = $builder->call('addItemTo', 'question', 'text', 0, 0, 0)->html();
    expect($html)->toContain('window.pdtsConfirm(')->toContain('Remove row 1 and the items in it?')
        ->not->toContain('wire:confirm="Remove row');

    // "Yes" removes the row with its item.
    $rows = count($builder->get('design.rows'));
    $builder->call('removeRow', 0);
    expect(count($builder->get('design.rows')))->toBe($rows - 1);
});
