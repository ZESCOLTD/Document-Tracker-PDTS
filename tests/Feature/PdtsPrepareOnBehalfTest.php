<?php

use App\Livewire\Pdts\Documents\InternalDocument;
use App\Livewire\Pdts\Documents\Show;
use App\Livewire\Pdts\Organization\Delegations;
use App\Livewire\Pdts\Signatures\SignatureRequests;
use App\Livewire\Settings\MySignature;
use App\Models\DocumentMovement;
use App\Models\PdtsDepartment;
use App\Models\PdtsDirectorate;
use App\Models\PdtsDocument;
use App\Models\PdtsDocumentTemplate;
use App\Models\PdtsDocumentType;
use App\Models\PdtsOffice;
use App\Models\PdtsUserDelegation;
use App\Models\User;
use App\Notifications\SignatureRequestNotification;
use App\Services\Documents\CustodyService;
use App\Services\Documents\WorkCounts;
use App\Services\Pdts\InternalDocuments;
use App\Services\Pdts\Signatures\ElectronicSigning;
use App\Services\Pdts\Signatures\PdfConverter;
use App\Services\Pdts\Signatures\PreparedDocuments;
use App\Services\Pdts\Templates\TemplateSetup;
use Database\Seeders\PdtsAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/../Support/docx.php';
require_once __DIR__.'/../Support/signatures.php';

uses(RefreshDatabase::class);

/** Stands in for LibreOffice. */
class PrepareTestPdfConverter extends PdfConverter
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
    app()->instance(PdfConverter::class, new PrepareTestPdfConverter);

    foreach (['documents.register', 'documents.view', 'documents.forward', 'documents.acknowledge', 'delegations.manage', 'documents.authorise_preparers'] as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $make = function (string $manNo, string $name, string $title, string $grade) {
        $user = User::factory()->create(['man_no' => $manNo, 'name' => $name, 'job_title' => $title, 'grade' => $grade, 'password' => Hash::make('login-pass')]);
        $user->givePermissionTo(['documents.register', 'documents.view', 'documents.forward', 'documents.acknowledge']);

        return $user;
    };
    $this->boss = $make('BOSS-01', 'Grace Mwila', 'Director - Investments & Finance', 'ML2');
    $this->secretary = $make('SEC-01', 'Mary Banda', 'Personal Secretary', 'ML8');
    $this->colleague = $make('COL-01', 'John Phiri', 'Accountant', 'ML6'); // same directorate, no authorisation

    $this->type = PdtsDocumentType::create(['name' => 'Memorandum', 'is_active' => true]);
    $this->directorate = PdtsDirectorate::create(['name' => 'Investments & Finance', 'is_active' => true]);
    $this->department = PdtsDepartment::create(['name' => 'Finance', 'directorate_id' => $this->directorate->id, 'is_active' => true]);
    $this->office = PdtsOffice::create(['name' => 'Finance Head Office', 'department_id' => $this->department->id, 'is_active' => true]);

    $setup = app(TemplateSetup::class);
    $stored = $setup->storeOriginal(database_path('seeders/templates/ZESCO_Memorandum_Template.docx'), 'docx');
    $this->template = $setup->save(PdtsDocumentTemplate::create([
        'name' => 'Internal Memorandum', 'kind' => 'docx', 'document_type_id' => $this->type->id,
        'original_path' => $stored['path'], 'original_filename' => 'memo.docx',
    ]), ['page' => 'first', 'x' => 10, 'y' => 10, 'size' => 18])->fresh();
});

function prepareSignaturePng(): string
{
    $raw = '';
    for ($y = 0; $y < 140; $y++) {
        $raw .= "\0";
        for ($x = 0; $x < 360; $x++) {
            $raw .= abs($y - (int) (70 + sin($x / 20) * 35)) < 3 ? "\x10\x20\x80\xff" : "\0\0\0\0";
        }
    }
    $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

    return "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', 360, 140, 8, 6, 0, 0, 0)).$chunk('IDAT', gzcompress($raw)).$chunk('IEND', '');
}

