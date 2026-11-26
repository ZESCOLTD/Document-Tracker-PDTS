<?php

use App\Livewire\Pdts\Documents\InternalDocument;
use App\Livewire\Pdts\Organization\DocumentTemplates;
use App\Livewire\Pdts\Organization\TemplateQrSetup;
use App\Livewire\Pdts\Organization\TemplateReview;
use App\Livewire\Pdts\Organization\TemplateView;
use App\Models\PdtsDepartment;
use App\Models\PdtsDirectorate;
use App\Models\PdtsDocument;
use App\Models\PdtsDocumentTemplate;
use App\Models\PdtsDocumentType;
use App\Models\PdtsOffice;
use App\Models\User;
use App\Services\Pdts\InternalDocuments;
use App\Services\Pdts\Templates\DocxPackage;
use App\Services\Pdts\Templates\DocxTemplateFiller;
use App\Services\Pdts\Templates\PdfQrStamper;
use App\Services\Pdts\Templates\QrCodePng;
use App\Services\Pdts\Templates\TemplateDesigner;
use App\Services\Pdts\Templates\TemplatePreparer;
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
        ->assertRedirect(route('pdts.organization.document-templates.review', $template));

    // These sample forms have nowhere to sign: the administrator marks them as
    // not signed on the review page, which offers them to authors.
    $template = $template->fresh();
    if ($template->signingBlocker() !== null) {
        app(TemplateSetup::class)->markUnsigned($template, true);
    }

    return $template->fresh();
}

test('a saved template records the placeholders and the form fields it asks for', function () {
    $template = uploadTemplate('Payment Request', '<w:r><w:t>${to} ${subject} ${amount} ${due_date}</w:t></w:r>');

    expect($template->placeholders)->toEqualCanonicalizing(['to', 'subject', 'amount', 'due_date', 'qr_code', 'reference'])
        ->and($template->customFields())->toBe(['amount' => 'Amount', 'due_date' => 'Due date']);

});

test('the signature is kept on the same page as the name under it', function () {
    $file = tempnam(sys_get_temp_dir(), 'sig').'.docx';
    file_put_contents($file, pdtsTestDocx('<w:r><w:t>Kindly approve.</w:t></w:r></w:p>'
        .'<w:p><w:pPr><w:pStyle w:val="Normal"/><w:spacing w:after="0"/></w:pPr><w:r><w:t>${signature}</w:t></w:r></w:p>'
        .'<w:p></w:p>'
        .'<w:p><w:r><w:t>${author_upper}</w:t></w:r>'));

    foreach ([null, ['image' => TemplateSetup::sampleSignature(), 'extension' => 'png', 'width_mm' => 36.0, 'height_mm' => 14.0]] as $signature) {
        $xml = pdtsZipPart(app(DocxTemplateFiller::class)->fill($file, ['author_upper' => 'TEDDY SIKAZWE'], app(QrCodePng::class)->make('x'), 16.0, $signature), 'word/document.xml');
        // The signature's line (after its style) and the empty line under it keep with the next line: the name.
        expect($xml)->toContain('<w:pPr><w:pStyle w:val="Normal"/><w:keepNext/><w:spacing w:after="0"/></w:pPr>')
            ->toContain('<w:p><w:pPr><w:keepNext/></w:pPr></w:p>')
            ->toContain('TEDDY SIKAZWE')
            // And "Kindly approve." above it goes with the signature: it never stands alone on a page.
            ->toContain('<w:p><w:pPr><w:keepNext/></w:pPr><w:r><w:t>Kindly approve.</w:t>')
            ->and(substr_count($xml, '<w:keepNext/>'))->toBe(3);
    }
    @unlink($file);
});

test('[BODY] and [SIGNATURE] hint lines are the body and the signature spot; the sample has no ${placeholder} left', function () {
    $line = fn (string $text) => '</w:p><w:p><w:r><w:t xml:space="preserve">'.$text.'</w:t></w:r>';
    $template = uploadTemplate('Hinted Memo', '<w:r><w:t xml:space="preserve">TO: [Title of main recipient]</w:t></w:r>'
        .$line('[SUBJECT IN CAPITAL LETTERS]').$line('[BODY]').$line('').$line('[SIGNATURE]').$line('[FULL NAME IN CAPITALS]'));

    expect($template->placeholders)->toContain('text_body')->toContain('signature')->toContain('author_upper')->not->toContain('body')
        ->and($template->supportsElectronicSignature())->toBeTrue();
    // In the order of the file: the body where [BODY] was, the signature above the name; nothing after the name.
    $text = strip_tags(str_replace('</w:p>', "\n", pdtsZipPart(Storage::disk('private')->get($template->storage_path), 'word/document.xml')));
    expect($text)->toMatch('/\$\{subject\}\s+\$\{text_body\}\s+\$\{signature\}\s+\$\{author_upper\}\s*$/');

    // The sample on the QR code page fills every line.
    $sample = app(TemplateSetup::class)->sample($template, ['page' => 'first', 'x' => 10, 'y' => 10, 'size' => 20]);
    $xml = pdtsZipPart($sample['contents'], 'word/document.xml');
    expect($xml)->not->toContain('${')->toContain('SAMPLE OFFICER')->toContain('This is sample text for the body.');
});

