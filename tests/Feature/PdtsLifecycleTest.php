<?php

use App\Livewire\Movements\ForwardDocument;
use App\Livewire\Pdts\Dashboard;
use App\Livewire\Pdts\Documents\Archive;
use App\Livewire\Pdts\Documents\InternalDocument;
use App\Livewire\Pdts\Documents\Show;
use App\Livewire\Pdts\Organization\TemplateBuilder;
use App\Models\DocumentMovement;
use App\Models\PdtsDepartment;
use App\Models\PdtsDirectorate;
use App\Models\PdtsDocument;
use App\Models\PdtsDocumentTemplate;
use App\Models\PdtsDocumentType;
use App\Models\PdtsOffice;
use App\Models\User;
use App\Services\Documents\CustodyService;
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
class LifecycleTestPdfConverter extends PdfConverter
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

/** A verified signature and PIN 1357, set up the way an employee and ICT would. */
function lifecycleSigner(User $user): void
{
    $profile = app(SignatureProfile::class);
    $profile->saveImage($user, UploadedFile::fake()->createWithContent('sig.png', TemplateSetup::sampleSignature()), 'login-pass');
    $profile->setSecret($user->fresh(), 'login-pass', 'pin', '1357');
    ictApprove($user);
}

test('a document goes through its whole life: built template, written, signed, handed over, decided, filed and counted', function () {
    Storage::fake('private');
    Notification::fake();
    app()->instance(PdfConverter::class, new LifecycleTestPdfConverter);

    $all = ['documents.register', 'documents.view', 'documents.forward', 'documents.acknowledge', 'document_types.manage', 'reports.view'];
    foreach ($all as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }
    $admin = User::factory()->create(['man_no' => 'LC-00', 'name' => 'Template Admin', 'password' => Hash::make('login-pass')]);
    $admin->givePermissionTo($all);
    $author = User::factory()->create(['man_no' => 'LC-01', 'name' => 'Teddy Sikazwe', 'job_title' => 'Engineer', 'password' => Hash::make('login-pass')]);
    $author->givePermissionTo(['documents.register', 'documents.view', 'documents.forward', 'documents.acknowledge']);
    $director = User::factory()->create(['man_no' => 'LC-02', 'name' => 'Grace Mwila', 'job_title' => 'Director ICT', 'grade' => 'ML1', 'password' => Hash::make('login-pass')]);
    $director->givePermissionTo(['documents.view', 'documents.forward', 'documents.acknowledge']);

    $type = PdtsDocumentType::create(['name' => 'Request', 'is_active' => true, 'retention_years' => 5]);
    $directorate = PdtsDirectorate::create(['name' => 'Corporate Services', 'is_active' => true]);
    $department = PdtsDepartment::create(['name' => 'ICT', 'directorate_id' => $directorate->id, 'is_active' => true]);
    $office = PdtsOffice::create(['name' => 'ICT Office', 'department_id' => $department->id, 'is_active' => true]);

    // 1. The administrator builds a leave request: a choice, a person and formatted text.
    Livewire::actingAs($admin)->test(TemplateBuilder::class)
        ->set('name', 'Leave Request')->set('description', 'For staff applying for leave.')->set('documentTypeId', (string) $type->id)
        ->set('design.items.4.type', 'rich')                                   // the body becomes formatted text
        ->call('addItem', 'question', 'choice', 4)->set('design.items.4.label', 'Type of leave')->set('design.items.4.choices', 'Annual, Sick')->set('design.items.4.required', true)
        ->call('addItem', 'question', 'person', 5)->set('design.items.5.label', 'Acting officer')
        ->call('save')->assertHasNoErrors();
    $template = PdtsDocumentTemplate::sole();
    expect($template->is_active)->toBeTrue()->and($template->supportsElectronicSignature())->toBeTrue();

    // 2. The author writes it to the director.
    lifecycleSigner($author);
    lifecycleSigner($director);
    Livewire::actingAs($author)->test(InternalDocument::class)
        ->assertSee('For staff applying for leave.')
        ->set('template_id', (string) $template->id)
        ->set('to', 'Director ICT')->set('subject', 'Annual leave in December')
        ->set('custom.type_of_leave', 'Annual')
        ->call('choosePerson', 'acting_officer', $admin->id)
        ->set('custom.text_body', '<p>I request <b>ten days</b> of leave:</p><ul><li>From 1 December</li><li>To 12 December</li></ul>')
        ->call('chooseRecipient', $director->id)
        ->set('directorate_id', (string) $directorate->id)->set('department_id', (string) $department->id)->set('office_id', (string) $office->id)
        ->call('save')->assertHasNoErrors();
    $document = PdtsDocument::sole();
    expect($document->isDraft())->toBeTrue()->and($document->reference_number)->not->toBeEmpty();

    // 3. The author signs it in PDTS: it is registered, with the signed PDF as the document.
    Livewire::actingAs($author)->test(Show::class, ['document' => $document])
        ->set('reviewed', true)->set('signaturePin', '1357')->call('signElectronically')->assertHasNoErrors();
    $document->refresh();
    expect($document->status)->toBe('registered')
        ->and($document->electronicSignature)->not->toBeNull()
        ->and($document->primaryAttachment->mime_type)->toBe('application/pdf');

    // 4. The paper goes to the director, who acknowledges it.
    Livewire::actingAs($author)->test(ForwardDocument::class, ['document' => $document])->call('openForm')->assertSet('recipientId', $director->id)->call('forward')->assertHasNoErrors();
    $movement = DocumentMovement::sole();
    app(CustodyService::class)->acknowledge($movement, $director);
    expect($document->fresh()->current_custodian_id)->toBe($director->id);

    // 5. The director files it; it is in the archive with where it is kept and until when.
    app(CustodyService::class)->complete($document->fresh(), $director, 'filed', 'Leave approved and filed', 'HR/LEAVE/2026/12');
    $document->refresh();
    expect($document->status)->toBe('completed')->and($document->retainUntil()->year)->toBe(now()->addYears(5)->year);
    Livewire::actingAs($director)->test(Archive::class)->assertSee($document->reference_number)->assertSee('HR/LEAVE/2026/12');
    // The template administrator was not involved in it: not in their archive.
    Livewire::actingAs($admin)->test(Archive::class)->assertDontSee($document->reference_number);

    // 6. Tracking and the QR code still work; the dashboard opens.
    $this->actingAs($director)->get(route('movements.history', $document))->assertOk()->assertSee($document->reference_number);
    $this->actingAs($director)->get(route('pdts.scan', $document->scan_token))->assertOk()->assertSee($document->reference_number);
    $this->actingAs($author)->get(route('pdts.documents.show', $document))->assertOk();
    Livewire::actingAs($admin)->test(Dashboard::class)->assertOk();

    // 7. Changing the template now makes version 2; this document keeps version 1.
    Livewire::actingAs($admin)->test(TemplateBuilder::class, ['template' => $template])->set('design.items.4.label', 'Leave type')->call('save')->assertHasNoErrors();
    expect(PdtsDocumentTemplate::count())->toBe(2)->and($document->fresh()->template_id)->toBe($template->id);
});