function setUpSignature(User $user): void
{
    Livewire::actingAs($user)->test(MySignature::class)
        ->set('image', UploadedFile::fake()->createWithContent('signature.png', prepareSignaturePng()))
        ->set('imageConfirmation', 'login-pass')->call('saveImage')->assertHasNoErrors()
        ->set('password', 'login-pass')->set('secret', '2468')->set('secret_confirmation', '2468')->call('saveSecret')->assertHasNoErrors();

    ictApprove($user);
}

function authorise(User $officer, User $preparer): void
{
    // As the officer does it: search, click the person in the list, see them chosen, authorise.
    Livewire::actingAs($officer)->test(MySignature::class)
        ->set('preparerSearch', $preparer->man_no)
        ->assertSee($preparer->name)
        ->call('choosePreparer', $preparer->id)
        ->assertSet('preparerId', (string) $preparer->id)
        ->assertSeeHtml('data-test="chosen-preparer"')
        ->call('grantPreparer')
        ->assertHasNoErrors();
}

function prepareMemo(User $preparer, ?User $signatory = null, string $body = 'We request approval to buy twelve laptops.'): PdtsDocument
{
    Livewire::actingAs($preparer)->test(InternalDocument::class)
        ->set('template_id', (string) test()->template->id)
        ->set('signatory_id', (string) ($signatory ?? $preparer)->id)
        ->set('to', 'Managing Director')
        ->set('subject', 'Procurement of laptops')
        ->set('custom.text_body', $body)
        ->set('directorate_id', (string) test()->directorate->id)
        ->set('department_id', (string) test()->department->id)
        ->set('office_id', (string) test()->office->id)
        ->call('save')
        ->assertHasNoErrors();

    return PdtsDocument::query()->latest('id')->first();
}

test('nobody can prepare for an officer without an explicit authorisation, not even a colleague in the same directorate', function () {
    expect(app(PreparedDocuments::class)->officersFor($this->secretary))->toBeEmpty();

    Livewire::actingAs($this->secretary)->test(InternalDocument::class)
        ->assertDontSeeHtml('data-test="signatory-choice"')
        ->set('template_id', (string) $this->template->id)
        ->set('signatory_id', (string) $this->boss->id)
        ->set('to', 'Managing Director')->set('subject', 'X')
        ->set('directorate_id', (string) $this->directorate->id)->set('office_id', (string) $this->office->id)
        ->call('save')
        ->assertHasErrors('signatory_id');

    expect(PdtsDocument::count())->toBe(0);

    // An authorisation to receive documents does not let anyone prepare them.
    PdtsUserDelegation::create(['principal_user_id' => $this->boss->id, 'delegate_user_id' => $this->colleague->id, 'can_receive' => true, 'can_prepare' => false, 'granted_by' => $this->boss->id, 'starts_at' => now()->subDay()]);
    expect(app(PreparedDocuments::class)->canPrepareFor($this->colleague, $this->boss->id))->toBeFalse();
});

test('the secretary prepares a memo for the boss: the boss is the signatory, the secretary the preparer and custodian', function () {
    authorise($this->boss, $this->secretary);

    Livewire::actingAs($this->secretary)->test(InternalDocument::class)
        ->assertSeeHtml('data-test="signatory-choice"')
        ->assertSee('Grace Mwila');

    $document = prepareMemo($this->secretary, $this->boss);

    expect($document->registered_by)->toBe($this->secretary->id)
        ->and($document->signatory_id)->toBe($this->boss->id)
        ->and($document->isPreparedOnBehalf())->toBeTrue()
        ->and($document->signature_status)->toBe(PdtsDocument::SIGNATURE_WITH_PREPARER)
        ->and($document->current_custodian_id)->toBe($this->secretary->id)
        ->and($document->template_data['from'])->toBe('Director - Investments & Finance')
        ->and($document->versions)->toHaveCount(1);

    // The memo carries the boss's name, not the secretary's.
    $xml = pdtsZipPart(app(InternalDocuments::class)->wordFile($document->fresh())['contents'], 'word/document.xml');
    expect($xml)->toContain('GRACE MWILA')->not->toContain('MARY BANDA');
});

