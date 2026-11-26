<?php

use App\Livewire\Movements\ForwardDocument;
use App\Livewire\Pdts\Documents\InternalDocument;
use App\Livewire\Pdts\Documents\Show;
use App\Models\DocumentMovement;
use App\Models\PdtsDepartment;
use App\Models\PdtsDirectorate;
use App\Models\PdtsDocument;
use App\Models\PdtsDocumentTemplate;
use App\Models\PdtsDocumentType;
use App\Models\PdtsOffice;
use App\Models\User;
use App\Notifications\DocumentDistributedNotification;
use App\Services\Documents\CustodyService;
use App\Services\Pdts\PositionText;
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
class DecideTestPdfConverter extends PdfConverter
{
    public static array $words = [];

    public function engine(): ?string
    {
        return 'fake';
    }

    public function convert(string $docx): string
    {
        self::$words[] = $docx;

        return "%PDF-1.4\n% ".hash('sha256', $docx)."\n%%EOF\n";
    }
}

beforeEach(function () {
    Storage::fake('private');
    Notification::fake();
    DecideTestPdfConverter::$words = [];
    app()->instance(PdfConverter::class, new DecideTestPdfConverter);

    foreach (['documents.register', 'documents.view', 'documents.forward', 'documents.acknowledge'] as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $make = function (string $manNo, string $name, string $title) {
        $user = User::factory()->create(['man_no' => $manNo, 'name' => $name, 'job_title' => $title, 'password' => Hash::make('login-pass')]);
        $user->givePermissionTo(['documents.register', 'documents.view', 'documents.forward', 'documents.acknowledge']);

        return $user;
    };
    $this->author = $make('AUT-01', 'Teddy Sikazwe', 'Engineer - Software Developer');
    $this->director = $make('DIR-01', 'Grace Mwila', 'Director ICT');
    $this->copied = $make('CC-01', 'John Phiri', 'Manager - Finance');

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

/** A verified signature and PIN 2468, set up the way an employee and ICT would. */
function verifiedSigner(User $user): void
{
    $profile = app(SignatureProfile::class);
    $profile->saveImage($user, UploadedFile::fake()->createWithContent('sig.png', TemplateSetup::sampleSignature()), 'login-pass');
    $profile->setSecret($user->fresh(), 'login-pass', 'pin', '2468');
    ictApprove($user);
}

function distributedMemo(User $author, ?User $for = null, array $copies = []): PdtsDocument
{
    $form = Livewire::actingAs($author)->test(InternalDocument::class)
        ->set('template_id', (string) test()->memo->id)
        ->set('to', 'Director ICT')
        ->set('subject', 'Request to purchase ICT equipment')
        ->set('custom.text_body', 'We request approval to buy twelve laptops.')
        ->set('directorate_id', (string) test()->directorate->id)
        ->set('department_id', (string) test()->department->id)
        ->set('office_id', (string) test()->office->id);
    if ($for) {
        $form->call('chooseRecipient', $for->id);
    }
    foreach ($copies as $person) {
        $form->call('addCopy', $person->id);
    }
    $form->call('save')->assertHasNoErrors();

    return PdtsDocument::query()->latest('id')->first();
}

function signMemo(PdtsDocument $document, User $author): PdtsDocument
{
    Livewire::actingAs($author)->test(Show::class, ['document' => $document])
        ->set('reviewed', true)->set('signaturePin', '2468')->call('signElectronically')->assertHasNoErrors();

    return $document->fresh();
}

test('once signed, the person it is for and the people copied are told; nothing moves', function () {
    verifiedSigner($this->author);
    $document = distributedMemo($this->author, $this->director, [$this->copied]);

    expect($document->recipient_user_id)->toBe($this->director->id)
        ->and($document->cc_user_ids)->toBe([$this->copied->id]);
    // A draft tells nobody.
    Notification::assertNotSentTo($this->director, DocumentDistributedNotification::class);
    Notification::assertNotSentTo($this->copied, DocumentDistributedNotification::class);

    $movements = DocumentMovement::count();
    $document = signMemo($document, $this->author);

    Notification::assertSentTo($this->director, DocumentDistributedNotification::class, fn ($n) => $n->kind === 'attention');
    Notification::assertSentTo($this->copied, DocumentDistributedNotification::class, fn ($n) => $n->kind === 'copy');
    expect(DocumentMovement::count())->toBe($movements)
        ->and($document->current_custodian_id)->toBe($this->author->id)
        ->and($document->auditLogs->pluck('action'))->toContain('distributed');

});

test('Forward suggests the person the document is for, and the card says where the paper is', function () {
    verifiedSigner($this->author);
    $document = signMemo(distributedMemo($this->author, $this->director), $this->author);

    // One action: Forward, already pointed at the person it is for (it can be changed).
    Livewire::actingAs($this->author)->test(Show::class, ['document' => $document])
        ->assertDontSee('Send to Grace Mwila')
        ->assertDontSeeHtml('data-test="paper-status"'); // not sent at all: the label and Forward already say so

    Livewire::actingAs($this->author)->test(ForwardDocument::class, ['document' => $document])
        ->assertSee('Forward to Grace Mwila')
        ->call('openForm')
        ->assertSet('recipientId', $this->director->id)->assertSet('remarks', 'For your attention')
        ->assertSeeHtml('data-test="for-recipient"')
        ->call('forward')->assertHasNoErrors();

    $movement = DocumentMovement::sole();
    expect($movement->sender_id)->toBe($this->author->id)
        ->and($movement->recipient_id)->toBe($this->director->id)
        ->and($movement->remarks)->toBe('For your attention');
    Livewire::actingAs($this->author)->test(Show::class, ['document' => $document->fresh()])->assertSee('On its way to Grace Mwila')->assertDontSee('Paper on its way'); // the bar says it, once

    app(CustodyService::class)->acknowledge($movement, $this->director);
    Livewire::actingAs($this->director)->test(Show::class, ['document' => $document->fresh()])->assertSee('Paper received by Grace Mwila');
    // Once it has reached them, Forward no longer suggests them.
    Livewire::actingAs($this->director)->test(ForwardDocument::class, ['document' => $document->fresh()])
        ->assertDontSee('Forward to Grace Mwila')->call('openForm')->assertSet('recipientId', null);
});

test('a reply starts a new memo to the author, linked both ways', function () {
    verifiedSigner($this->author);
    $original = signMemo(distributedMemo($this->author, $this->director), $this->author);

    Livewire::actingAs($this->director)->test(Show::class, ['document' => $original])->assertSee('Reply');

    Livewire::actingAs($this->director)->withQueryParams(['reply_to' => $original->id])->test(InternalDocument::class)
        ->assertSet('subject', 'RE: Request to purchase ICT equipment')
        ->assertSet('office_reference', $original->reference_number)
        ->assertSet('recipient_user_id', (string) $this->author->id)
        ->set('custom.text_body', 'Approved in principle.')
        ->set('directorate_id', (string) $this->directorate->id)->set('department_id', (string) $this->department->id)->set('office_id', (string) $this->office->id)
        ->call('save')->assertHasNoErrors();

    $reply = PdtsDocument::query()->latest('id')->first();
    expect($reply->reply_to_id)->toBe($original->id)
        ->and($original->fresh()->replies->pluck('id')->all())->toBe([$reply->id]);
    Livewire::actingAs($this->author)->test(Show::class, ['document' => $original->fresh()])->assertSee('Replied in')->assertSee($reply->reference_number);
});

test('on a memo, TO and CC are picked from PDTS: no separate "Who it is for" section, and copies go on the CC line', function () {
    $form = Livewire::actingAs($this->author)->test(InternalDocument::class)->set('template_id', (string) $this->memo->id);

    // No extra boxes and no separate section: TO and CC themselves find people. Nothing shows until something is typed.
    $form->assertDontSeeHtml('data-test="who-it-is-for"')->assertDontSeeHtml('id="recipient-search"')->assertDontSeeHtml('id="cc-search"')
        ->assertDontSeeHtml('data-test="to-suggestions"');

    // TO: typing "Grace" suggests her; picking her fills TO with her title and makes her the person it is for.
    $form->set('to', 'Grace')->assertSeeHtml('data-test="to-suggestions"')->assertSee('Grace Mwila · Director ICT')
        ->call('chooseRecipient', $this->director->id)
        ->assertSet('to', 'Director ICT')->assertSet('recipient_user_id', (string) $this->director->id)
        ->assertSeeHtml('data-test="recipient-chosen"')->assertDontSeeHtml('data-test="to-suggestions"');

    // CC: the line being typed suggests people; picking one turns that line into their title.
    $form->set('cc', "Managing Director\nJohn")->assertSeeHtml('data-test="cc-suggestions"')
        ->call('copyFromLine', $this->copied->id)->assertSet('cc', "Managing Director\nManager - Finance")
        ->assertSet('cc_user_ids', [$this->copied->id])->assertSeeHtml('data-test="copies-chosen"')
        ->call('removeCopy', $this->copied->id);

    // CC: a person copied adds their title to the CC lines, once; removing them takes it off again.
    $form->set('cc', 'Managing Director')
        ->call('addCopy', $this->copied->id)->assertSet('cc', "Managing Director\nManager - Finance")
        ->call('addCopy', $this->copied->id)->assertSet('cc', "Managing Director\nManager - Finance")
        ->call('removeCopy', $this->copied->id)->assertSet('cc', 'Managing Director')->assertSet('cc_user_ids', []);

    // Typing a name outside PDTS still works: only the paper says it.
    $form->set('cc', "Managing Director\nThe Sales Manager, Zamtel")->call('addCopy', $this->copied->id)
        ->assertSet('cc', "Managing Director\nThe Sales Manager, Zamtel\nManager - Finance");
});

test('no template has a separate "Who it is for": a letter\'s addressee line finds the person, a report has nobody to name', function () {
    $setup = app(TemplateSetup::class);
    $upload = function (string $name, string $body) use ($setup) {
        $file = tempnam(sys_get_temp_dir(), 'tpl');
        file_put_contents($file, pdtsTestDocx($body));
        $stored = $setup->storeOriginal($file, 'docx');

        return $setup->save(PdtsDocumentTemplate::create(['name' => $name, 'kind' => 'docx', 'document_type_id' => $this->type->id,
            'original_path' => $stored['path'], 'original_filename' => $name.'.docx', 'is_active' => true]), ['page' => 'first', 'x' => 10, 'y' => 10, 'size' => 16]);
    };
    $n = fn ($t) => '<w:r><w:t xml:space="preserve">'.$t.'</w:t></w:r>';
    $letter = $upload('Letter', $n('[Mr./Ms. Full Name]').'</w:p><w:p>'.$n('[Position / Organisation, if any]').'</w:p><w:p>'.$n('[SUBJECT]').'</w:p><w:p>'.$n('[BODY]').'</w:p><w:p>'.$n('[FULL NAME]'));
    $report = $upload('Notice', $n('[SUBJECT]').'</w:p><w:p>'.$n('[BODY]').'</w:p><w:p>'.$n('[FULL NAME]'));

    // A letter: typing the addressee suggests people; picking one fills the name and position, and links them.
    Livewire::actingAs($this->author)->test(InternalDocument::class)->set('template_id', (string) $letter->id)
        ->assertDontSeeHtml('data-test="who-it-is-for"')->assertSeeHtml('data-test="addressee-in-pdts"')
        ->set('custom.recipient_name', 'Grace')->assertSeeHtml('data-test="addressee-suggestions"')
        ->call('chooseRecipient', $this->director->id)
        ->assertSet('custom.recipient_name', 'Grace Mwila')->assertSet('custom.position_organisation', 'Director ICT')
        ->assertSet('recipient_user_id', (string) $this->director->id)->assertSeeHtml('data-test="recipient-chosen"');

    // A document with no addressee line: nothing to fill in here; it reaches people by forwarding.
    Livewire::actingAs($this->author)->test(InternalDocument::class)->set('template_id', (string) $report->id)
        ->assertDontSeeHtml('data-test="who-it-is-for"')->assertDontSeeHtml('data-test="addressee-in-pdts"')->assertDontSeeHtml('data-test="to-in-pdts"');
});

test('job titles from the staff records in capitals go on the memo in ordinary capitals; typed text is left as typed', function () {
    $this->author->update(['job_title' => 'ENGINEER - SOFTWARE DEVELOPER']);
    $this->director->update(['job_title' => 'PRINCIPAL ENGINEER - AI SOLUTIONS & DIGITAL PRODUCTS']);
    $this->copied->update(['job_title' => 'PRINCIPAL SYSTEMS DEVELOPER - SOFTWARE DEVELOPMENT & COMMERCIAL SERVICES']);

    Livewire::actingAs($this->author->fresh())->test(InternalDocument::class)->set('template_id', (string) $this->memo->id)
        ->assertSet('from', 'Engineer - Software Developer')
        ->call('chooseRecipient', $this->director->id)->assertSet('to', 'Principal Engineer - AI Solutions & Digital Products')
        ->set('cc', 'HEAD OF SHEQ (typed)')
        ->call('addCopy', $this->copied->id)->assertSet('cc', "HEAD OF SHEQ (typed)\nPrincipal Systems Developer - Software Development & Commercial Services")
        ->call('removeCopy', $this->copied->id)->assertSet('cc', 'HEAD OF SHEQ (typed)');

    expect(PositionText::tidy('HEAD OF SHEQ'))->toBe('Head of SHEQ')
        ->and(PositionText::tidy('ENGINEER II (MV/LV)'))->toBe('Engineer II (MV/LV)')
        ->and(PositionText::tidy('Director ICT'))->toBe('Director ICT');
});

test('after signing and forwarding, the page says each thing once: one activity entry for signing, and where it is going in the bar only', function () {
    verifiedSigner($this->author);
    $document = signMemo(distributedMemo($this->author, $this->director, [$this->copied]), $this->author);

    // Signed, registered and the people told: one entry, with who was told.
    $page = Livewire::actingAs($this->author)->test(Show::class, ['document' => $document->fresh()])
        ->assertSee('Signed electronically and registered')->assertSee('Told in PDTS: ')
        ->assertDontSee('Sent to the people it is for');
    expect(substr_count(strip_tags($page->html()), 'Signed electronically and registered'))->toBe(1);

    // Forwarded from the document page: no note of its own; the bar says where it is going, once.
    Livewire::actingAs($this->author)->test(ForwardDocument::class, ['document' => $document->fresh(), 'quiet' => true])
        ->call('openForm')->call('forward')->assertHasNoErrors()->assertSet('notice', null)->assertDontSee('Waiting for receipt');
    Livewire::actingAs($this->author)->test(Show::class, ['document' => $document->fresh()])
        ->assertSee('On its way to Grace Mwila')->assertSee('You stay responsible until it is acknowledged.')
        ->assertDontSee('Paper on its way')->assertDontSee('You remain the custodian');
});
