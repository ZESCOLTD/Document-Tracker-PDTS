<?php

use App\Livewire\Pdts\Documents\InternalDocument;
use App\Livewire\Pdts\Organization\DocumentTemplates;
use App\Livewire\Pdts\Organization\TemplateQrSetup;
use App\Livewire\Pdts\Organization\TemplateView;
use App\Models\PdtsDepartment;
use App\Models\PdtsDirectorate;
use App\Models\PdtsDocument;
use App\Models\PdtsDocumentTemplate;
use App\Models\PdtsDocumentType;
use App\Models\PdtsOffice;
use App\Models\User;
use App\Services\Pdts\InternalDocuments;
use App\Services\Pdts\Templates\PdfQrStamper;
use App\Services\Pdts\Templates\QrCodePng;
use App\Services\Pdts\Templates\TemplateSetup;
use Database\Seeders\PdtsDocumentTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/../Support/docx.php';
require_once __DIR__.'/../Support/pdf.php';


beforeEach(function () {
    Storage::fake('private');

    foreach (['documents.register', 'documents.view', 'documents.forward', 'documents.acknowledge', 'document_types.manage'] as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $this->author = User::factory()->create(['man_no' => 'TPL-0001', 'name' => 'Racheal Inonge Zekko']);
    $this->author->givePermissionTo(['documents.register', 'documents.view', 'documents.forward', 'document_types.manage']);
    $this->actingAs($this->author);

    $this->type = PdtsDocumentType::create(['name' => 'Payment Request', 'is_active' => true]);
    $this->directorate = PdtsDirectorate::create(['name' => 'Investments & Finance', 'is_active' => true]);
    $this->department = PdtsDepartment::create(['name' => 'Finance', 'directorate_id' => $this->directorate->id, 'is_active' => true]);
    $this->office = PdtsOffice::create(['name' => 'Finance Head Office', 'department_id' => $this->department->id, 'is_active' => true]);
});

/**
 * Adds a template as an administrator does: upload on the Configurations page
 * (which opens the QR setup), then save the QR placement.
 */
function uploadTemplate(string $name, string $bodyRuns, ?array $placement = null, ?string $footer = null): PdtsDocumentTemplate
{
    Livewire::test(DocumentTemplates::class)
        ->call('openCreate')
        ->set('name', $name)
        ->set('file', UploadedFile::fake()->createWithContent(str($name)->slug().'.docx', $footer === null ? pdtsTestDocx($bodyRuns) : pdtsTestDocx($bodyRuns, $footer)))
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $template = PdtsDocumentTemplate::where('name', $name)->sole();

    Livewire::test(TemplateQrSetup::class, ['template' => $template])
        ->call('save', $placement ?? ['page' => 'all', 'x' => 170, 'y' => 10, 'size' => 22])
        ->assertHasNoErrors()
        ->assertRedirect(route('pdts.organization.document-templates'));

    return $template->fresh();
}

test('a saved template records the placeholders and the form fields it asks for', function () {
    $template = uploadTemplate('Payment Request', '<w:r><w:t>${to} ${subject} ${amount} ${due_date}</w:t></w:r>');

    expect($template->placeholders)->toEqualCanonicalizing(['to', 'subject', 'amount', 'due_date', 'qr_code', 'reference'])
        ->and($template->customFields())->toBe(['amount' => 'Amount', 'due_date' => 'Due date']);

    Livewire::test(DocumentTemplates::class)->assertSee('Subject, To, Amount (extra field), Due date (extra field)');
});

test('the form shows only the fields the chosen template uses, plus its own fields', function () {
    $template = uploadTemplate('Payment Request', '<w:r><w:t>${to} ${subject} ${amount} ${due_date}</w:t></w:r>');

    Livewire::test(InternalDocument::class)
        ->set('template_id', (string) $template->id)
        ->assertSee('Details in this template')
        ->assertSeeHtml('id="internal-to"')
        ->assertDontSeeHtml('id="internal-from"')
        ->assertDontSeeHtml('id="internal-cc"')
        ->assertDontSeeHtml('id="internal-office-ref"')
        ->assertDontSeeHtml('id="internal-date"')
        ->assertSeeHtml('id="custom-amount"')
        ->assertSeeHtml('id="custom-due_date"')
        ->assertSet('custom', ['amount' => '', 'due_date' => '']);
});

test('a memorandum template with every heading line shows all heading fields and no extra ones', function () {
    $memo = pdtsMemoTemplate($this->type->id);

    Livewire::test(InternalDocument::class)
        ->set('template_id', (string) $memo->id)
        ->assertSeeHtml('id="internal-to"')
        ->assertSeeHtml('id="internal-from"')
        ->assertSeeHtml('id="internal-cc"')
        ->assertSeeHtml('id="internal-office-ref"')
        ->assertSeeHtml('id="internal-date"')
        ->assertDontSeeHtml('id="custom-');
});

test('before a template is chosen the form asks for one first', function () {
    uploadTemplate('Payment Request', '<w:r><w:t>${amount}</w:t></w:r>');

    Livewire::test(InternalDocument::class)
        ->set('template_id', '')
        ->assertSeeHtml('data-test="choose-template-first"')
        ->assertDontSeeHtml('id="internal-to"');
});

test('fields the template does not use are not required', function () {
    $template = uploadTemplate('Payment Request', '<w:r><w:t>${subject} ${amount}</w:t></w:r>');

    Livewire::test(InternalDocument::class)
        ->set('template_id', (string) $template->id)
        ->set('document_type_id', (string) $this->type->id)
        ->set('from', '')
        ->set('memo_date', '')
        ->set('subject', 'Payment for ICT equipment')
        ->set('custom.amount', 'K 12,000')
        ->set('directorate_id', (string) $this->directorate->id)
        ->set('department_id', (string) $this->department->id)
        ->set('office_id', (string) $this->office->id)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $document = PdtsDocument::query()->sole();
    expect($document->template_data['custom'])->toBe(['amount' => 'K 12,000'])
        ->and($document->template_data['to'])->toBe('')
        ->and($document->template_data['from'])->toBe('');
});

test('a field the template uses is still required', function () {
    $template = uploadTemplate('Letter', '<w:r><w:t>${to} ${subject}</w:t></w:r>');

    Livewire::test(InternalDocument::class)
        ->set('template_id', (string) $template->id)
        ->set('document_type_id', (string) $this->type->id)
        ->set('to', '')
        ->set('subject', 'Tender clarification')
        ->set('directorate_id', (string) $this->directorate->id)
        ->set('department_id', (string) $this->department->id)
        ->set('office_id', (string) $this->office->id)
        ->call('save')
        ->assertHasErrors(['to' => 'required']);
});

test('the extra field values are filled into the Word file', function () {
    $template = uploadTemplate('Payment Request', '<w:r><w:t>Amount: ${amount}. Due: ${due_date}. Ref ${reference}.</w:t></w:r>');

    Livewire::test(InternalDocument::class)
        ->set('template_id', (string) $template->id)
        ->set('document_type_id', (string) $this->type->id)
        ->set('subject', 'Payment for ICT equipment')
        ->set('custom.amount', 'K 12,000')
        ->set('custom.due_date', '30 November 2026')
        ->set('directorate_id', (string) $this->directorate->id)
        ->set('department_id', (string) $this->department->id)
        ->set('office_id', (string) $this->office->id)
        ->call('save')
        ->assertHasNoErrors();

    $document = PdtsDocument::query()->sole();
    $body = pdtsZipPart(app(InternalDocuments::class)->wordFile($document)['contents'], 'word/document.xml');

    expect($body)->toContain('Amount: K 12,000. Due: 30 November 2026. Ref '.$document->reference_number.'.')
        ->not->toContain('${amount}')
        ->not->toContain('${due_date}');
});

test('switching template keeps shared extra fields and drops the others', function () {
    $payment = uploadTemplate('Payment Request', '<w:r><w:t>${amount} ${due_date}</w:t></w:r>');
    $quote = uploadTemplate('Quotation', '<w:r><w:t>${amount} ${supplier}</w:t></w:r>');

    Livewire::test(InternalDocument::class)
        ->set('template_id', (string) $payment->id)
        ->set('custom.amount', 'K 500')
        ->set('custom.due_date', 'Friday')
        ->set('template_id', (string) $quote->id)
        ->assertSet('custom', ['amount' => 'K 500', 'supplier' => ''])
        ->assertSee('Details in this template')
        ->assertSeeHtml('id="custom-supplier"')
        ->assertDontSeeHtml('id="custom-due_date"');
});

test('an extra field cannot overwrite what PDTS fills itself', function () {
    $template = uploadTemplate('Payment Request', '<w:r><w:t>${reference}</w:t></w:r>');
    $document = PdtsDocument::factory()->create([
        'origin' => 'internal',
        'template_id' => $template->id,
        'template_data' => ['custom' => ['reference' => 'FAKE-REF', 'amount' => 'K 1']],
    ]);

    $values = app(InternalDocuments::class)->placeholderValues($document);

    expect($values['reference'])->toBe($document->reference_number)
        ->and($values['amount'])->toBe('K 1');
});

test('older uploads without recorded placeholders are read from their file once', function () {
    $template = uploadTemplate('Payment Request', '<w:r><w:t>${from} ${amount}</w:t></w:r>');
    $template->forceFill(['placeholders' => null])->saveQuietly();

    $fresh = PdtsDocumentTemplate::find($template->id);

    expect($fresh->usesField('from'))->toBeTrue()
        ->and($fresh->customFields())->toBe(['amount' => 'Amount'])
        ->and(PdtsDocumentTemplate::find($template->id)->placeholders)->toEqualCanonicalizing(['from', 'amount', 'qr_code', 'reference']);
});

/** A ZESCO-style memo as offices already have it: labels with blank lines, no ${placeholders}. */
function zescoMemoBody(): string
{
    $b = fn ($t) => '<w:r><w:rPr><w:b/></w:rPr><w:t xml:space="preserve">'.$t.'</w:t></w:r>';
    $n = fn ($t) => '<w:r><w:t xml:space="preserve">'.$t.'</w:t></w:r>';
    $tab = '<w:r><w:tab/></w:r>';

    return $b('TO:').$tab.$n('______________').$tab.$b('FROM:').$tab.$n('..............')
        .'</w:p><w:p>'.$b('CC:').$n(' ')
        .'</w:p><w:p>'.$b('REF:').$n(' DIF/DDAS/054/2025')
        .'</w:p><w:p>'.$b('DATE:').$n(' [Insert date]')
        .'</w:p><w:p>'.$b('RE:').$n(' ____________')
        .'</w:p><w:p>'.$n('Please go to: the registry');
}

function uploadPlainTemplate(string $name, array $placement, ?string $footer = null): PdtsDocumentTemplate
{
    return uploadTemplate($name, zescoMemoBody(), $placement, $footer ?? '<w:r><w:t>ZESCO Limited, Stand No. 6949</w:t></w:r>');
}

function storedPart(PdtsDocumentTemplate $template, string $part): string|false
{
    return pdtsZipPart(Storage::disk('private')->get($template->storage_path), $part);
}

test('uploading keeps the original as it is and opens the QR setup; the template waits until the QR code is placed', function () {
    $bytes = pdtsTestDocx(zescoMemoBody(), '<w:r><w:t>Footer</w:t></w:r>');

    Livewire::test(DocumentTemplates::class)
        ->call('openCreate')
        ->set('file', UploadedFile::fake()->createWithContent('Internal_Memorandum.docx', $bytes))
        ->assertSet('name', 'Internal Memorandum')
        ->set('description', 'Standard memo')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('pdts.organization.document-templates.qr', PdtsDocumentTemplate::sole()));

    $template = PdtsDocumentTemplate::sole();
    expect($template->kind)->toBe('docx')
        ->and($template->description)->toBe('Standard memo')
        ->and(Storage::disk('private')->get($template->original_path))->toBe($bytes)
        ->and($template->isConfigured())->toBeFalse()
        ->and($template->is_active)->toBeFalse();

    Livewire::test(DocumentTemplates::class)->assertSee('Needs QR code')->assertSee('Place the QR code');
    Livewire::test(InternalDocument::class)->assertDontSee('Internal Memorandum');
});