test('send, return with a comment, correct, send again and sign: custody and movements are never touched', function () {
    setUpSignature($this->boss);
    authorise($this->boss, $this->secretary);
    $document = prepareMemo($this->secretary, $this->boss);
    $movementsBefore = DocumentMovement::count();

    // The secretary has no signing box; the boss cannot sign before it is sent.
    Livewire::actingAs($this->secretary)->test(Show::class, ['document' => $document])
        ->assertDontSeeHtml('data-test="sign-electronically"')
        ->assertSee('Send to Grace Mwila for signature')
        ->call('sendForSignature')->assertHasNoErrors();

    expect($document->fresh()->signature_status)->toBe(PdtsDocument::SIGNATURE_AWAITING);
    Notification::assertSentTo($this->boss, SignatureRequestNotification::class, fn ($n) => $n->event === 'awaiting');
    Livewire::actingAs($this->boss)->test(SignatureRequests::class)->assertSee($document->reference_number)->assertSee('Prepared by Mary Banda');
    expect(SignatureRequests::actionCount($this->boss->id))->toBe(1);

    // The boss returns it; a comment is required.
    $page = Livewire::actingAs($this->boss)->test(Show::class, ['document' => $document])->assertSee('Review and sign');
    $page->call('returnForCorrection')->assertHasErrors('returnComment');
    $page->set('returnComment', 'Add the total cost to the body.')->call('returnForCorrection')->assertHasNoErrors();

    expect($document->fresh()->signature_status)->toBe(PdtsDocument::SIGNATURE_RETURNED);
    Notification::assertSentTo($this->secretary, SignatureRequestNotification::class, fn ($n) => $n->event === 'returned' && $n->comment === 'Add the total cost to the body.');
    expect(fn () => app(ElectronicSigning::class)->sign($document->fresh(), $this->boss, '2468'))->toThrow(RuntimeException::class, 'You returned this draft');

    // The secretary corrects it: a new version, with what changed.
    $this->actingAs($this->secretary)->get(route('pdts.documents.internal.edit', $document))->assertOk()->assertSee('Add the total cost to the body.');
    Livewire::actingAs($this->secretary)->test(InternalDocument::class, ['document' => $document->fresh()])
        ->set('custom.text_body', "We request approval to buy twelve laptops.\nThe total cost is K 240,000.")
        ->call('save')->assertHasNoErrors();

    $document->refresh();
    $latest = $document->versions()->first();
    expect($document->signature_status)->toBe(PdtsDocument::SIGNATURE_WITH_PREPARER)
        ->and($latest->version)->toBe(2)
        ->and($latest->reason)->toBe('corrected')
        ->and($latest->changed_fields)->toBe(['Body'])
        ->and($document->versions()->where('version', 1)->first()->data['template_data']['custom']['text_body'])->toBe('We request approval to buy twelve laptops.');

    Livewire::actingAs($this->secretary)->test(Show::class, ['document' => $document])->call('sendForSignature')->assertHasNoErrors();

    // The boss reviews and signs with his own PIN.
    Livewire::actingAs($this->boss)->test(Show::class, ['document' => $document->fresh()])
        ->set('signaturePin', '2468')->call('signElectronically')->assertHasErrors('reviewed')
        ->set('reviewed', true)->set('signaturePin', '2468')->call('signElectronically')->assertHasNoErrors();

    $document->refresh();
    expect($document->isDraft())->toBeFalse()
        ->and($document->signature_status)->toBe(PdtsDocument::SIGNATURE_SIGNED)
        ->and($document->electronicSignature->user_id)->toBe($this->boss->id)
        ->and($document->registered_by)->toBe($this->secretary->id)
        ->and($document->current_custodian_id)->toBe($this->secretary->id) // the secretary holds it and sends it on
        ->and(DocumentMovement::count())->toBe($movementsBefore) // nothing in the draft stage was a handover
        ->and($document->auditLogs->pluck('action')->all())->toContain('draft_created', 'sent_for_signature', 'returned_for_correction', 'draft_edited', 'signed_electronically', 'registered')
        ->and($document->auditLogs->firstWhere('action', 'signed_electronically')->details['prepared_by'])->toBe($this->secretary->id);
    Notification::assertSentTo($this->secretary, SignatureRequestNotification::class, fn ($n) => $n->event === 'signed');

    // Movement tracking from here is exactly as before: the secretary forwards it.
    expect(Livewire::actingAs($this->secretary)->test(Show::class, ['document' => $document])->viewData('canForward'))->toBeTrue();
    $movement = app(CustodyService::class)->forward($document, $this->secretary, $this->colleague, 'For your action');
    expect($movement->sender_id)->toBe($this->secretary->id)
        ->and(DocumentMovement::count())->toBe($movementsBefore + 1);

    // Signed: immutable.
    $this->actingAs($this->secretary)->get(route('pdts.documents.internal.edit', $document))->assertForbidden();
    $this->actingAs($this->boss)->get(route('pdts.documents.word', $document))->assertForbidden();
});

