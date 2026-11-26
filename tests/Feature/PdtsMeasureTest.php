<?php

use App\Livewire\Pdts\Dashboard;
use App\Livewire\Pdts\Documents\InternalDocument;
use App\Livewire\Pdts\Documents\Show;
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
use App\Services\Pdts\Signatures\SignatureProfile;
use App\Services\Pdts\Templates\TemplateSetup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/../Support/docx.php';
require_once __DIR__.'/../Support/signatures.php';

uses(RefreshDatabase::class);

/** Stands in for LibreOffice. */
class MeasureTestPdfConverter extends PdfConverter
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
    app()->instance(PdfConverter::class, new MeasureTestPdfConverter);

    foreach (['documents.register', 'documents.view', 'documents.forward', 'documents.acknowledge', 'reports.view'] as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $this->author = User::factory()->create(['man_no' => 'MSR-01', 'name' => 'Teddy Sikazwe', 'grade' => 'ML3', 'password' => Hash::make('login-pass')]);
    $this->author->givePermissionTo(['documents.register', 'documents.view', 'documents.forward', 'documents.acknowledge']);
    $this->manager = User::factory()->create(['man_no' => 'MSR-02', 'name' => 'Board Viewer']);
    $this->manager->givePermissionTo(['reports.view', 'documents.view']);

    $this->type = PdtsDocumentType::create(['name' => 'Memorandum', 'is_active' => true]);
    $this->directorate = PdtsDirectorate::create(['name' => 'Corporate Services', 'is_active' => true]);
    $this->department = PdtsDepartment::create(['name' => 'ICT', 'directorate_id' => $this->directorate->id, 'is_active' => true]);
    $this->office = PdtsOffice::create(['name' => 'ICT Office', 'department_id' => $this->department->id, 'is_active' => true]);

    $setup = app(TemplateSetup::class);
    $stored = $setup->storeOriginal(database_path('seeders/templates/ZESCO_Memorandum_Template.docx'), 'docx');
    $this->memo = $setup->save(PdtsDocumentTemplate::create([
        'name' => 'Internal Memorandum', 'kind' => 'docx', 'document_type_id' => $this->type->id,
        'original_path' => $stored['path'], 'original_filename' => 'memo.docx',
    ]), ['page' => 'first', 'x' => 10, 'y' => 10, 'size' => 18])->fresh();
});

function measureMemo(User $author): PdtsDocument
{
    Livewire::actingAs($author)->test(InternalDocument::class)
        ->set('template_id', (string) test()->memo->id)
        ->set('to', 'Director ICT')
        ->set('subject', 'Request to purchase ICT equipment')
        ->set('custom.text_body', 'We request approval to buy twelve laptops.')
        ->set('directorate_id', (string) test()->directorate->id)
        ->set('department_id', (string) test()->department->id)
        ->set('office_id', (string) test()->office->id)
        ->call('save')->assertHasNoErrors();

    return PdtsDocument::query()->latest('id')->first();
}

function measureVerifiedSigner(User $user): void
{
    $profile = app(SignatureProfile::class);
    $profile->saveImage($user, UploadedFile::fake()->createWithContent('sig.png', TemplateSetup::sampleSignature()), 'login-pass');
    $profile->setSecret($user->fresh(), 'login-pass', 'pin', '2468');
    ictApprove($user);
}

test('the dashboard shows management how documents are made: signed in PDTS, signed on paper, or received on paper', function () {
    measureVerifiedSigner($this->author);

    // One memo signed in PDTS.
    $signed = measureMemo($this->author);
    Livewire::actingAs($this->author)->test(Show::class, ['document' => $signed])
        ->set('reviewed', true)->set('signaturePin', '2468')->call('signElectronically')->assertHasNoErrors();

    // One memo printed, signed on paper and scanned back.
    $paper = measureMemo($this->author);
    app(InternalDocuments::class)->attachSignedScan($paper, $this->author, UploadedFile::fake()->create('signed.pdf', 50, 'application/pdf'));

    // One letter received on paper, and a draft and a cancelled document that do not count.
    PdtsDocument::create(['reference_number' => 'OMTS-2026-0990', 'title' => 'Letter from ERB', 'subject' => 'Letter', 'document_type_id' => $this->type->id, 'originating_office' => 'Registry',
        'originating_directorate' => 'Corporate Services', 'registered_by' => $this->author->id, 'current_custodian_id' => $this->author->id,
        'status' => 'registered', 'registered_at' => now()]);
    measureMemo($this->author);
    PdtsDocument::create(['reference_number' => 'OMTS-2026-0991', 'title' => 'Error', 'subject' => 'Error', 'document_type_id' => $this->type->id, 'originating_office' => 'Registry',
        'registered_by' => $this->author->id, 'current_custodian_id' => $this->author->id, 'status' => 'cancelled', 'registered_at' => now()]);

    $summary = app(DocumentOrigins::class)->summary();
    expect($summary['overall'])->toMatchArray(['electronic' => 1, 'paper_signed' => 1, 'received_on_paper' => 1, 'total' => 3, 'electronicShare' => 50])
        ->and(end($summary['monthly'])['label'])->toBe(now()->format('M Y'))
        ->and(end($summary['monthly'])['total'])->toBe(3)
        ->and($summary['byDirectorate'][0])->toMatchArray(['label' => 'Corporate Services', 'total' => 3])
        ->and($summary['signatures'])->toMatchArray(['officers' => 1, 'officersVerified' => 1]);

    // The figures are no longer shown on the dashboard (taken off 2026-11-24); the report itself stays.
    Livewire::actingAs($this->manager)->test(Dashboard::class)->assertDontSeeHtml('data-test="document-origins"')->assertDontSee('How Documents Are Made');
});

test('paper signing can be limited to people without a verified signature, or switched off', function () {
    $memo = measureMemo($this->author);
    $scan = fn () => UploadedFile::fake()->create('signed.pdf', 50, 'application/pdf');

    // Fallback: no verified signature yet, so paper is still offered.
    config(['pdts.signatures.paper_signing' => 'fallback']);
    expect(app(InternalDocuments::class)->paperSigningBlocker($memo))->toBeNull();

    // Once the author's signature is verified, they sign in PDTS.
    measureVerifiedSigner($this->author);
    expect(app(InternalDocuments::class)->paperSigningBlocker($memo->fresh()))->toContain('has a verified signature');
    Livewire::actingAs($this->author->fresh())->test(Show::class, ['document' => $memo->fresh()])
        ->assertDontSee('Upload signed scan')->assertSee('Apply my signature');

    // Off: refused even for someone without a verified signature.
    config(['pdts.signatures.paper_signing' => 'off']);
    expect(fn () => app(InternalDocuments::class)->attachSignedScan($memo->fresh(), $this->author, $scan()))
        ->toThrow(RuntimeException::class, 'Signing on paper is switched off');
    expect($memo->fresh()->isDraft())->toBeTrue();

    // Allowed (the default): both ways are offered.
    config(['pdts.signatures.paper_signing' => 'allowed']);
    Livewire::actingAs($this->author->fresh())->test(Show::class, ['document' => $memo->fresh()])
        ->assertSee('Upload signed scan')->assertSee('Apply my signature');
});