test('the QR setup page shows the original, the page size and what PDTS found', function () {
    Livewire::test(DocumentTemplates::class)
        ->call('openCreate')
        ->set('file', UploadedFile::fake()->createWithContent('memo.docx', pdtsTestDocx(zescoMemoBody(), '<w:r><w:t>Footer</w:t></w:r>')))
        ->call('save');
    $template = PdtsDocumentTemplate::sole();

    $this->get(route('pdts.organization.document-templates.qr', $template))
        ->assertOk()
        ->assertSee('Place the QR code')
        ->assertSee('vendor/docx-preview/docx-preview.min.js', false)
        ->assertSee('pdtsQrEditor(', false); // the preview and drag editor

    Livewire::test(TemplateQrSetup::class, ['template' => $template])
        ->assertSet('pages', [['width' => 210.0, 'height' => 297.0]])
        ->assertSet('fields', ['from', 'to', 'cc', 'date', 'subject'])
        ->assertSet('placement', ['page' => 'first', 'x' => 178.0, 'y' => 10.0, 'size' => 22.0]);

    $this->get(route('pdts.organization.document-templates.original', $template))->assertOk();
});

test('saving pins the QR code where it was placed and makes the template available', function () {
    $template = uploadPlainTemplate('ZESCO Letter', ['page' => 'all', 'x' => 170, 'y' => 12, 'size' => 22]);

    expect($template->placeholders)->toEqualCanonicalizing(['to', 'from', 'cc', 'date', 'subject', 'reference', 'qr_code'])
        ->and($template->placement())->toBe(['page' => 'all', 'x' => 170.0, 'y' => 12.0, 'size' => 22.0])
        ->and($template->isConfigured())->toBeTrue()
        ->and($template->is_active)->toBeTrue()
        ->and($template->storage_path)->not->toBe($template->original_path)
        ->and(storedPart($template, 'word/header1.xml'))
        ->toContain('<wp:anchor')
        ->toContain('<wp:posOffset>6048000</wp:posOffset>') // 170 mm, less half the extra box width (2 mm)
        ->toContain('<wp:posOffset>432000</wp:posOffset>') // 12 mm
        ->toContain('<wp:wrapNone/>')
        ->toContain('${qr_code}')
        ->toContain('${reference}')
        ->and(storedPart($template, 'word/document.xml'))
        ->toContain('DIF/DDAS/054/2025') // REF: already had text, left alone
        ->toContain('Please go to: the registry') // "to:" inside a sentence is not a label
        ->not->toContain('______________')
        ->not->toContain('[Insert date]');

    Livewire::test(DocumentTemplates::class)
        ->assertSee('Subject, To, From, CC, Date')
        ->assertSee('Every page · 22 mm, 170 mm from left, 12 mm from top');
});

