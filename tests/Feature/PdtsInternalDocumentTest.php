<?php

use App\Livewire\Pdts\Documents\InternalDocument;
use App\Livewire\Pdts\Documents\Show;
use App\Livewire\Pdts\Organization\DocumentTemplates;
use App\Models\PdtsDepartment;
use App\Models\PdtsDirectorate;
use App\Models\PdtsDocument;
use App\Models\PdtsDocumentTemplate;
use App\Models\PdtsDocumentType;
use App\Models\PdtsOffice;
use App\Models\User;
use App\Services\Documents\CustodyService;
use App\Services\Pdts\Templates\DocxTemplateFiller;
use App\Services\Pdts\Templates\QrCodePng;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/../Support/docx.php';

beforeEach(function () {
    Storage::fake('private');

    foreach (['documents.register', 'documents.view', 'documents.forward', 'documents.acknowledge', 'document_types.manage'] as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $this->author = User::factory()->create(['man_no' => 'INT-0001', 'name' => 'Racheal Inonge Zekko']);
    $this->author->givePermissionTo(['documents.register', 'documents.view', 'documents.forward']);
    $this->actingAs($this->author);

    $this->memoType = PdtsDocumentType::create(['name' => 'Memorandum', 'is_active' => true]);
    $this->directorate = PdtsDirectorate::create(['name' => 'Investments & Finance', 'is_active' => true]);
    $this->department = PdtsDepartment::create(['name' => 'Finance', 'directorate_id' => $this->directorate->id, 'is_active' => true]);
    $this->office = PdtsOffice::create(['name' => 'Finance Head Office', 'department_id' => $this->department->id, 'is_active' => true]);

    $this->memo = pdtsMemoTemplate($this->memoType->id);
});

function pdtsCreateDraft(): PdtsDocument
{
    Livewire::test(InternalDocument::class)
        ->set('template_id', (string) test()->memo->id)
        ->set('document_type_id', (string) test()->memoType->id)
        ->set('to', 'Director - Corporate Affairs')
        ->set('from', 'Director - Investments & Finance')
        ->set('cc', "Managing Director\nAll Directors")
        ->set('office_reference', 'DIF/DDAS/054/2025')
        ->set('memo_date', '2026-11-15')
        ->set('subject', 'Revision of petty cash limit')
        ->set('directorate_id', (string) test()->directorate->id)
        ->set('department_id', (string) test()->department->id)
        ->set('office_id', (string) test()->office->id)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    return PdtsDocument::query()->sole();
}

test('the QR code is a valid PNG', function () {
    $png = app(QrCodePng::class)->make('https://pdts.example/s/abc');

    expect(substr($png, 0, 8))->toBe("\x89PNG\r\n\x1a\n")
        ->and(getimagesizefromstring($png)[2])->toBe(IMAGETYPE_PNG);
});

test('every document gets a random scan code', function () {
    $document = PdtsDocument::create([
        'reference_number' => 'OMTS-2026-0900',
        'title' => 'External letter',
        'subject' => 'External letter',
        'document_type_id' => $this->memoType->id,
        'originating_office' => 'Registry',
        'registered_by' => $this->author->id,
        'current_custodian_id' => $this->author->id,
        'status' => 'registered',
        'registered_at' => now(),
    ]);

    expect($document->scan_token)->toHaveLength(40)
        ->and($document->origin)->toBe('external');
});

test('an author creates an internal draft from an uploaded memorandum template', function () {
    $document = pdtsCreateDraft();

    expect($document->status)->toBe('draft')
        ->and($document->origin)->toBe('internal')
        ->and($document->template_id)->toBe($this->memo->id)
        ->and($document->title)->toBe('Revision of petty cash limit')
        ->and($document->template_data)->toMatchArray([
            'to' => 'Director - Corporate Affairs',
            'cc' => "Managing Director\nAll Directors",
            'office_reference' => 'DIF/DDAS/054/2025',
        ])
        ->and($document->originating_office)->toBe('Finance Head Office')
        ->and($document->auditLogs()->pluck('action')->all())->toBe(['draft_created']);
});

test('the Word file carries the details, and the QR code and reference in its footer', function () {
    $document = pdtsCreateDraft();

    $response = $this->get(route('pdts.documents.word', $document))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

    $bytes = $response->getContent();
    $body = pdtsZipPart($bytes, 'word/document.xml');
    $footer = pdtsZipPart($bytes, 'word/footer1.xml');

    expect($body)->toContain('Director - Corporate Affairs')
        ->toContain('DIF/DDAS/054/2025')
        ->toContain('Revision of petty cash limit')
        ->toContain('Managing Director</w:t><w:br/><w:t xml:space="preserve">All Directors')
        ->and($footer)->toContain($document->reference_number)->toContain('r:embed="rIdPdtsQr"')
        ->and(pdtsZipPart($bytes, 'word/media/pdts-qr.png'))->toStartWith("\x89PNG");
});

test('people not involved cannot download the Word file', function () {
    $document = pdtsCreateDraft();
    $outsider = User::factory()->create(['man_no' => 'INT-0002']);
    $outsider->givePermissionTo('documents.view');

    $this->actingAs($outsider)->get(route('pdts.documents.word', $document))->assertForbidden();
});

test('a draft cannot be forwarded until the signed scan is uploaded', function () {
    $document = pdtsCreateDraft();

    expect(app(CustodyService::class)->forwardBlockedReason($document, $this->author))
        ->toBe('Upload the signed scan before sending this document.');
});

test('uploading the signed scan registers the draft', function () {
    $document = pdtsCreateDraft();
    $scan = UploadedFile::fake()->createWithContent('signed.pdf', "%PDF-1.4\nsigned\n%%EOF");

    Livewire::test(Show::class, ['document' => $document])
        ->set('signedScan', $scan)
        ->call('uploadSignedScan')
        ->assertHasNoErrors();

    $document->refresh();

    expect($document->status)->toBe('registered')
        ->and($document->attachments()->count())->toBe(1)
        ->and($document->auditLogs()->pluck('action')->all())->toBe(['draft_created', 'registered'])
        ->and(app(CustodyService::class)->forwardBlockedReason($document, $this->author))->toBeNull();
});

test('only the author can upload the signed scan', function () {
    $document = pdtsCreateDraft();
    $other = User::factory()->create(['man_no' => 'INT-0003']);
    $other->givePermissionTo('documents.view');

    Livewire::actingAs($other)->test(Show::class, ['document' => $document])
        ->set('signedScan', UploadedFile::fake()->createWithContent('signed.pdf', "%PDF-1.4\n%%EOF"))
        ->call('uploadSignedScan')
        ->assertHasErrors('signedScan');

    expect($document->refresh()->status)->toBe('draft');
});

test('an uploaded template is filled even when Word split a placeholder across runs', function () {
    $template = pdtsTestDocx(
        '<w:r><w:t xml:space="preserve">To: ${t</w:t></w:r><w:r><w:rPr><w:b/></w:rPr><w:t>o}</w:t></w:r><w:r><w:t xml:space="preserve"> Ref: ${reference}</w:t></w:r>',
    );
    $path = tempnam(sys_get_temp_dir(), 'pdts-tpl-src');
    file_put_contents($path, $template);

    $filler = app(DocxTemplateFiller::class);
    expect($filler->placeholdersIn($path))->toEqualCanonicalizing(['to', 'reference', 'qr_code']);

    $filled = $filler->fill($path, ['to' => 'Director & Staff', 'reference' => 'OMTS-2026-0007'], app(QrCodePng::class)->make('x'));
    unlink($path);

    expect(pdtsZipPart($filled, 'word/document.xml'))->toContain('To: Director &amp; Staff')->toContain('OMTS-2026-0007')->not->toContain('${')
        ->and(pdtsZipPart($filled, 'word/footer1.xml'))->toContain('r:embed="rIdPdtsQr"')->not->toContain('${qr_code}')
        ->and(pdtsZipPart($filled, 'word/_rels/footer1.xml.rels'))->toContain('media/pdts-qr.png')
        ->and(pdtsZipPart($filled, '[Content_Types].xml'))->toContain('Extension="png"')
        ->and(pdtsZipPart($filled, 'word/media/pdts-qr.png'))->toStartWith("\x89PNG");
});

test('administrators upload templates, and a file that is not Word is refused', function () {
    $this->author->givePermissionTo('document_types.manage');

    Livewire::test(DocumentTemplates::class)
        ->call('openCreate')
        ->set('name', 'Internal Minute')
        ->set('file', UploadedFile::fake()->createWithContent('minute.docx', 'not a word file'))
        ->call('save')
        ->assertHasErrors('file');

    Livewire::test(DocumentTemplates::class)
        ->call('openCreate')
        ->set('name', 'Internal Minute')
        ->set('documentTypeId', (string) $this->memoType->id)
        ->set('file', UploadedFile::fake()->createWithContent('minute.docx', pdtsTestDocx('<w:r><w:t>${subject}</w:t></w:r>')))
        ->call('save')
        ->assertHasNoErrors();

    $template = PdtsDocumentTemplate::where('name', 'Internal Minute')->sole();
    expect($template->is_active)->toBeFalse() // until its QR code is placed
        ->and($template->document_type_id)->toBe($this->memoType->id);
    Storage::disk('private')->assertExists($template->original_path);
});

test('the templates page needs the document types permission', function () {
    $this->get(route('pdts.organization.document-templates'))->assertForbidden();
});

test('scanning a draft shows the author it still needs the signed scan', function () {
    $document = pdtsCreateDraft();

    $this->get($document->scanUrl())
        ->assertOk()
        ->assertSee($document->reference_number)
        ->assertSee('This is a draft');
});