test('only the designated signatory can sign; the secretary never can', function () {
    setUpSignature($this->secretary);
    authorise($this->boss, $this->secretary);
    $document = prepareMemo($this->secretary, $this->boss);
    app(PreparedDocuments::class)->sendForSignature($document, $this->secretary);

    expect(fn () => app(ElectronicSigning::class)->sign($document->fresh(), $this->secretary, '2468'))
        ->toThrow(RuntimeException::class, 'Only Grace Mwila can sign this document.');
    expect($document->fresh()->isDraft())->toBeTrue();
});

test('ending an authorisation takes effect at once; the boss can still sign or discard the draft', function () {
    authorise($this->boss, $this->secretary);
    $document = prepareMemo($this->secretary, $this->boss);

    $delegation = PdtsUserDelegation::query()->preparing()->sole();
    Livewire::actingAs($this->boss)->test(MySignature::class)->call('revokePreparer', $delegation->id);

    expect(app(PreparedDocuments::class)->canEdit($document->fresh(), $this->secretary))->toBeFalse();
    $this->actingAs($this->secretary)->get(route('pdts.documents.internal.edit', $document))->assertForbidden();
    Livewire::actingAs($this->secretary)->test(Show::class, ['document' => $document])
        ->assertSee('You are no longer authorised to prepare documents for Grace Mwila')
        ->assertDontSee('Send to Grace Mwila for signature');
    expect(fn () => app(PreparedDocuments::class)->sendForSignature($document->fresh(), $this->secretary))->toThrow(RuntimeException::class);

    Livewire::actingAs($this->boss)->test(Show::class, ['document' => $document])->call('discardDraft')->assertHasNoErrors();
    expect($document->fresh()->signature_status)->toBe('discarded')
        ->and($this->boss->signatureEvents()->pluck('action')->all())->toContain('preparer_granted', 'preparer_revoked');
});

test('an authorisation can have an end date; after it the secretary can no longer prepare for the boss', function () {
    $prepared = app(PreparedDocuments::class);
    $prepared->grant($this->boss, $this->secretary, now()->subDays(10), now()->subDay(), null, $this->boss);
    expect($prepared->canPrepareFor($this->secretary, $this->boss->id))->toBeFalse();

    $prepared->grant($this->boss, $this->secretary, now()->subHour(), now()->addDays(5), null, $this->boss);
    expect($prepared->canPrepareFor($this->secretary, $this->boss->id))->toBeTrue();

    // The secretary can give it up herself.
    $delegation = PdtsUserDelegation::query()->preparing()->whereNull('revoked_at')->where('expires_at', '>', now())->sole();
    Livewire::actingAs($this->secretary)->test(MySignature::class)->assertSee('Officers I prepare documents for')->call('revokePreparer', $delegation->id);
    expect($prepared->canPrepareFor($this->secretary, $this->boss->id))->toBeFalse();
});