test('first page only gives page 1 its own header, keeping a copy of the footer', function () {
    $template = uploadPlainTemplate('First page memo', ['page' => 'first', 'x' => 15, 'y' => 250, 'size' => 30]);

    $document = storedPart($template, 'word/document.xml');
    expect($document)->toContain('<w:titlePg/>')
        ->toContain('w:headerReference w:type="first"')
        ->toContain('w:footerReference w:type="first"')
        ->and(storedPart($template, 'word/header1.xml'))->toContain('<wp:anchor')
        ->and(storedPart($template, 'word/footer2.xml'))->toContain('ZESCO Limited, Stand No. 6949')
        ->and($template->qrLabel())->toBe('First page · 30 mm, 15 mm from left, 250 mm from top');
});

test('a file PDTS prepared before can still have its QR code moved; the old spot is removed', function () {
    // The line PDTS itself used to add, now in a file uploaded again.
    $oldLine = '<w:r><w:t>PDTS: </w:t></w:r><w:r><w:t>${reference}</w:t></w:r><w:r><w:t xml:space="preserve">  </w:t></w:r><w:r><w:t>${qr_code}</w:t></w:r>';

    Livewire::test(DocumentTemplates::class)
        ->call('openCreate')
        ->set('name', 'Prepared before')
        ->set('file', UploadedFile::fake()->createWithContent('memo.docx', pdtsTestDocx(zescoMemoBody().'</w:p><w:p>'.$oldLine, '<w:r><w:t>ZESCO footer</w:t></w:r>')))
        ->call('save');
    $template = PdtsDocumentTemplate::sole();

    Livewire::test(TemplateQrSetup::class, ['template' => $template])
        ->assertSet('qrTyped', true)
        ->assertSee('the old spot is removed')
        ->call('save', ['page' => 'all', 'x' => 20, 'y' => 15, 'size' => 25])
        ->assertHasNoErrors();

    $template->refresh();
    $body = storedPart($template, 'word/document.xml');
    expect($template->placement())->toBe(['page' => 'all', 'x' => 20.0, 'y' => 15.0, 'size' => 25.0])
        ->and($body)->not->toContain('${qr_code}')->not->toContain('PDTS: ')
        ->and(storedPart($template, 'word/header1.xml'))->toContain('<wp:anchor')->toContain('${qr_code}');
});