test('a form laid out as a table gets its fields from label and empty cells, and tick boxes become a choice', function () {
    $cell = fn (string $text = '') => '<w:tc><w:p>'.($text === '' ? '' : '<w:r><w:rPr><w:b/><w:sz w:val="20"/></w:rPr><w:t xml:space="preserve">'.$text.'</w:t></w:r>').'</w:p></w:tc>';
    $row = fn (string ...$cells) => '<w:tr>'.implode('', array_map($cell, $cells)).'</w:tr>';
    $table = fn (string ...$rows) => '</w:p><w:tbl>'.implode('', $rows).'</w:tbl><w:p>';

    $template = uploadTemplate('Transport Request', '<w:r><w:t>TRANSPORT REQUEST FORM</w:t></w:r>'
        .$table($row('Request no.', '', 'Date', ''), $row('Requested by', '', 'Department', ''))
        .$table($row('Destination', ''), $row('Type of trip', '☐ Within town     ☐ Out of town     ☐ Overnight'))
        // A grid under its heading: a heading row and numbered empty rows. A table the author adds rows to.
        .'<w:r><w:t>E. ACTION POINTS</w:t></w:r>'
        .$table($row('No.', 'Action', 'Responsible', 'Due date'), $row('1', '', '', ''), $row('2', '', '', ''), $row('3', '', '', ''))
        // An approvals table: each row has its own label, so it is not a grid. The first row is the signatory's:
        // the PDTS signature, with their name and job title and the date beside it; the next rows are signed by hand.
        .$table($row('Stage', 'Name and job title', 'Signature', 'Date', 'Decision / comments'), $row('Requested by', '', '', '', ''), $row('Head of Department', '', '', '', ''))
        // A second "Date": a field of its own.
        .$table($row('Odometer out', '', 'Date', '')));

    // "Date" is the document's date, "Department" and "Requested by" (the signatory) are filled in by PDTS;
    // the second Date is a field of its own.
    expect(array_keys($template->customFields()))->toBe(['request_no', 'destination', 'type_of_trip', 'text_action_points', 'odometer_out', 'date_2'])
        ->and($template->placeholders)->toContain('date')->toContain('department')->toContain('author')
        ->and($template->customFields())->toMatchArray(['request_no' => 'Request no', 'type_of_trip' => 'Type of trip', 'text_action_points' => 'Action points'])
        ->and($template->fieldSetting('type_of_trip'))->toMatchArray(['type' => 'choice', 'choices' => ['Within town', 'Out of town', 'Overnight']])
        // The numbering column is left out: the author writes the other three.
        ->and($template->fieldSetting('text_action_points'))->toMatchArray(['type' => 'table', 'columns' => ['Action', 'Responsible', 'Due date']]);

    // The answers go in the empty cells, in the label's font without its bold; the tick boxes are taken out.
    $xml = pdtsZipPart(Storage::disk('private')->get($template->storage_path), 'word/document.xml');
    expect($xml)->toContain('<w:t xml:space="preserve">Request no.</w:t>')
        ->toMatch('#<w:tc><w:p><w:r><w:rPr><w:sz w:val="20"/></w:rPr><w:t xml:space="preserve">\$\{request_no\}</w:t>#')
        ->not->toContain('☐')->not->toContain('>Responsible<')                   // the grid gave way to its table field
        ->toContain('Head of Department')
        // The signatory's row: name with the job title under it, signature, date; "Decision" and the next rows stay empty.
        ->toMatch('#Requested by(?:(?!</w:tr>).)*\$\{author\}(?:(?!</w:tc>).)*<w:br/><w:t>\$\{author_job_title\}(?:(?!</w:tr>).)*\$\{signature\}(?:(?!</w:tr>).)*\$\{date\}#s')
        ->and(substr_count($xml, '${'))->toBe(9 + 4)   // the 9 fields, and the signatory's row (the QR code is in the header)
        ->and($template->supportsElectronicSignature())->toBeTrue();

    // The form settings page shows it as a table, and saving the settings keeps it one.
    Livewire::test(TemplateReview::class, ['template' => $template])
        ->assertSeeHtml('data-test="table-columns-text_action_points"')->assertSee('Table: Action, Responsible, Due date')
        ->set('settings.text_action_points.label', 'Actions agreed')->call('saveSettings')->assertHasNoErrors();
    expect($template->fresh()->fieldSetting('text_action_points'))->toMatchArray(['type' => 'table', 'columns' => ['Action', 'Responsible', 'Due date'], 'label' => 'Actions agreed']);
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

    // Offered to authors: active in the list (the QR position itself is checked above).
    Livewire::test(DocumentTemplates::class)->assertSee('Active')->assertDontSee('Needs QR code');
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
        ->assertDontSeeHtml('id="document-type"');                 // set by the template: nothing to pick
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

test('any fill-in line in an uploaded file becomes a field, in document order, signature lines are left alone, and the designation beside the signature is filled from the signatory', function () {
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

    expect($template->placeholders)->toBe(['your_ref', 'date', 'attention', 'subject', 'signature', 'author_job_title', 'qr_code', 'reference'])
        ->and(storedPart($template, 'word/document.xml'))
        ->toContain('Signature:') // not a field to type in: the author's signature spot
        ->toContain('${signature}')
        ->toContain('We refer to your letter dated 3 March: please see below.')
        ->toContain('K ____________'); // "Amount:" already had text ("K"), so it is left alone

    $form = Livewire::test(InternalDocument::class)->set('template_id', (string) $template->id);
    expect(collect($form->viewData('formFields'))->pluck('label')->all())->toBe(['Your Ref', 'Date', 'Attention']);
    $form->assertDontSeeHtml('id="internal-to"')
        ->assertSeeHtml('id="custom-your_ref"')
        ->assertSeeHtml('id="custom-attention"')
        ->assertSee('Details in this template');
});

test('a field the author leaves empty is left out of the document, with its label', function () {
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

    expect($body)->toContain('Name: Benson Ngulube')->not->toContain('Account No')->not->toContain('____');
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

        // These templates have no subject line: PDTS names each document itself.
        return PdtsDocument::latest('id')->first();
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
        ->assertDontSee('Move QR code')   // only looking here; the list's menu moves it
        ->assertDontSee('Save template')
        // A quick look: no position in mm, no list of questions, no sample download.
        ->assertDontSee('150 mm from left')->assertDontSee('Form asks for')->assertDontSee('Download sample')
        ->assertSee('viewOnly'.chr(92).'u0022:true', false) // read-only editor, JSON-escaped by @js
        ->assertSee('showPin'.chr(92).'u0022:true', false);

    // Once documents were made from it, the QR code cannot move: said in one short line.
    PdtsDocument::factory()->create(['template_id' => $template->id]);
    $this->get(route('pdts.organization.document-templates.view', $template))
        ->assertSeeHtml('data-test="qr-fixed"');

    Livewire::test(TemplateView::class, ['template' => $template])
        ->call('save', ['page' => 'all', 'x' => 10, 'y' => 10, 'size' => 22])
        ->assertForbidden();
    expect($template->fresh()->qr_page)->toBe('first');

    // Without the permission to manage templates the page is closed.
    $other = User::factory()->create(['man_no' => 'TPL-0002']);
    $this->actingAs($other)->get(route('pdts.organization.document-templates.view', $template))->assertForbidden();
});

test('the template seeder installs the ZESCO Internal Memo as set up in Configurations, and running it again changes nothing', function () {
    $this->seed(PdtsDocumentTemplateSeeder::class);
    $this->seed(PdtsDocumentTemplateSeeder::class);

    $templates = PdtsDocumentTemplate::with('documentType')->orderBy('name')->get();
    $memo = $templates->firstWhere('name', 'Internal Memo');

    expect($templates->pluck('name')->all())->toBe(['Internal Memo'])
        ->and($templates->every(fn ($t) => $t->is_active && $t->isConfigured() && $t->storage_path && Storage::disk('private')->exists($t->storage_path)))->toBeTrue()
        ->and($memo->documentType->name)->toBe('Memorandum')
        ->and($memo->placement())->toBe(['page' => 'first', 'x' => 14.2, 'y' => 23.2, 'size' => 15.0])
        ->and($memo->field_labels)->toMatchArray(['to' => 'To', 'from' => 'From', 'cc' => 'Cc', 'office_reference' => 'Ref', 'date' => 'Date', 'text_body' => 'Body'])
        ->and($memo->fieldSetting('text_body')['type'])->toBe('long')
        ->and($memo->supportsElectronicSignature())->toBeTrue();

    // Authors can use it straight away.
    Livewire::test(InternalDocument::class)
        ->set('template_id', (string) $memo->id)
        ->assertSeeHtml('id="internal-to"');
});

test('two fields on one line ("TO: … <tab> FROM: …") become two columns, so a long answer wraps under itself', function () {
    $run = fn (string $text) => '<w:r><w:t xml:space="preserve">'.$text.'</w:t></w:r>';
    $body = '<w:pPr><w:tabs><w:tab w:val="left" w:pos="4820"/></w:tabs></w:pPr>'
        .$run('TO: ${to}').$run("\t").$run('FROM: ${from}')                // a tab character, as in the ZESCO memo
        .'</w:p><w:p>'.$run('REF: ${office_reference}').'<w:r><w:tab/></w:r>'.$run('DATE: ${date}')   // a Word tab
        .'</w:p><w:p>'.$run('A line with a tab	but no fields stays as it is.');
    $file = tempnam(sys_get_temp_dir(), 'pair').'.docx';
    file_put_contents($file, pdtsTestDocx($body, '<w:r><w:t>Footer</w:t></w:r>'));

    $prepared = app(TemplatePreparer::class)->prepare($file, ['page' => 'first', 'x' => 170, 'y' => 10, 'size' => 20]);
    @unlink($file);
    $xml = pdtsZipPart($prepared['contents'], 'word/document.xml');

    expect(substr_count($xml, '<w:tbl>'))->toBe(2)
        ->and($xml)->toContain('<w:gridCol w:w="4820"/>')
        ->and($xml)->toMatch('#<w:tc>(?:(?!</w:tc>).)*TO: \$\{to\}(?:(?!</w:tc>).)*</w:tc><w:tc>(?:(?!</w:tc>).)*FROM: \$\{from\}#s')
        ->and($xml)->toMatch('#<w:tc>(?:(?!</w:tc>).)*REF: \$\{office_reference\}(?:(?!</w:tc>).)*</w:tc><w:tc>(?:(?!</w:tc>).)*DATE: \$\{date\}#s')
        ->and($xml)->toContain('A line with a tab');
});

test('the ZESCO memo keeps its line under the CC block and lines up the copied names, in Tahoma', function () {
    $prepared = app(TemplatePreparer::class)->prepare(database_path('seeders/templates/ZESCO_Memorandum_Template.docx'), ['page' => 'first', 'x' => 10, 'y' => 10, 'size' => 16]);
    $file = tempnam(sys_get_temp_dir(), 'memo').'.docx';
    file_put_contents($file, $prepared['contents']);
    $xml = pdtsZipPart($prepared['contents'], 'word/document.xml');

    // The CC line carries the separator line and a hanging indent for the names after the first.
    expect($xml)->toMatch('#<w:p>(?:(?!</w:p>).)*<w:pBdr><w:bottom[^>]*/></w:pBdr>(?:(?!</w:p>).)*<w:ind w:left="560" w:hanging="560"/>(?:(?!</w:p>).)*\$\{cc\}#s')
        ->and($xml)->toContain('w:ascii="Tahoma"')->not->toContain('w:ascii="Arial"');

    $fill = fn (string $cc) => pdtsZipPart(app(DocxTemplateFiller::class)->fill($file, ['cc' => $cc, 'to' => 'A', 'from' => 'B', 'date' => '1 May 2026', 'subject' => 'S', 'office_reference' => 'R'], app(QrCodePng::class)->make('x'), 16.0), 'word/document.xml');

    $withCopies = $fill("Managing Director\nAll Directors");
    expect($withCopies)->toContain('Managing Director</w:t><w:br/><w:t xml:space="preserve">All Directors')->toContain('<w:pBdr>');

    // Nobody copied: the CC line goes, the line under it stays.
    $none = $fill('');
    expect(strip_tags($none))->not->toMatch('/CC:/')->and($none)->toContain('<w:pBdr>');
    @unlink($file);
});

test('an uploaded letter: the addressee is asked for, and the signature goes over the signatory after "Yours sincerely"', function () {
    $n = fn ($t) => '<w:r><w:t xml:space="preserve">'.$t.'</w:t></w:r>';
    $letter = $n('OUR REF: [DEPT/A00/0000/2026]').'</w:p><w:p>'
        .$n('[Day Month Year]').'</w:p><w:p>'
        .$n('[Full Name]').'</w:p><w:p>'
        .$n('[TOWN]').'</w:p><w:p>'
        .$n('[SUBJECT OF THE LETTER IN CAPITALS]').'</w:p><w:p>'
        .$n('[BODY]').'</w:p><w:p>'
        .$n('Yours sincerely,').'</w:p><w:p>'
        .$n('ZESCO LIMITED').'</w:p><w:p></w:p><w:p>'
        .$n('[FULL NAME]').'</w:p><w:p>'
        .$n('[JOB TITLE]');

    $template = uploadTemplate('Letter', $letter);
    $xml = storedPart($template, 'word/document.xml');

    expect($template->placeholders)->toContain('recipient_name', 'date', 'author', 'author_job_title', 'signature')
        ->not->toContain('job_title', 'day_month_year')
        // The address: who the letter goes to, not the signatory.
        ->and(strpos($xml, '${recipient_name}'))->toBeLessThan(strpos($xml, '${subject}'))
        // The signature: after "Yours sincerely", straight over the signatory's name.
        ->and(strpos($xml, '${signature}'))->toBeGreaterThan(strpos($xml, 'ZESCO LIMITED'))
        ->and(strpos($xml, '${signature}'))->toBeLessThan(strpos($xml, '${author}'))
        ->and(substr_count($xml, '${author}'))->toBe(1);
});

test('an uploaded report: headings one under the other each get a space to write in; "Prepared by" is the signatory', function () {
    $n = fn ($t) => '<w:r><w:t xml:space="preserve">'.$t.'</w:t></w:r>';
    $report = $n('1. EXECUTIVE SUMMARY').'</w:p><w:p>'
        .$n('2. INTRODUCTION').'</w:p><w:p>'
        .$n('3. CONCLUSION').'</w:p><w:p>'
        .$n('4. PREPARED BY').'</w:p><w:p>'
        .$n('Name:').'</w:p><w:p>'
        .$n('Job title:').'</w:p><w:p>'
        .$n('Signature:').'</w:p><w:p>'
        .$n('Date:');

    $template = uploadTemplate('Monthly Report', $report);
    $xml = storedPart($template, 'word/document.xml');

    expect($template->placeholders)->toContain('text_executive_summary', 'text_introduction', 'text_conclusion', 'author', 'author_job_title', 'signature', 'date')
        ->not->toContain('text_prepared_by', 'name', 'job_title')
        ->and(strpos($xml, '${text_introduction}'))->toBeGreaterThan(strpos($xml, '2. INTRODUCTION'))
        ->and(strpos($xml, '${text_introduction}'))->toBeLessThan(strpos($xml, '3. CONCLUSION'))
        ->and(strip_tags($xml))->toContain('Name: ${author}')->toContain('Job title: ${author_job_title}');
});

test('hints inside a line become fields of their own and the text around them stays: "Dear [Mr./Ms. Surname]", a name beside "[Private and Confidential]"', function () {
    $n = fn ($t) => '<w:r><w:t xml:space="preserve">'.$t.'</w:t></w:r>';
    $letter = $n('[Day Month Year]').'</w:p><w:p>'
        .$n('[Mr./Ms. Full Name]').'<w:r><w:tab/></w:r>'.$n('“[Private and Confidential]”').'</w:p><w:p>'
        .$n('Dear ').$n('[Mr./Ms. Surname]').'</w:p><w:p>'
        .$n('[SUBJECT OF THE LETTER IN CAPITALS]').'</w:p><w:p>'
        .$n('[BODY]').'</w:p><w:p>'
        .$n('Yours faithfully,').'</w:p><w:p></w:p><w:p>'
        .$n('[FULL NAME]');

    $template = uploadTemplate('Letter Four', $letter);
    $text = strip_tags(storedPart($template, 'word/document.xml'));

    expect($template->placeholders)->toContain('recipient_name', 'private_and_confidential', 'mr_ms_surname', 'author', 'signature')
        ->and($text)->toContain('Dear ${mr_ms_surname}')->toContain('“${private_and_confidential}”')
        ->not->toContain('[')
        ->and(strpos($text, '${recipient_name}'))->toBeLessThan(strpos($text, '${subject}'))
        ->and(strpos($text, '${signature}'))->toBeLessThan(strpos($text, '${author}'))
        ->and($template->field_labels['mr_ms_surname'] ?? null)->toBe('Mr./Ms. Surname');
});

test('[BODY] under each report heading is that section\'s own space, not one body repeated', function () {
    $n = fn ($t) => '<w:r><w:t xml:space="preserve">'.$t.'</w:t></w:r>';
    $report = $n('1. PURPOSE').'</w:p><w:p>'.$n('[BODY]').'</w:p><w:p>'
        .$n('2. BACKGROUND').'</w:p><w:p>'.$n('[BODY]').'</w:p><w:p>'
        .$n('3. SIGN-OFF').'</w:p><w:p>'.$n('Name:').'</w:p><w:p>'.$n('Signature:');

    $template = uploadTemplate('Short Report', $report);
    $text = strip_tags(storedPart($template, 'word/document.xml'));

    expect($template->placeholders)->toContain('text_purpose', 'text_background', 'author', 'signature')
        ->not->toContain('text_body')
        ->and($text)->not->toContain('[BODY]')
        ->and(strpos($text, '${text_background}'))->toBeGreaterThan(strpos($text, '2. BACKGROUND'));
});

test('a form with lettered sections: the items list keeps its total as a question, a box under a heading is written in, and what PDTS knows is filled', function () {
    $n = fn ($t) => '<w:r><w:t xml:space="preserve">'.$t.'</w:t></w:r>';
    $cell = fn ($t = '') => '<w:tc><w:p>'.($t === '' ? '' : $n($t)).'</w:p></w:tc>';
    $table = fn (array $rows) => '</w:p><w:tbl>'.implode('', array_map(fn ($row) => '<w:tr>'.implode('', array_map($cell, $row)).'</w:tr>', $rows)).'</w:tbl><w:p>';
    $form = $n('A. REQUEST DETAILS').'</w:p><w:p>'
        .$table([['Requisition ref. no.', '', 'Request date', ''], ['Requesting department', '', 'Requested by', '']])
        .$n('B. ITEMS REQUESTED').$table([['No.', 'Item description', 'Quantity', 'Est. total (K)'], ['1', '', '', ''], ['2', '', '', ''], ['', '', 'GRAND TOTAL (K)', '']])
        .$n('C. PURPOSE AND JUSTIFICATION').$table([['']])
        .$n('D. SIGNATURES').$table([['Stage', 'Name and job title', 'Signature', 'Date'], ['Requested by', '', '', ''], ['Authorisation', '', '', '']]);

    $template = uploadTemplate('Purchase Requisition', $form);
    $text = strip_tags(storedPart($template, 'word/document.xml'));

    expect($template->placeholders)->toContain('text_items_requested', 'grand_total_k', 'text_purpose_and_justification', 'requisition_ref_no', 'date', 'department', 'author', 'signature')
        // Lettered headings: the letter is not part of the question's name, and the
        // empty line under "A." before its table is spacing, not a place to write.
        ->not->toContain('text_c_purpose_and_justification', 'text_request_details', 'request_date', 'requesting_department', 'requested_by', 'date_2')
        ->and($template->field_labels['grand_total_k'] ?? null)->toBe('Grand total (K)')
        ->and($text)->toContain('GRAND TOTAL (K): ${grand_total_k}');
});

test('after uploading, the QR code page shows what PDTS found', function () {
    $n = fn ($t) => '<w:r><w:t xml:space="preserve">'.$t.'</w:t></w:r>';
    $letter = $n('OUR REF: [DEPT/A00/0000/2026]').'</w:p><w:p>'
        .$n('[Day Month Year]').'</w:p><w:p>'.$n('[Mr./Ms. Full Name]').'</w:p><w:p>'
        .$n('[Salutation, e.g. Dear Mr Banda]').'</w:p><w:p>'
        .$n('[SUBJECT OF THE LETTER IN CAPITALS]').'</w:p><w:p>'.$n('[BODY]').'</w:p><w:p>'
        .$n('Yours faithfully,').'</w:p><w:p></w:p><w:p>'.$n('[FULL NAME]');

    $template = uploadTemplate('Letter', $letter);

    foreach ([TemplateQrSetup::class, TemplateView::class] as $page) {
        Livewire::test($page, ['template' => $template])
            ->assertSeeHtml('data-test="qr-found"')->assertSee('What PDTS found')
            ->assertSee('Fields to fill')->assertSee("Recipient's full name")->assertSee('Salutation')
            ->assertSee('Spaces to write in')->assertSee('Body')
            ->assertSee('Signature spot found: can be signed in PDTS')
            ->assertSee("Name line filled with the signatory's name");
    }

    // Not repeated on the form settings page.
    Livewire::test(TemplateReview::class, ['template' => $template])
        ->assertDontSeeHtml('data-test="template-found"')->assertDontSee('What PDTS found');
});

test('a PDF saved from Word can be uploaded and stamped', function () {
    $pdf = pdtsTestPdfWord();

    Livewire::test(DocumentTemplates::class)
        ->call('openCreate')->set('name', 'Word PDF')
        ->set('file', UploadedFile::fake()->createWithContent('word-form.pdf', $pdf))
        ->call('save')->assertHasNoErrors();
    $template = PdtsDocumentTemplate::sole();

    expect($template->kind)->toBe('pdf')->and($template->page_count)->toBe(1);

    $stamped = app(TemplateSetup::class)->stamp($pdf, ['page' => 'first', 'x' => 170.0, 'y' => 10.0, 'size' => 22.0], 'https://pdts.test/s/SAMPLE', 'OMTS-2026-0000');
    expect($stamped)->toStartWith($pdf)->toContain('OMTS-2026-0000')
        ->and(strlen($stamped))->toBeGreaterThan(strlen($pdf));
});

test('a template with a subject line asks for the subject; one without names the document itself', function () {
    $n = fn ($t) => '<w:r><w:t xml:space="preserve">'.$t.'</w:t></w:r>';
    $memo = uploadTemplate('Memo With Subject', $n('TO: [Title of main recipient]').'</w:p><w:p>'.$n('[SUBJECT IN CAPITALS]').'</w:p><w:p>'.$n('[BODY]'));
    $report = uploadTemplate('Short Report', $n('[REPORT TITLE]').'</w:p><w:p>'.$n('1. PURPOSE').'</w:p><w:p></w:p><w:p>'.$n('Signature:'));
    $form = uploadTemplate('Transport Request', $n('Destination: ________').'</w:p><w:p>'.$n('Signature:'));
    $fill = fn ($component) => $component->set('document_type_id', (string) $this->type->id)
        ->set('directorate_id', (string) $this->directorate->id)->set('department_id', (string) $this->department->id)->set('office_id', (string) $this->office->id);

    expect($memo->asksForSubject())->toBeTrue()
        ->and($report->asksForSubject())->toBeFalse()->and($report->titleField())->toBe('report_title')
        ->and($form->asksForSubject())->toBeFalse()->and($form->titleField())->toBeNull();

    // A subject line: the Subject box, and it must be filled in.
    $fill(Livewire::test(InternalDocument::class)->set('template_id', (string) $memo->id))
        ->assertSeeHtml('id="document-subject"')->assertDontSeeHtml('data-test="automatic-name"')
        ->set('to', 'Director ICT')->set('custom.text_body', 'Text.')
        ->call('save')->assertHasErrors('subject');

    // A title line: no Subject box; the title names the document.
    $fill(Livewire::test(InternalDocument::class)->set('template_id', (string) $report->id))
        ->assertDontSeeHtml('id="document-subject"')->assertDontSeeHtml('id="document-title"')->assertSeeHtml('data-test="automatic-name"')
        ->set('custom.report_title', 'ICT Monthly Report - November 2026')
        ->call('save')->assertHasNoErrors();
    $named = PdtsDocument::latest('id')->first();
    expect($named->subject)->toBe('ICT Monthly Report - November 2026')->and($named->title)->toBe('ICT Monthly Report - November 2026');

    // Neither: the template, the signatory and the date.
    $fill(Livewire::test(InternalDocument::class)->set('template_id', (string) $form->id))
        ->assertSee('Name in PDTS:')->assertSee('Transport Request – Racheal Inonge Zekko – '.now()->format('j F Y'))
        ->set('custom.destination', 'Kitwe')
        ->call('save')->assertHasNoErrors();
    expect(PdtsDocument::latest('id')->first()->subject)->toBe('Transport Request – Racheal Inonge Zekko – '.now()->format('j F Y'));

    // The review page's preview needs no subject either.
    Livewire::test(InternalDocument::class, ['previewTemplateId' => $form->id])->set('custom.destination', 'Kitwe')
        ->call('checkPreview')->assertHasNoErrors()->assertSet('previewPassed', true);
});

test('the names under CC, and a long TO or FROM, line up under the first line of the answer', function () {
    $b = fn ($t) => '<w:r><w:rPr><w:b/></w:rPr><w:t xml:space="preserve">'.$t.'</w:t></w:r>';
    $n = fn ($t) => '<w:r><w:t xml:space="preserve">'.$t.'</w:t></w:r>';
    $cell = fn ($label, $field) => '<w:tc><w:p>'.$b($label).$n(' ${'.$field.'}').'</w:p></w:tc>';
    $body = $n('MEMORANDUM').'</w:p><w:tbl><w:tr>'.$cell('TO:', 'to').$cell('FROM:', 'from').'</w:tr></w:tbl>'
        .'<w:p><w:pPr><w:ind w:left="560" w:hanging="560"/></w:pPr>'.$b('CC:').$n(' ${cc}').'</w:p><w:p>'.$b('Remarks:').$n(' ${remarks}');
    $file = tempnam(sys_get_temp_dir(), 'align');
    file_put_contents($file, pdtsTestDocx($body));

    $filled = app(DocxTemplateFiller::class)->fill($file, ['to' => 'Director ICT', 'from' => 'Engineer – Machine Learning and Artificial Intelligence',
        'cc' => "Senior Manager – AI\nManager – ICT Infrastructure", 'remarks' => 'None'], app(QrCodePng::class)->make('x'));
    $xml = pdtsZipPart($filled, 'word/document.xml');

    // CC: a tab to its own hanging indent, so the first name starts where the next ones do.
    expect($xml)->toMatch('#<w:ind w:left="560" w:hanging="560"/>.*?CC:</w:t>.*?<w:tab/><w:t[^>]*>Senior Manager – AI</w:t><w:br/>#s')
        // TO and FROM in their cells: a hanging indent a little wider than the label, and a tab to it.
        ->toMatch('#<w:ind w:left="(\d+)" w:hanging="\1"/>.*?TO:</w:t>.*?<w:tab/><w:t[^>]*>Director ICT#s')
        ->toMatch('#<w:ind w:left="(\d+)" w:hanging="\1"/>.*?FROM:</w:t>.*?<w:tab/><w:t[^>]*>Engineer#s')
        // A label line outside a table, with no indent of its own, is left as it was.
        ->toContain('Remarks:</w:t></w:r><w:r><w:t xml:space="preserve"> None</w:t>');
    preg_match_all('#<w:ind w:left="(\d+)" w:hanging="\d+"/>#', $xml, $indents);
    expect((int) $indents[1][1])->toBeGreaterThan((int) $indents[1][0]);   // FROM: is wider than TO:
});

test('in a memo heading TO and CC answers start in one column, close after the labels; REF keeps its own', function () {
    $b = fn ($t) => '<w:r><w:rPr><w:b/></w:rPr><w:t xml:space="preserve">'.$t.'</w:t></w:r>';
    $n = fn ($t) => '<w:r><w:t xml:space="preserve">'.$t.'</w:t></w:r>';
    $cell = fn ($label, $field) => '<w:tc><w:p>'.$b($label).$n(' ${'.$field.'}').'</w:p></w:tc>';
    // As in the ZESCO memo: TO | FROM, then CC with its own (narrower) indent, then REF | DATE.
    $body = $n('MEMORANDUM').'</w:p><w:tbl><w:tr>'.$cell('TO:', 'to').$cell('FROM:', 'from').'</w:tr></w:tbl>'
        .'<w:p><w:pPr><w:ind w:left="560" w:hanging="560"/></w:pPr>'.$b('CC:').$n(' ${cc}').'</w:p>'
        .'<w:tbl><w:tr>'.$cell('REF:', 'office_reference').$cell('DATE:', 'date').'</w:tr></w:tbl><w:p>'.$n('End');
    $file = tempnam(sys_get_temp_dir(), 'column');
    file_put_contents($file, pdtsTestDocx($body));
    $xml = pdtsZipPart(app(DocxTemplateFiller::class)->fill($file, ['to' => 'Director ICT', 'from' => 'Engineer', 'cc' => 'Manager ICT',
        'office_reference' => 'DIF/054', 'date' => '1 May 2026'], app(QrCodePng::class)->make('x')), 'word/document.xml');

    $indent = function (string $label) use (&$xml) {
        preg_match('#<w:ind w:left="(\d+)" w:hanging="(\d+)"/>(?:(?!</w:p>).)*?'.preg_quote($label).'</w:t>#s', $xml, $m);

        return [(int) $m[1], (int) $m[2]];
    };
    expect($indent('TO:'))->toBe($indent('CC:'))->toBe([560, 560])   // the template's CC indent: no wider gap
        ->and($indent('REF:')[1])->toBeGreaterThan(560);              // REF, under the line, its own

    // A wider label among them (THROUGH:) moves TO and CC along with it, all lined up.
    $body2 = str_replace($cell('TO:', 'to'), $cell('TO:', 'to').'</w:tr><w:tr>'.$cell('THROUGH:', 'through').'<w:tc><w:p/></w:tc>', $body);
    file_put_contents($file, pdtsTestDocx($body2));
    $xml = pdtsZipPart(app(DocxTemplateFiller::class)->fill($file, ['to' => 'Director ICT', 'through' => 'Senior Manager', 'from' => 'Engineer', 'cc' => 'Manager ICT',
        'office_reference' => 'DIF/054', 'date' => '1 May 2026', 'x' => ''], app(QrCodePng::class)->make('x')), 'word/document.xml');
    expect($indent('TO:'))->toBe($indent('THROUGH:'))->toBe($indent('CC:'))
        ->and($indent('CC:')[1])->toBeGreaterThan(560);
});

test('the signature never stands alone on a page: the closing lines and the last paragraph go with it', function () {
    $n = fn ($t) => '<w:r><w:t xml:space="preserve">'.$t.'</w:t></w:r>';
    $long = str_repeat('The pilot will run for one month in the ICT Directorate. ', 3);
    $body = $n('First paragraph, which stays where it is: '.$long).'</w:p><w:p>'.$n('Last paragraph of the text: '.$long).'</w:p><w:p>'
        .$n('Yours faithfully,').'</w:p><w:p>'.$n('ZESCO LIMITED').'</w:p><w:p></w:p><w:p>'.$n('${signature}').'</w:p><w:p>'.$n('${author_upper}');
    $file = tempnam(sys_get_temp_dir(), 'keep');
    file_put_contents($file, pdtsTestDocx($body));

    $xml = pdtsZipPart(app(DocxTemplateFiller::class)->fill($file, ['author_upper' => 'TEDDY SIKAZWE'], app(QrCodePng::class)->make('x')), 'word/document.xml');
    $kept = fn (string $text) => (bool) preg_match('#<w:p><w:pPr><w:keepNext/>(?:(?!</w:p>).)*'.preg_quote($text, '#').'#s', $xml);

    expect($kept('ZESCO LIMITED'))->toBeTrue()
        ->and($kept('Yours faithfully,'))->toBeTrue()
        ->and($kept('Last paragraph of the text'))->toBeTrue()        // the last full paragraph goes with the signature
        ->and($kept('First paragraph, which stays'))->toBeFalse();     // nothing before it
});

test('a QR code on every page: the pages after the first start their text below it; the first page stays as it is', function () {
    $file = tempnam(sys_get_temp_dir(), 'qrall');
    // A narrow top margin (20 mm) and no letterhead: the text of page 2 would start under the QR code.
    file_put_contents($file, str_replace('<w:sectPr>', '<w:sectPr><w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134" w:header="567" w:footer="567" w:gutter="0"/>',
        pdtsTestDocx('<w:r><w:t>Page one text</w:t></w:r>', '<w:r><w:t>Footer</w:t></w:r>')));

    $prepared = app(TemplatePreparer::class)->prepare($file, ['page' => 'all', 'x' => 10.0, 'y' => 10.0, 'size' => 16.0]);
    $path = tempnam(sys_get_temp_dir(), 'qrall-out');
    file_put_contents($path, $prepared['contents']);
    $files = DocxPackage::read($path);
    $document = $files['word/document.xml'];
    $rels = $files['word/_rels/document.xml.rels'];
    $header = function (string $type) use ($document, $rels, $files) {
        preg_match('#<w:headerReference w:type="'.$type.'" r:id="([^"]+)"#', $document, $ref);
        preg_match('#Id="'.preg_quote($ref[1] ?? '', '#').'"[^>]*Target="([^"]+)"|Target="([^"]+)"[^>]*Id="'.preg_quote($ref[1] ?? '', '#').'"#', $rels, $target);

        return $files['word/'.($target[1] ?: $target[2])] ?? '';
    };

    expect($document)->toContain('<w:titlePg/>')
        ->and($header('first'))->toContain('PDTS QR code')->not->toContain('w:lineRule="exact"')   // page 1: the QR code, nothing added
        ->and($header('default'))->toContain('PDTS QR code')->toContain('w:lineRule="exact"');     // next pages: the QR code, and room under it
});

test('leaving fields out: a label line goes, a heading of its own goes with its space, a table cell is left blank, a sentence keeps its words', function () {
    $n = fn ($t) => '<w:r><w:t xml:space="preserve">'.$t.'</w:t></w:r>';
    $cell = fn ($t) => '<w:tc><w:p>'.$n($t).'</w:p></w:tc>';
    $body = $n('Name: ${name}').'</w:p><w:p>'.$n('Account No: ${account_no}').'</w:p><w:p>'
        .$n('1.2  Background').'</w:p><w:p>'.$n('${text_background}').'</w:p><w:p>'
        .$n('Findings').'</w:p><w:p>'.$n('${text_findings}').'</w:p><w:p>'
        .$n('Dear ${salutation}, thank you.').'</w:p><w:tbl><w:tr>'.$cell('Cost centre').$cell('${cost_centre}').'</w:tr></w:tbl><w:p>'
        .$n('Remarks: ${remarks}');
    $file = tempnam(sys_get_temp_dir(), 'omit');
    file_put_contents($file, pdtsTestDocx($body));

    $values = ['name' => 'Benson Ngulube', 'text_findings' => 'All went well.', 'remarks' => '', 'account_no' => '', 'text_background' => '', 'salutation' => '', 'cost_centre' => ''];
    $omit = ['account_no' => 'Account No', 'text_background' => 'Background', 'salutation' => 'Salutation', 'cost_centre' => 'Cost centre'];
    $xml = pdtsZipPart(app(DocxTemplateFiller::class)->fill($file, $values, app(QrCodePng::class)->make('x'), 16.0, null, [], [], $omit), 'word/document.xml');
    $text = html_entity_decode(strip_tags(str_replace('</w:p>', "\n", $xml)));

    expect($text)->toContain('Name: Benson Ngulube')
        ->not->toContain('Account No')                               // the whole line
        ->not->toContain('Background')                               // its own heading ("1.2  Background") and its space
        ->toContain('Findings')->toContain('All went well.')         // a filled section stays
        ->toContain('Dear , thank you.')                             // inside a sentence: only the blank goes
        ->toContain('Cost centre')                                   // the table keeps its shape: the label cell stays
        ->toContain('Remarks:')                                      // hidden (not left out): stays, empty, to fill in by hand
        ->not->toContain('${');
});

test('a sign-off block takes the last paragraph of the text with it, and an empty department line is left out', function () {
    $n = fn ($t) => '<w:r><w:t xml:space="preserve">'.$t.'</w:t></w:r>';
    $long = str_repeat('A report on usage will be submitted at the end of the pilot. ', 3);
    $body = $n('Department: ${department}').'</w:p><w:p>'.$n('Earlier paragraph: '.$long).'</w:p><w:p>'.$n('Last paragraph: '.$long).'</w:p><w:p>'
        .$n('Kindly approve.').'</w:p><w:p>'.$n('6. SIGN-OFF').'</w:p><w:p>'.$n('Name: ${author}').'</w:p><w:p>'.$n('Job title: ${author_job_title}').'</w:p><w:p>'
        .$n('Signature: ${signature}').'</w:p><w:p>'.$n('Date: ${date}');
    $file = tempnam(sys_get_temp_dir(), 'signoff');
    file_put_contents($file, pdtsTestDocx($body));

    $xml = pdtsZipPart(app(DocxTemplateFiller::class)->fill($file, ['department' => '', 'author' => 'TEDDY SIKAZWE', 'author_job_title' => 'Engineer', 'date' => '25 November 2026'],
        app(QrCodePng::class)->make('x'), 16.0, null, [], [], ['department' => 'Department']), 'word/document.xml');
    $kept = fn (string $text) => (bool) preg_match('#<w:p><w:pPr><w:keepNext/>(?:(?!</w:p>).)*'.preg_quote($text, '#').'#s', $xml);

    expect($kept('Job title:'))->toBeTrue()->and($kept('Name:'))->toBeTrue()->and($kept('6. SIGN-OFF'))->toBeTrue()->and($kept('Kindly approve.'))->toBeTrue()
        ->and($kept('Last paragraph:'))->toBeTrue()            // the page the sign-off moves to always has some of the text
        ->and($kept('Earlier paragraph:'))->toBeFalse()
        ->and(strip_tags($xml))->not->toContain('Department');
});

test('a filled document leaves a little room above the footer, and a built heading has room above it', function () {
    $file = tempnam(sys_get_temp_dir(), 'footer');
    file_put_contents($file, pdtsTestDocx('<w:r><w:t>Text</w:t></w:r>', '<w:r><w:t>ZESCO footer</w:t></w:r>'));
    $filled = app(DocxTemplateFiller::class)->fill($file, [], app(QrCodePng::class)->make('x'));

    expect(pdtsZipPart($filled, 'word/footer1.xml'))->toMatch('#<w:ftr[^>]*><w:p><w:pPr><w:spacing w:before="0" w:after="0" w:line="220" w:lineRule="exact"/></w:pPr></w:p>#')
        ->toContain('ZESCO footer');

    // In the builder, a Paragraph question's heading stands apart from the text above it.
    $design = app(TemplateDesigner::class);
    $item = TemplateDesigner::newItem('question', 'long');
    $item['label'] = 'Background';
    $xml = pdtsZipPart($design->docx($design->clean(['layout' => 'custom', 'title' => 'Notice', 'items' => [$item]]), 'Notice'), 'word/document.xml');
    expect($xml)->toMatch('#<w:spacing w:before="280" w:after="160"/>(?:(?!</w:p>).)*Background#s');
});

test('an uploaded section heading gets room under it before its text; the memo body under the subject does not change', function () {
    $n = fn ($t) => '<w:r><w:t xml:space="preserve">'.$t.'</w:t></w:r>';
    $body = $n('${subject}').'</w:p><w:p>'.$n('${text_body}').'</w:p>'
        .'<w:p><w:pPr><w:spacing w:before="240" w:after="60"/></w:pPr>'.$n('1. PURPOSE').'</w:p><w:p>'.$n('${text_purpose}').'</w:p>'
        .'<w:p><w:pPr><w:spacing w:before="240" w:after="300"/></w:pPr>'.$n('2. BACKGROUND').'</w:p><w:p>'.$n('${text_background}');
    $file = tempnam(sys_get_temp_dir(), 'heads');
    file_put_contents($file, pdtsTestDocx($body));

    $xml = pdtsZipPart(app(DocxTemplateFiller::class)->fill($file, ['subject' => 'SUBJECT', 'text_body' => 'Body text.', 'text_purpose' => 'Purpose text.', 'text_background' => 'Background text.'],
        app(QrCodePng::class)->make('x')), 'word/document.xml');

    expect($xml)->toContain('<w:spacing w:before="240" w:after="160"/></w:pPr><w:r><w:t xml:space="preserve">1. PURPOSE')    // 3 pt became 8 pt
        ->toContain('<w:spacing w:before="240" w:after="300"/></w:pPr><w:r><w:t xml:space="preserve">2. BACKGROUND')               // already more: as it was
        ->toMatch('#<w:p><w:r><w:t xml:space="preserve">SUBJECT</w:t>#');                                                        // the subject line is left alone
});

test('CC wraps in the left column where TO does, never under FROM; the line under it stays the full width', function () {
    $b = fn ($t) => '<w:r><w:rPr><w:b/></w:rPr><w:t xml:space="preserve">'.$t.'</w:t></w:r>';
    $n = fn ($t) => '<w:r><w:t xml:space="preserve">'.$t.'</w:t></w:r>';
    $cell = fn ($label, $field, $w) => '<w:tc><w:tcPr><w:tcW w:w="'.$w.'" w:type="dxa"/></w:tcPr><w:p>'.$b($label).$n(' ${'.$field.'}').'</w:p></w:tc>';
    // As in the ZESCO memo: TO | FROM in a table (cells' right margin 113), CC under it carrying the line.
    $body = $n('MEMORANDUM').'</w:p><w:tbl><w:tblPr><w:tblW w:w="9360" w:type="dxa"/><w:tblCellMar><w:left w:w="0" w:type="dxa"/><w:right w:w="113" w:type="dxa"/></w:tblCellMar></w:tblPr>'
        .'<w:tblGrid><w:gridCol w:w="4680"/><w:gridCol w:w="4680"/></w:tblGrid><w:tr>'.$cell('TO:', 'to', 4680).$cell('FROM:', 'from', 4680).'</w:tr></w:tbl>'
        .'<w:p><w:pPr><w:pBdr><w:bottom w:val="single" w:sz="12" w:space="8" w:color="000000"/></w:pBdr><w:spacing w:before="60" w:after="200"/><w:ind w:left="560" w:hanging="560"/></w:pPr>'
        .$b('CC:').$n(' ${cc}').'</w:p><w:p>'.$n('End');
    $file = tempnam(sys_get_temp_dir(), 'ccleft');
    file_put_contents($file, pdtsTestDocx($body));
    $xml = pdtsZipPart(app(DocxTemplateFiller::class)->fill($file, ['to' => 'Graduate Engineer', 'from' => 'Engineer',
        'cc' => "Principal Systems Developer - Software Development & Commercial Services\nManager"], app(QrCodePng::class)->make('x')), 'word/document.xml');

    // The page (Letter, 1-inch margins: 9360 wide) less where TO's column ends (4680 - 113).
    expect($xml)->toMatch('#<w:ind w:left="560" w:hanging="560" w:right="4793"/>(?:(?!</w:p>).)*?CC:</w:t>#s')
        // The line: no longer on the CC paragraph, but on a thin empty one right after it, keeping the space under it.
        ->toMatch('#Manager</w:t>(?:(?!<w:p[ >]).)*?</w:p><w:p><w:pPr><w:pBdr><w:bottom w:val="single" w:sz="12" w:space="8" w:color="000000"/></w:pBdr><w:spacing w:before="0" w:after="200" w:line="20" w:lineRule="exact"/>#s')
        ->and(substr_count($xml, '<w:pBdr>'))->toBe(1);
});