test('a "prepare" authorisation never lets someone receive the officer\'s documents', function () {
    authorise($this->boss, $this->secretary);

    $document = PdtsDocument::create([
        'reference_number' => 'OMTS-2026-0500', 'title' => 'Letter', 'subject' => 'Letter', 'document_type_id' => $this->type->id,
        'originating_office' => 'Registry', 'registered_by' => $this->colleague->id, 'current_custodian_id' => $this->colleague->id,
        'status' => 'registered', 'registered_at' => now(),
    ]);
    $movement = app(CustodyService::class)->forward($document, $this->colleague, $this->boss, 'For you');

    expect(app(WorkCounts::class)->pendingAcknowledgementsQuery($this->secretary)->count())->toBe(0)
        ->and(app(CustodyService::class)->isRecipientOrDelegate($movement, $this->secretary))->toBeFalse();
});

test('earlier versions can be downloaded by the preparer and the signatory while it is a draft', function () {
    authorise($this->boss, $this->secretary);
    $document = prepareMemo($this->secretary, $this->boss);
    Livewire::actingAs($this->secretary)->test(InternalDocument::class, ['document' => $document])
        ->set('subject', 'Procurement of twelve laptops')->call('save')->assertHasNoErrors();

    $this->actingAs($this->boss)->get(route('pdts.documents.versions.download', [$document, 1]))->assertOk();
    $first = $this->actingAs($this->secretary)->get(route('pdts.documents.versions.download', [$document, 1]));
    $first->assertOk();
    expect(pdtsZipPart($first->getContent(), 'word/document.xml'))->toContain('Procurement of laptops');
    $this->actingAs($this->colleague)->get(route('pdts.documents.versions.download', [$document, 1]))->assertForbidden();

    Livewire::actingAs($this->secretary)->test(Show::class, ['document' => $document])->assertSee('Version 2')->assertSee('Changed: Subject');
});

test('there is no revising a signed document: a correction is a new document', function () {
    setUpSignature($this->boss);
    $original = prepareMemo($this->boss);
    Livewire::actingAs($this->boss)->test(Show::class, ['document' => $original])
        ->set('reviewed', true)->set('signaturePin', '2468')->call('signElectronically')->assertHasNoErrors();

    Livewire::actingAs($this->boss)->test(Show::class, ['document' => $original->fresh()])->assertDontSee('Revise');

    // The old address opens an empty New Internal Document, not a copy of the original.
    $this->actingAs($this->boss)->get(route('pdts.documents.internal.create', ['revision_of' => $original->id]))
        ->assertOk()->assertDontSee('This is a revision of');
    Livewire::actingAs($this->boss)->withQueryParams(['revision_of' => $original->id])->test(InternalDocument::class)
        ->assertSet('subject', '');

    expect(Schema::hasColumn('pdts_documents', 'revision_of_id'))->toBeFalse();
});

test('administrators can grant a prepare-only delegation from Delegations', function () {
    $admin = User::factory()->create(['man_no' => 'ADM-01']);
    $admin->givePermissionTo('delegations.manage');

    Livewire::actingAs($admin)->test(Delegations::class)
        ->set('principalUserId', (string) $this->boss->id)
        ->set('delegateUserId', (string) $this->secretary->id)
        ->set('canReceive', false)->set('canPrepare', true)
        ->call('grant')->assertHasNoErrors()
        ->assertSee('Prepare');

    $delegation = PdtsUserDelegation::sole();
    expect($delegation->can_prepare)->toBeTrue()->and($delegation->can_receive)->toBeFalse()
        ->and(app(PreparedDocuments::class)->canPrepareFor($this->secretary, $this->boss->id))->toBeTrue();
});