test('a ${qr_code} typed inside other text is taken out and the QR code pinned where placed', function () {
    $template = uploadPlainTemplate('Typed QR', ['page' => 'all', 'x' => 10, 'y' => 10, 'size' => 25], '<w:r><w:t>ZESCO footer ${qr_code}</w:t></w:r>');

    expect($template->placement())->toBe(['page' => 'all', 'x' => 10.0, 'y' => 10.0, 'size' => 25.0])
        ->and(storedPart($template, 'word/footer1.xml'))->toContain('ZESCO footer')->not->toContain('${qr_code}')
        ->and(substr_count(storedPart($template, 'word/header1.xml'), '${qr_code}'))->toBe(1);
});

test('a placement outside the page is pulled back in, and sizes are limited', function () {
    $template = uploadPlainTemplate('Edge memo', ['page' => 'all', 'x' => 500, 'y' => -20, 'size' => 22]);
    expect($template->placement())->toBe(['page' => 'all', 'x' => 188.0, 'y' => 0.0, 'size' => 22.0]);

    Livewire::test(TemplateQrSetup::class, ['template' => $template->fresh()])
        ->call('save', ['page' => 'all', 'x' => 10, 'y' => 10, 'size' => 80])
        ->assertHasErrors('placement');
    Livewire::test(TemplateQrSetup::class, ['template' => $template->fresh()])
        ->call('save', ['page' => '3', 'x' => 10, 'y' => 10, 'size' => 22])
        ->assertHasErrors('placement'); // Word templates: every page or first page only
});

test('a sample with a sample QR code can be downloaded before saving', function () {
    Livewire::test(DocumentTemplates::class)
        ->call('openCreate')
        ->set('file', UploadedFile::fake()->createWithContent('memo.docx', pdtsTestDocx(zescoMemoBody(), '<w:r><w:t>Footer</w:t></w:r>')))
        ->call('save');
    $template = PdtsDocumentTemplate::sole();

    Livewire::test(TemplateQrSetup::class, ['template' => $template])
        ->call('downloadSample', ['page' => 'all', 'x' => 170, 'y' => 10, 'size' => 22])
        ->assertFileDownloaded('Sample - memo.docx');

    expect($template->fresh()->isConfigured())->toBeFalse(); // nothing saved
});

