<?php

use App\Livewire\Pdts\Documents\Registration;
use App\Models\PdtsDepartment;
use App\Models\PdtsDocument;
use App\Models\PdtsDocumentType;
use App\Models\PdtsOffice;
use App\Models\User;
use App\Services\Pdts\PdtsReferenceNumberGenerator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    Storage::fake('private');

    Permission::firstOrCreate([
        'name' => 'documents.register',
        'guard_name' => 'web',
    ]);
    Permission::firstOrCreate([
        'name' => 'documents.view',
        'guard_name' => 'web',
    ]);

    $this->registrar = User::factory()->create([
        'man_no' => 'REG-0001',
        'name' => 'Registration Test User',
    ]);
    $this->registrar->givePermissionTo(['documents.register', 'documents.view']);
    $this->actingAs($this->registrar);

    $this->documentType = PdtsDocumentType::create([
        'name' => 'Memorandum',
        'is_active' => true,
    ]);

    $this->department = PdtsDepartment::create(['name' => 'Operations', 'is_active' => true]);
    $this->office = PdtsOffice::create(['name' => 'Lusaka Main Office', 'department_id' => $this->department->id, 'is_active' => true]);
});

test('reference numbers increment within the configured year and prefix', function () {
    $generator = app(PdtsReferenceNumberGenerator::class);
    $year = now()->format('Y');

    expect($generator->next())->toBe("OMTS-{$year}-0001")
        ->and($generator->next())->toBe("OMTS-{$year}-0002");
});

test('authorized employee can register a document with a unique reference and private scan', function () {
    $file = UploadedFile::fake()->createWithContent(
        'field-inspection.pdf',
        "%PDF-1.4\nPDTS registration test\n%%EOF",
    );

    Livewire::test(Registration::class)
        ->set('title', 'Field inspection memorandum')
        ->set('subject', 'Inspection results')
        ->set('description', 'Results from the northern area inspection.')
        ->set('document_type_id', (string) $this->documentType->id)
        ->set('department_id', (string) $this->department->id)
        ->set('office_id', (string) $this->office->id)
        ->set('attachment', $file)
        ->call('save')
        ->assertHasNoErrors();

    $document = PdtsDocument::query()->with('attachments')->sole();

    expect($document->reference_number)->toBe('OMTS-'.now()->format('Y').'-0001')
        ->and($document->status)->toBe('registered')
        ->and($document->registered_by)->toBe($this->registrar->id)
        ->and($document->current_custodian_id)->toBe($this->registrar->id)
        ->and($document->attachments)->toHaveCount(1)
        ->and($document->originating_department)->toBe('Operations')
        ->and($document->originating_office)->toBe('Lusaka Main Office');

    Storage::disk('private')->assertExists($document->attachments->sole()->storage_path);

    $this->assertDatabaseHas('pdts_document_audit_logs', [
        'pdts_document_id' => $document->id,
        'actor_id' => $this->registrar->id,
        'action' => 'registered',
    ]);

    $attachment = $document->attachments->sole();
    $this->get(route('pdts.documents.show', $document))->assertOk();
    $this->get(route('pdts.documents.attachments.download', [$document, $attachment]))->assertOk();
});

test('document registration route is restricted to users with registration permission', function () {
    $this->get(route('pdts.documents.register'))->assertOk();

    $viewer = User::factory()->create([
        'man_no' => 'VIEW-0001',
    ]);
    Permission::firstOrCreate([
        'name' => 'documents.view',
        'guard_name' => 'web',
    ]);
    $viewer->givePermissionTo('documents.view');

    $this->actingAs($viewer)
        ->get(route('pdts.documents.register'))
        ->assertForbidden();
});