test('only Directors, Senior Managers and Managers can have someone prepare documents for them', function () {
    // Officers by grade (Director ML2, Manager ML4) can; others cannot.
    expect($this->boss->canHavePreparers())->toBeTrue()
        ->and($this->colleague->canHavePreparers())->toBeFalse()
        ->and($this->secretary->canHavePreparers())->toBeFalse();
    $manager = User::factory()->create(['man_no' => 'MGR-01', 'grade' => 'ml4']);
    expect($manager->canHavePreparers())->toBeTrue();

    // Others do not see the section, and cannot authorise anyone.
    Livewire::actingAs($this->colleague)->test(MySignature::class)->assertDontSeeHtml('data-test="preparers"');
    Livewire::actingAs($this->colleague)->test(MySignature::class)
        ->set('preparerId', (string) $this->secretary->id)->call('grantPreparer')->assertForbidden();
    expect(fn () => app(PreparedDocuments::class)->grant($this->colleague, $this->secretary, now(), null, null, $this->colleague))
        ->toThrow(RuntimeException::class, 'Only Directors, Senior Managers and Managers');
    Livewire::actingAs($this->boss)->test(MySignature::class)->assertSeeHtml('data-test="preparers"');

    // Nor can an administrator grant it for them.
    $admin = User::factory()->create(['man_no' => 'ADM-02']);
    $admin->givePermissionTo('delegations.manage');
    Livewire::actingAs($admin)->test(Delegations::class)
        ->set('principalUserId', (string) $this->colleague->id)->set('delegateUserId', (string) $this->secretary->id)
        ->set('canReceive', false)->set('canPrepare', true)
        ->call('grant')->assertHasErrors('canPrepare');

    // An exception can be made with the permission, e.g. an acting manager on a lower grade.
    $this->colleague->givePermissionTo('documents.authorise_preparers');
    expect($this->colleague->fresh()->canHavePreparers())->toBeTrue();
});

test('authorisations stop working when the officer is no longer at an officer grade', function () {
    authorise($this->boss, $this->secretary);
    expect(app(PreparedDocuments::class)->canPrepareFor($this->secretary, $this->boss->id))->toBeTrue();

    $this->boss->forceFill(['grade' => 'ML6'])->save();

    expect(app(PreparedDocuments::class)->canPrepareFor($this->secretary, $this->boss->id))->toBeFalse()
        ->and(app(PreparedDocuments::class)->officersFor($this->secretary))->toBeEmpty();
    Livewire::actingAs($this->boss->fresh())->test(MySignature::class)
        ->assertSee('These authorisations no longer work')
        ->assertDontSeeHtml('wire:submit.prevent="grantPreparer"');
});

test('no role carries the preparer exception: a System Administrator below ML4 cannot have preparers', function () {
    $this->seed(PdtsAccessSeeder::class);

    $rolesWithIt = Role::whereHas('permissions', fn ($q) => $q->where('name', 'documents.authorise_preparers'))->pluck('name');
    expect($rolesWithIt)->toBeEmpty();

    $admin = User::factory()->create(['man_no' => 'ADM-ML8', 'grade' => 'ML8']);
    $admin->assignRole('System Administrator');
    $manager = User::factory()->create(['man_no' => 'MGR-ML3', 'grade' => 'ML3']);
    $manager->assignRole('Manager');

    expect($admin->fresh()->canHavePreparers())->toBeFalse()
        ->and($manager->fresh()->canHavePreparers())->toBeTrue();      // by grade

    // The exception is given to a named person on purpose.
    $admin->givePermissionTo('documents.authorise_preparers');
    expect($admin->fresh()->canHavePreparers())->toBeTrue();
});