test('a document made from an uploaded ZESCO template is filled in its own layout with the QR code', function () {
    $template = uploadPlainTemplate('ZESCO Letter', ['page' => 'all', 'x' => 170, 'y' => 10, 'size' => 25]);

    Livewire::test(InternalDocument::class)
        ->set('template_id', (string) $template->id)
        ->assertSeeHtml('id="internal-to"')
        ->assertSeeHtml('id="internal-from"')
        ->assertDontSeeHtml('id="internal-office-ref"') // REF already printed in the template
        ->set('document_type_id', (string) $this->type->id)
        ->set('to', 'Director - Corporate Affairs')
        ->set('from', 'Senior Manager ICT')
        ->set('cc', 'Managing Director')
        ->set('memo_date', '2026-11-20')
        ->set('subject', 'Procurement of ICT')
        ->set('directorate_id', (string) $this->directorate->id)
        ->set('department_id', (string) $this->department->id)
        ->set('office_id', (string) $this->office->id)
        ->call('save')
        ->assertHasNoErrors();

    $document = PdtsDocument::query()->sole();
    $word = app(InternalDocuments::class)->wordFile($document)['contents'];
    // Word tabs read as a space, so "TO:<tab>value" reads "TO: value".
    $body = preg_replace('/ +/', ' ', strip_tags(str_replace(['<w:tab/>', '</w:p>'], [' ', "\n"], pdtsZipPart($word, 'word/document.xml'))));
    $header = pdtsZipPart($word, 'word/header1.xml');

    expect($body)->toContain('TO: Director - Corporate Affairs')
        ->toContain('FROM: Senior Manager ICT')
        ->toContain('CC: Managing Director')
        ->toContain('DATE: 20 November 2026')
        ->toContain('RE: Procurement of ICT')
        ->toContain('DIF/DDAS/054/2025')
        ->and($header)
        ->toContain('r:embed="rIdPdtsQr"')
        ->toContain('<wp:extent cx="900000" cy="900000"/>') // the QR picture at 25 mm
        ->toContain($document->reference_number)
        ->toContain('<wps:txbx>') // still inside its pinned box
        ->not->toContain('${qr_code}')
        ->and(pdtsZipPart($word, 'word/footer1.xml'))->toContain('ZESCO Limited, Stand No. 6949');
});

test('a PDF template is kept as it is and its QR code is stamped on the chosen page of each document', function () {
    $pdf = pdtsTestPdfModern();

    Livewire::test(DocumentTemplates::class)
        ->call('openCreate')
        ->set('name', 'Leave Form')
        ->set('file', UploadedFile::fake()->createWithContent('leave-form.pdf', $pdf))
        ->call('save')
        ->assertHasNoErrors();
    $template = PdtsDocumentTemplate::sole();

    expect($template->kind)->toBe('pdf')
        ->and($template->page_count)->toBe(2)
        ->and(Storage::disk('private')->get($template->original_path))->toBe($pdf);

    $this->get(route('pdts.organization.document-templates.qr', $template))
        ->assertOk()->assertSee('vendor/pdfjs/pdf.min.js', false)->assertSee('or one page:');

    Livewire::test(TemplateQrSetup::class, ['template' => $template])
        ->assertSet('pages', [['width' => 210.0, 'height' => 297.0], ['width' => 297.0, 'height' => 210.0]])
        ->call('downloadSample', ['page' => '2', 'x' => 250, 'y' => 10, 'size' => 25])
        ->assertFileDownloaded('Sample - Leave Form.pdf')
        ->call('save', ['page' => '2', 'x' => 250, 'y' => 10, 'size' => 25])
        ->assertHasNoErrors();

    $template->refresh();
    expect($template->placement())->toBe(['page' => '2', 'x' => 250.0, 'y' => 10.0, 'size' => 25.0])
        ->and($template->storage_path)->toBe($template->original_path)
        ->and($template->is_active)->toBeTrue();

    Livewire::test(InternalDocument::class)
        ->set('template_id', (string) $template->id)
        ->assertDontSeeHtml('id="internal-to"')
        ->set('document_type_id', (string) $this->type->id)
        ->set('subject', 'Annual leave')
        ->set('directorate_id', (string) $this->directorate->id)
        ->set('department_id', (string) $this->department->id)
        ->set('office_id', (string) $this->office->id)
        ->call('save')
        ->assertHasNoErrors();

    $document = PdtsDocument::query()->sole();
    $response = $this->get(route('pdts.documents.word', $document))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
    $stamped = $response->getContent();

    expect($stamped)->toStartWith($pdf) // the original bytes are untouched
        ->and(strlen($stamped))->toBeGreaterThan(strlen($pdf))
        ->and(app(PdfQrStamper::class)->pages($stamped))->toHaveCount(2)
        ->and(substr_count(substr($stamped, strlen($pdf)), ' re'))->toBeGreaterThan(50); // QR squares drawn
});

test('files other than Word or PDF, or unreadable ones, are refused', function () {
    Livewire::test(DocumentTemplates::class)
        ->call('openCreate')
        ->set('file', UploadedFile::fake()->create('notes.txt', 1, 'text/plain'))
        ->assertHasErrors('file')
        ->assertSet('file', null);

    Livewire::test(DocumentTemplates::class)
        ->call('openCreate')
        ->set('name', 'Broken')
        ->set('file', UploadedFile::fake()->createWithContent('broken.pdf', '%PDF-1.4 not really a pdf'))
        ->call('save')
        ->assertHasErrors('file');

    expect(PdtsDocumentTemplate::count())->toBe(0);
});

test('a template documents were made from cannot have its QR code moved', function () {
    $template = uploadPlainTemplate('Used letter', ['page' => 'all', 'x' => 170, 'y' => 10, 'size' => 22]);
    PdtsDocument::factory()->create(['origin' => 'internal', 'template_id' => $template->id]);

    Livewire::test(TemplateQrSetup::class, ['template' => $template])
        ->assertSee('can no longer be moved')
        ->call('save', ['page' => 'first', 'x' => 10, 'y' => 10, 'size' => 22])
        ->assertForbidden();
});

test('any template can be deleted; documents made from it keep their details', function () {
    $unused = uploadTemplate('Unused', '<w:r><w:t>${subject}</w:t></w:r>');
    $used = pdtsMemoTemplate($this->type->id, 'Used memo');
    $document = PdtsDocument::factory()->create(['origin' => 'internal', 'template_id' => $used->id, 'registered_by' => $this->author->id, 'current_custodian_id' => $this->author->id]);

    Livewire::test(DocumentTemplates::class)
        ->assertSee('made from it will keep their details')
        ->call('delete', $unused->id)
        ->call('delete', $used->id);

    expect(PdtsDocumentTemplate::count())->toBe(0)
        ->and($document->fresh())->not->toBeNull()
        ->and($document->fresh()->template_id)->toBeNull();
    Storage::disk('private')->assertMissing($used->storage_path);
    Storage::disk('private')->assertMissing($unused->storage_path);

    $this->get(route('pdts.documents.word', $document))->assertNotFound();
});

test('document types offered are only those of the uploaded templates, and a template with a type sets it', function () {
    $letterType = PdtsDocumentType::create(['name' => 'Official Letter', 'is_active' => true]);
    PdtsDocumentType::create(['name' => 'Contract', 'is_active' => true]); // no template uses it
    $memoType = PdtsDocumentType::create(['name' => 'Memorandum', 'is_active' => true]);

    $memo = pdtsMemoTemplate($memoType->id, 'ZESCO Memorandum');
    $letter = pdtsMemoTemplate($letterType->id, 'ZESCO Letter');
    $open = pdtsMemoTemplate(null, 'Any type');

    $form = Livewire::test(InternalDocument::class)->set('template_id', '');
    expect($form->viewData('documentTypes')->pluck('name')->all())->toBe(['Memorandum', 'Official Letter']);

    $form->set('template_id', (string) $letter->id)
        ->assertSet('document_type_id', (string) $letterType->id)
        ->assertSee('Set by the template');
    expect($form->viewData('documentTypes')->pluck('name')->all())->toBe(['Official Letter']);

    // A template without a type: choose from the uploaded templates' types only.
    $form->set('template_id', (string) $open->id)
        ->assertSet('document_type_id', (string) $letterType->id) // still a valid choice, so kept
        ->set('document_type_id', (string) PdtsDocumentType::where('name', 'Contract')->value('id'))
        ->set('subject', 'Test')
        ->set('to', 'X')->set('from', 'Y')->set('memo_date', '2026-11-21')
        ->set('directorate_id', (string) $this->directorate->id)
        ->set('department_id', (string) $this->department->id)
        ->set('office_id', (string) $this->office->id)
        ->call('save')
        ->assertHasErrors('document_type_id');
    expect($form->viewData('documentTypes')->pluck('name')->all())->toBe(['Memorandum', 'Official Letter']);
});

test('any fill-in line in an uploaded file becomes a field, in document order, and signature lines are left alone', function () {
    $b = fn ($t) => '<w:r><w:rPr><w:b/></w:rPr><w:t xml:space="preserve">'.$t.'</w:t></w:r>';
    $n = fn ($t) => '<w:r><w:t xml:space="preserve">'.$t.'</w:t></w:r>';
    $letter = $b('Your Ref:').$n(' ........').'</w:p><w:p>'
        .$b('Date:').$n(' ________').'</w:p><w:p>'
        .$b('Attention:').$n(' [Name of officer]').'</w:p><w:p>'
        .$b('RE:').$n(' ________').'</w:p><w:p>'
        .$n('We refer to your letter dated 3 March: please see below.').'</w:p><w:p>'
        .$b('Amount:').'<w:r><w:tab/></w:r>'.$n('K ____________').'</w:p><w:p>'
        .$b('Signature:').$n(' ______________').'</w:p><w:p>'
        .$b('Designation:').$n(' ______________');

    $template = uploadTemplate('Official Letter', $letter, null, '<w:r><w:t>ZESCO footer</w:t></w:r>');

    expect($template->placeholders)->toBe(['your_ref', 'date', 'attention', 'subject', 'designation', 'qr_code', 'reference'])
        ->and(storedPart($template, 'word/document.xml'))
        ->toContain('Signature:') // signed by hand: left as it is
        ->toContain('______________')
        ->toContain('We refer to your letter dated 3 March: please see below.')
        ->toContain('K ____________'); // "Amount:" already had text ("K"), so it is left alone

    $form = Livewire::test(InternalDocument::class)->set('template_id', (string) $template->id);
    expect(collect($form->viewData('formFields'))->pluck('label')->all())->toBe(['Your Ref', 'Date', 'Attention', 'Designation']);
    $form->assertDontSeeHtml('id="internal-to"')
        ->assertSeeHtml('id="custom-your_ref"')
        ->assertSeeHtml('id="custom-attention"')
        ->assertSee('Details in this template');
});

test('a field left empty keeps a line to write on by hand', function () {
    $template = uploadTemplate('Form', '<w:r><w:t xml:space="preserve">Name: ______</w:t></w:r></w:p><w:p><w:r><w:t xml:space="preserve">Account No: ______</w:t></w:r>');

    Livewire::test(InternalDocument::class)
        ->set('template_id', (string) $template->id)
        ->set('document_type_id', (string) $this->type->id)
        ->set('subject', 'Account opening')
        ->set('custom.name', 'Benson Ngulube')
        ->set('directorate_id', (string) $this->directorate->id)
        ->set('department_id', (string) $this->department->id)
        ->set('office_id', (string) $this->office->id)
        ->call('save')
        ->assertHasNoErrors();

    $body = strip_tags(pdtsZipPart(app(InternalDocuments::class)->wordFile(PdtsDocument::sole())['contents'], 'word/document.xml'));

    expect($body)->toContain('Name: Benson Ngulube')->toContain('Account No: ____________________');
});

test('form labels keep the wording of the document', function () {
    $n = fn ($t) => '<w:r><w:t xml:space="preserve">'.$t.'</w:t></w:r>';
    $template = uploadTemplate('Report', $n('Prepared for: ______').'</w:p><w:p>'.$n('APPENDIX A: ______').'</w:p><w:p>'.$n('Report Number: ......'));

    expect($template->field_labels)->toBe(['prepared_for' => 'Prepared for', 'appendix_a' => 'Appendix A', 'report_number' => 'Report Number'])
        ->and(array_values($template->customFields()))->toBe(['Prepared for', 'Appendix A', 'Report Number']);

    // Templates saved before labels were kept still read well.
    $template->forceFill(['field_labels' => null])->save();
    expect(array_values($template->fresh()->customFields()))->toBe(['Prepared for', 'Appendix A', 'Report number']);

    Livewire::test(InternalDocument::class)
        ->set('template_id', (string) $template->id)
        ->assertSee('Appendix A')
        ->assertDontSeeHtml('col-md-3');
});

test('every document from the same template gets its own reference and its own QR code, and each scans to itself', function () {
    $template = uploadTemplate('Payment Request', '<w:r><w:t>To: ${to}</w:t></w:r>');
    $pdfTemplate = PdtsDocumentTemplate::create([
        'name' => 'Leave Form', 'kind' => 'pdf', 'document_type_id' => $this->type->id,
        'storage_path' => 'templates/leave.pdf', 'original_path' => 'templates/leave.pdf',
        'qr_page' => 'all', 'qr_x_mm' => 170, 'qr_y_mm' => 10, 'qr_size_mm' => 22,
        'configured_at' => now(), 'is_active' => true,
    ]);
    Storage::disk('private')->put('templates/leave.pdf', pdtsTestPdfModern());

    $make = function (PdtsDocumentTemplate $template, string $subject) {
        Livewire::test(InternalDocument::class)
            ->set('template_id', (string) $template->id)
            ->set('document_type_id', (string) $this->type->id)
            ->set('to', 'Director - Corporate Affairs')
            ->set('subject', $subject)
            ->set('directorate_id', (string) $this->directorate->id)
            ->set('department_id', (string) $this->department->id)
            ->set('office_id', (string) $this->office->id)
            ->call('save')
            ->assertHasNoErrors();

        return PdtsDocument::where('subject', $subject)->sole();
    };

    $documents = [$make($template, 'First'), $make($template, 'Second'), $make($pdfTemplate, 'Third'), $make($pdfTemplate, 'Fourth')];
    $files = array_map(fn ($d) => app(InternalDocuments::class)->wordFile($d)['contents'], $documents);

    expect(collect($documents)->pluck('reference_number')->unique())->toHaveCount(4)
        ->and(collect($documents)->pluck('scan_token')->unique())->toHaveCount(4)
        ->and(collect($files)->unique())->toHaveCount(4);

    // Word: the QR picture is exactly the code for that document's own scan link, with its reference beside it.
    foreach ([0, 1] as $i) {
        $xml = collect(['word/document.xml', 'word/header1.xml', 'word/footer1.xml'])
            ->map(fn ($part) => pdtsZipPart($files[$i], $part) ?? '')->implode('');
        expect(pdtsZipPart($files[$i], 'word/media/pdts-qr.png'))->toBe(app(QrCodePng::class)->make($documents[$i]->scanUrl()))
            ->and($xml)->toContain($documents[$i]->reference_number)
            ->and($xml)->not->toContain($documents[1 - $i]->reference_number);
    }

    // PDF: the stamp is the code for that document's own scan link, with its own reference printed under it.
    foreach ([2, 3] as $i) {
        expect($files[$i])->toBe(app(TemplateSetup::class)->stamp(pdtsTestPdfModern(), $pdfTemplate->placement(), $documents[$i]->scanUrl(), $documents[$i]->reference_number))
            ->and($files[$i])->toContain('('.$documents[$i]->reference_number.') Tj')
            ->and($files[$i])->not->toContain('('.$documents[5 - $i]->reference_number.') Tj')
            ->and($files[$i])->toContain('/BaseFont /Helvetica');
    }

    // Scanning each link opens that document and no other.
    foreach ($documents as $i => $document) {
        $this->get(parse_url($document->scanUrl(), PHP_URL_PATH))->assertOk()
            ->assertSee($document->reference_number)
            ->assertDontSee($documents[($i + 1) % 4]->reference_number);
    }
});

test('a template can be viewed with its QR code where it prints, without changing anything', function () {
    $template = uploadTemplate('Payment Request', '<w:r><w:t>To: ${to}</w:t></w:r>', ['page' => 'first', 'x' => 150, 'y' => 12, 'size' => 22]);

    Livewire::test(DocumentTemplates::class)
        ->assertSeeHtml('href="'.route('pdts.organization.document-templates.view', $template).'"');

    $this->get(route('pdts.organization.document-templates.view', $template))
        ->assertOk()
        ->assertSee('Payment Request')
        ->assertSee('First page · 22 mm, 150 mm from left, 12 mm from top')
        ->assertSee('Move QR code')
        ->assertSee('Download sample')
        ->assertDontSee('Save template')
        ->assertSee('viewOnly'.chr(92).'u0022:true', false) // read-only editor, JSON-escaped by @js
        ->assertSee('showPin'.chr(92).'u0022:true', false);

    Livewire::test(TemplateView::class, ['template' => $template])
        ->call('downloadSavedSample')
        ->assertFileDownloaded('Sample - Payment Request.docx');

    Livewire::test(TemplateView::class, ['template' => $template])
        ->call('save', ['page' => 'all', 'x' => 10, 'y' => 10, 'size' => 22])
        ->assertForbidden();
    expect($template->fresh()->qr_page)->toBe('first');

    // Without the permission to manage templates the page is closed.
    $other = User::factory()->create(['man_no' => 'TPL-0002']);
    $this->actingAs($other)->get(route('pdts.organization.document-templates.view', $template))->assertForbidden();
});

test('the template seeder installs the ZESCO templates ready to use, and running it again changes nothing', function () {
    $this->seed(PdtsDocumentTemplateSeeder::class);
    $this->seed(PdtsDocumentTemplateSeeder::class);

    $templates = PdtsDocumentTemplate::with('documentType')->orderBy('name')->get();

    expect($templates->pluck('name')->all())->toBe(['Internal Memorandum', 'Monthly Report Template', 'Petty Cash Voucher', 'Petty Cash VoucherP'])
        ->and($templates->every(fn ($t) => $t->is_active && $t->isConfigured() && $t->storage_path && Storage::disk('private')->exists($t->storage_path)))->toBeTrue()
        ->and($templates->firstWhere('name', 'Internal Memorandum')->documentType->name)->toBe('Memorandum')
        ->and($templates->firstWhere('name', 'Internal Memorandum')->placement())->toBe(['page' => 'first', 'x' => 10.0, 'y' => 10.0, 'size' => 16.0])
        ->and($templates->firstWhere('name', 'Petty Cash VoucherP')->kind)->toBe('pdf');

    // Authors can use them straight away.
    Livewire::test(InternalDocument::class)
        ->set('template_id', (string) $templates->firstWhere('name', 'Internal Memorandum')->id)
        ->assertSeeHtml('id="internal-to"');
});
