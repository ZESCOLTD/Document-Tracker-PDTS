<?php

use App\Livewire\Pdts\Documents\InternalDocument;
use App\Livewire\Pdts\Documents\Show;
use App\Livewire\Settings\MySignature;
use App\Livewire\Users\UserEdit;
use App\Models\PdtsDepartment;
use App\Models\PdtsDirectorate;
use App\Models\PdtsDocument;
use App\Models\PdtsDocumentTemplate;
use App\Models\PdtsDocumentType;
use App\Models\PdtsOffice;
use App\Models\User;
use App\Notifications\SignatureChangedNotification;
use App\Services\Pdts\InternalDocuments;
use App\Services\Pdts\Signatures\ElectronicSigning;
use App\Services\Pdts\Signatures\PdfConverter;
use App\Services\Pdts\Signatures\SignatureImageNormaliser;
use App\Services\Pdts\Templates\TemplateSetup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/../Support/docx.php';
require_once __DIR__.'/../Support/signatures.php';

uses(RefreshDatabase::class);

/** Stands in for LibreOffice: keeps the Word file it was given and returns a small PDF. */
class FakePdfConverter extends PdfConverter
{
    public static ?string $lastWord = null;

    public bool $on = true;

    public function engine(): ?string
    {
        return $this->on ? 'fake' : null;
    }

    public function convert(string $docx): string
    {
        self::$lastWord = $docx;

        return "%PDF-1.4\n% signed copy ".hash('sha256', $docx)."\n%%EOF\n";
    }
}

beforeEach(function () {
    Storage::fake('private');
    FakePdfConverter::$lastWord = null;
    $this->converter = new FakePdfConverter;
    app()->instance(PdfConverter::class, $this->converter);

    foreach (['documents.register', 'documents.view', 'documents.forward', 'documents.acknowledge', 'document_types.manage'] as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $this->author = User::factory()->create(['man_no' => 'SIG-0001', 'name' => 'Racheal Inonge Zekko', 'password' => Hash::make('login-pass')]);
    $this->author->givePermissionTo(['documents.register', 'documents.view', 'documents.forward']);
    $this->actingAs($this->author);

    $this->type = PdtsDocumentType::create(['name' => 'Memorandum', 'is_active' => true]);
    $this->directorate = PdtsDirectorate::create(['name' => 'Investments & Finance', 'is_active' => true]);
    $this->department = PdtsDepartment::create(['name' => 'Finance', 'directorate_id' => $this->directorate->id, 'is_active' => true]);
    $this->office = PdtsOffice::create(['name' => 'Finance Head Office', 'department_id' => $this->department->id, 'is_active' => true]);
});

/** One of the real ZESCO templates, installed as the seeder does. */
function zescoTemplate(string $file, string $name): PdtsDocumentTemplate
{
    $setup = app(TemplateSetup::class);
    // The real ZESCO memo is in the seeder; sample forms are test fixtures.
    $path = is_file(database_path('seeders/templates/'.$file)) ? database_path('seeders/templates/'.$file) : base_path('tests/Fixtures/templates/'.$file);
    $stored = $setup->storeOriginal($path, pathinfo($file, PATHINFO_EXTENSION));
    $template = PdtsDocumentTemplate::create([
        'name' => $name, 'kind' => $stored['kind'], 'document_type_id' => test()->type->id,
        'original_path' => $stored['path'], 'original_filename' => $file, 'is_active' => false,
    ]);

    return $setup->save($template, ['page' => 'first', 'x' => 10, 'y' => 10, 'size' => 18])->fresh();
}

/** A PNG of the given size with a dark stroke, to stand in for a scanned signature. */
function signaturePng(int $width = 360, int $height = 140, int $shade = 30): string
{
    $raw = '';
    for ($y = 0; $y < $height; $y++) {
        $raw .= "\0";
        for ($x = 0; $x < $width; $x++) {
            $ink = abs($y - (int) ($height / 2 + sin($x / 20) * $height / 4)) < 3;
            $raw .= $ink ? chr($shade).chr($shade).chr(100).chr(255) : "\0\0\0\0";
        }
    }
    $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

    return "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0)).$chunk('IDAT', gzcompress($raw)).$chunk('IEND', '');
}

function signatureReady(User $user, string $secret = '2468', string $type = 'pin'): void
{
    Livewire::actingAs($user)->test(MySignature::class)
        ->set('image', UploadedFile::fake()->createWithContent('signature.png', signaturePng()))
        ->set('imageConfirmation', 'login-pass') // first time: the login password confirms it
        ->call('saveImage')->assertHasNoErrors()
        ->set('secretType', $type)->set('password', 'login-pass')->set('secret', $secret)->set('secret_confirmation', $secret)
        ->call('saveSecret')->assertHasNoErrors();

    ictApprove($user);
}

function memoDraft(PdtsDocumentTemplate $template, string $body = "First paragraph.\nSecond paragraph & more."): PdtsDocument
{
    Livewire::test(InternalDocument::class)
        ->set('template_id', (string) $template->id)
        ->set('to', 'Director - Corporate Affairs')
        ->set('from', 'Director - Investments & Finance')
        ->set('cc', "Managing Director\nAll Directors")
        ->set('subject', 'Revision of petty cash limit')
        ->set('custom.text_body', $body)
        ->set('directorate_id', (string) test()->directorate->id)
        ->set('department_id', (string) test()->department->id)
        ->set('office_id', (string) test()->office->id)
        ->call('save')
        ->assertHasNoErrors();

    return PdtsDocument::query()->latest('id')->first();
}

test('the ZESCO memorandum gets a body to write in and a signature spot above the name', function () {
    $template = zescoTemplate('ZESCO_Memorandum_Template.docx', 'Internal Memorandum');

    expect($template->placeholderNames())->toContain('subject', 'text_body', 'author_upper', 'signature')
        ->and($template->supportsElectronicSignature())->toBeTrue();

    Livewire::test(InternalDocument::class)
        ->set('template_id', (string) $template->id)
        ->assertSeeHtml('<textarea id="custom-text_body"')
        ->assertSee('Body');

    $document = memoDraft($template);
    $xml = pdtsZipPart(app(InternalDocuments::class)->wordFile($document)['contents'], 'word/document.xml');

    expect($xml)->toContain('First paragraph.')
        ->toContain('Second paragraph &amp; more.')
        ->toContain('RACHEAL INONGE ZEKKO')
        ->toContain('Revision of petty cash limit')
        ->not->toContain('${')
        ->not->toContain('[Title of person copied]') // the extra CC lines go once CC is filled
        ->not->toContain('name="Signature"'); // not signed: the spot is left to sign by hand

    // Each line of the body is its own paragraph.
    expect(substr_count($xml, 'First paragraph.') + substr_count($xml, 'Second paragraph'))->toBe(2)
        ->and(preg_match('#First paragraph\.</w:t></w:r></w:p><w:p#', $xml))->toBe(1);
});

test('the monthly report gets a space for each section and signs under "Prepared by"', function () {
    $template = zescoTemplate('Monthly_Report_Template.docx', 'Monthly Report');

    expect($template->placeholderNames())->toContain('text_executive_summary', 'text_conclusion', 'signature')
        ->and($template->customFields())->toMatchArray(['text_executive_summary' => 'Executive summary', 'text_planned_activities_for_next_month' => 'Planned activities for next month']);

    expect(substr_count(pdtsZipPart(Storage::disk('private')->get($template->storage_path), 'word/document.xml'), '${signature}'))->toBe(1);
});

test('the petty cash voucher is signed in the Signature column of the claimant row', function () {
    $template = zescoTemplate('Petty_Cash_Voucher.docx', 'Petty Cash Voucher');

    expect($template->supportsElectronicSignature())->toBeTrue()
        ->and(pdtsZipPart(Storage::disk('private')->get($template->storage_path), 'word/document.xml'))
        ->toMatch('#Claimant.*?</w:tc>.*?</w:tc><w:tc>.*?\$\{signature\}#s');
});

test('a PDF template is not signed in PDTS', function () {
    $setup = app(TemplateSetup::class);
    $stored = $setup->storeOriginal(base_path('tests/Fixtures/templates/Petty_Cash_VoucherP.pdf'), 'pdf');
    $template = PdtsDocumentTemplate::create(['name' => 'Petty PDF', 'kind' => 'pdf', 'original_path' => $stored['path'], 'original_filename' => 'p.pdf']);

    expect($setup->save($template, ['page' => 'first', 'x' => 10, 'y' => 10, 'size' => 18])->supportsElectronicSignature())->toBeFalse();
});

test('my signature: the picture is kept privately and the PIN needs the login password and is stored hashed', function () {
    Livewire::test(MySignature::class)
        ->set('image', UploadedFile::fake()->create('signature.gif', 10, 'image/gif'))
        ->set('imageConfirmation', 'login-pass')
        ->call('saveImage')->assertHasErrors('image');

    Livewire::test(MySignature::class)
        ->set('password', 'wrong')->set('secret', '1234')->set('secret_confirmation', '1234')
        ->call('saveSecret')->assertHasErrors('password');
    Livewire::test(MySignature::class)
        ->set('password', 'login-pass')->set('secret', '12')->set('secret_confirmation', '12')
        ->call('saveSecret')->assertHasErrors('secret');
    expect($this->author->fresh()->signature_pin)->toBeNull();

    signatureReady($this->author, '2468');
    $user = $this->author->fresh();

    expect($user->canSignElectronically())->toBeTrue()
        ->and($user->signature_pin)->not->toBe('2468')
        ->and(Hash::check('2468', $user->signature_pin))->toBeTrue()
        ->and(Storage::disk('private')->exists($user->signature_path))->toBeTrue()
        ->and($user->toArray())->not->toHaveKey('signature_pin');

    $this->get(route('settings.signature'))->assertOk()->assertSee('You are ready to sign');
    $this->get(route('settings.signature.image'))->assertOk()->assertHeader('Content-Type', 'image/png');

    // Nobody else can see it.
    $other = User::factory()->create(['man_no' => 'SIG-0002']);
    $this->actingAs($other)->get(route('settings.signature.image'))->assertNotFound();
});

test('the author signs a draft with their PIN: a signed PDF is kept, fingerprinted and the document registered', function () {
    $template = zescoTemplate('ZESCO_Memorandum_Template.docx', 'Internal Memorandum');
    signatureReady($this->author);
    $document = memoDraft($template);

    Livewire::test(Show::class, ['document' => $document])
        ->assertSee('Sign electronically')
        ->set('reviewed', true)->set('signaturePin', '2468')
        ->call('signElectronically')
        ->assertHasNoErrors();

    $document->refresh();
    $signature = $document->electronicSignature;
    $pdf = Storage::disk('private')->get($signature->attachment->storage_path);

    expect($document->isDraft())->toBeFalse()
        ->and($signature->user_id)->toBe($this->author->id)
        ->and($signature->file_sha256)->toBe(hash('sha256', $pdf))
        ->and($signature->fileIsUnchanged())->toBeTrue()
        ->and($signature->attachment->original_filename)->toEndWith('(signed).pdf')
        ->and($document->auditLogs->pluck('action')->all())->toContain('signed_electronically', 'registered');

    // The Word file that became the PDF carried the signature picture on the signature spot, and the QR code.
    $word = FakePdfConverter::$lastWord;
    expect(pdtsZipPart($word, 'word/document.xml'))->toContain('name="Signature"')->toContain('First paragraph.')->not->toContain('${signature}')
        ->and(pdtsZipPart($word, 'word/media/pdts-signature.png'))->toBe(Storage::disk('private')->get($this->author->fresh()->signature_path));

    // Signed: no unsigned copies, and the page shows who signed.
    $this->get(route('pdts.documents.word', $document))->assertForbidden();
    Livewire::test(Show::class, ['document' => $document])
        ->assertSee('Signed electronically by Racheal Inonge Zekko')
        ->assertSee('Unchanged since signing')->assertSee('Signed PDF')->assertDontSee('Man No.')->assertDontSee('Fingerprint')
        ->assertDontSee('Download Word file');

    // A changed file shows.
    Storage::disk('private')->put($signature->attachment->storage_path, $pdf.'tampered');
    expect($signature->fresh()->fileIsUnchanged())->toBeFalse();
});

test('a wrong PIN is refused, and after too many tries signing pauses even with the right one', function () {
    $template = zescoTemplate('ZESCO_Memorandum_Template.docx', 'Internal Memorandum');
    signatureReady($this->author);
    $document = memoDraft($template);
    RateLimiter::clear('pdts-signature-pin:'.$this->author->id);

    $page = Livewire::test(Show::class, ['document' => $document]);
    $page->set('reviewed', true)->set('signaturePin', '0000')->call('signElectronically')->assertHasErrors('signaturePin')->assertSee('4 attempts left');

    foreach (range(1, 4) as $try) {
        $page->set('reviewed', true)->set('signaturePin', '0000')->call('signElectronically');
    }
    $page->set('reviewed', true)->set('signaturePin', '2468')->call('signElectronically')->assertSee('Signing is paused');

    expect($document->fresh()->isDraft())->toBeTrue()
        ->and($document->fresh()->electronicSignature)->toBeNull();
});

test('only the author can sign, only once set up, and only where PDF signing is available', function () {
    $template = zescoTemplate('ZESCO_Memorandum_Template.docx', 'Internal Memorandum');
    $document = memoDraft($template);

    // Not set up yet.
    Livewire::test(Show::class, ['document' => $document])->assertSee('Set up your signature first; ICT then verifies it.');

    // Someone else's draft.
    $other = User::factory()->create(['man_no' => 'SIG-0003', 'password' => Hash::make('login-pass')]);
    $other->givePermissionTo(['documents.view']);
    signatureReady($other);
    expect(fn () => app(ElectronicSigning::class)->sign($document, $other->fresh(), '2468'))
        ->toThrow(RuntimeException::class, 'Only the author can sign this document.');

    // No PDF converter on this server.
    signatureReady($this->author);
    $this->converter->on = false;
    Livewire::actingAs($this->author->fresh())->test(Show::class, ['document' => $document])
        ->assertSee('LibreOffice is not installed')
        ->assertDontSeeHtml('wire:submit.prevent="signElectronically"');

    expect($document->fresh()->isDraft())->toBeTrue();
});

/** A PNG with ink wherever $ink(x, y) is true, on white paper. */
function inkPng(int $width, int $height, callable $ink): string
{
    $raw = '';
    for ($y = 0; $y < $height; $y++) {
        $raw .= "\0";
        for ($x = 0; $x < $width; $x++) {
            $raw .= $ink($x, $y) ? "\x10\x20\x60\xff" : "\xff\xff\xff\xff";
        }
    }
    $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

    return "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0)).$chunk('IDAT', gzcompress($raw)).$chunk('IEND', '');
}

test('only signature-shaped pictures are accepted: the signature wider than high, and not tiny', function () {
    $gd = app(SignatureImageNormaliser::class)->available();
    $wave = fn (int $w, int $h) => fn ($x, $y) => abs($y - (int) ($h / 2 + sin($x / 20) * min(40, $h / 4))) < 3 && $x > $w * 0.15 && $x < $w * 0.85;

    $refused = [[200, 30, fn () => true, 'too small']];
    if ($gd) {
        // The shape of the signature inside the picture is what counts.
        $refused[] = [400, 600, fn ($x, $y) => abs($x - 200) < 4 && $y > 100 && $y < 500, 'taller than it is wide'];
        $refused[] = [400, 300, fn () => false, 'No signature could be found'];
    } else {
        $refused[] = [300, 300, $wave(300, 300), 'too tall'];
    }

    foreach ($refused as [$w, $h, $ink, $problem]) {
        Livewire::test(MySignature::class)
            ->set('image', UploadedFile::fake()->createWithContent('signature.png', inkPng($w, $h, $ink)))
            ->assertHasErrors('image')
            ->assertSee($problem);
    }

    Livewire::test(MySignature::class)
        ->set('image', UploadedFile::fake()->createWithContent('signature.png', inkPng(360, 140, $wave(360, 140))))
        ->assertHasNoErrors()
        ->assertSee('Preview, not saved yet');

    if ($gd) {
        // A whole phone photo, taller than wide, with the signature across the middle: accepted.
        Livewire::test(MySignature::class)
            ->set('image', UploadedFile::fake()->createWithContent('photo.png', inkPng(608, 1080, fn ($x, $y) => abs($y - (int) (540 + sin($x / 24) * 40)) < 3 && $x > 120 && $x < 480)))
            ->assertHasNoErrors()
            ->assertSee('Preview, not saved yet');
    }
});

test('replacing the signature needs the current PIN; the old picture is kept and the change is recorded and emailed', function () {
    Notification::fake();
    signatureReady($this->author, '2468');
    $first = $this->author->fresh()->signatureImages()->sole();

    // Without the PIN (the login password is not enough once a PIN exists), nothing changes.
    Livewire::test(MySignature::class)
        ->set('image', UploadedFile::fake()->createWithContent('new.png', signaturePng(400, 150, 90)))
        ->set('imageConfirmation', 'login-pass')
        ->call('saveImage')->assertHasErrors('imageConfirmation');
    expect($this->author->fresh()->signature_path)->toBe($first->storage_path);

    Livewire::test(MySignature::class)
        ->set('image', UploadedFile::fake()->createWithContent('new.png', signaturePng(400, 150, 90)))
        ->set('imageConfirmation', '2468')
        ->call('saveImage')->assertHasNoErrors();

    $user = $this->author->fresh();
    $images = $user->signatureImages()->orderBy('id')->get();

    // The new one waits for ICT; the verified one stays in use until then.
    expect($images)->toHaveCount(2)
        ->and($images[0]->status)->toBe('active')
        ->and($images[1]->status)->toBe('pending')
        ->and($user->signature_path)->toBe($images[0]->storage_path)
        ->and($user->canSignElectronically())->toBeTrue()
        ->and($user->signatureEvents()->first()->action)->toBe('image_replaced')
        ->and($user->signatureEvents()->first()->details)->toMatchArray(['image_sha256' => $images[1]->sha256, 'previous_image_sha256' => $images[0]->sha256]);
    Notification::assertSentTo($user, SignatureChangedNotification::class, fn ($n) => $n->action === 'image_replaced');
    $this->get(route('settings.signature'))->assertSee('New signature uploaded, waiting for ICT to verify it')->assertSee('The one above stays in use until then.');

    // ICT approves it: the old one is retired and kept, never deleted.
    ictApprove($this->author);
    $images = $user->signatureImages()->orderBy('id')->get();
    expect($images[0]->status)->toBe('retired')
        ->and(Storage::disk('private')->exists($images[0]->storage_path))->toBeTrue()
        ->and($images[1]->status)->toBe('active')
        ->and($user->fresh()->signature_path)->toBe($images[1]->storage_path);
    Notification::assertSentTo($user, SignatureChangedNotification::class, fn ($n) => $n->action === 'image_approved');
});

test('a document signed before the picture is replaced keeps the fingerprint of the picture it was signed with', function () {
    $template = zescoTemplate('ZESCO_Memorandum_Template.docx', 'Internal Memorandum');
    signatureReady($this->author);
    $document = memoDraft($template);
    $original = $this->author->fresh()->signatureImages()->sole();

    Livewire::test(Show::class, ['document' => $document])->set('reviewed', true)->set('signaturePin', '2468')->call('signElectronically')->assertHasNoErrors();

    Livewire::test(MySignature::class)
        ->set('image', UploadedFile::fake()->createWithContent('new.png', signaturePng(420, 160, 90)))
        ->set('imageConfirmation', '2468')
        ->call('saveImage')->assertHasNoErrors();

    $signature = $document->fresh()->electronicSignature;
    expect($signature->signature_image_sha256)->toBe($original->sha256)
        ->and(Storage::disk('private')->exists($original->storage_path))->toBeTrue()
        ->and($signature->fileIsUnchanged())->toBeTrue();
});

test('a signature password can be used instead of a PIN, and must be strong', function () {
    $template = zescoTemplate('ZESCO_Memorandum_Template.docx', 'Internal Memorandum');

    Livewire::test(MySignature::class)
        ->set('secretType', 'password')->set('password', 'login-pass')->set('secret', 'short1')->set('secret_confirmation', 'short1')
        ->call('saveSecret')->assertHasErrors('secret');
    Livewire::test(MySignature::class)
        ->set('secretType', 'password')->set('password', 'login-pass')->set('secret', 'onlyletters')->set('secret_confirmation', 'onlyletters')
        ->call('saveSecret')->assertHasErrors('secret');
    Livewire::test(MySignature::class)
        ->set('secretType', 'password')->set('password', 'login-pass')->set('secret', 'login-pass1')->set('secret_confirmation', 'login-pass1')
        ->call('saveSecret')->assertHasNoErrors(); // differs from the login password

    // With a signature password set, it (not the login password) confirms the picture.
    Livewire::test(MySignature::class)
        ->set('image', UploadedFile::fake()->createWithContent('signature.png', signaturePng()))
        ->set('imageConfirmation', 'login-pass')
        ->call('saveImage')->assertHasErrors('imageConfirmation');
    Livewire::test(MySignature::class)
        ->set('image', UploadedFile::fake()->createWithContent('signature.png', signaturePng()))
        ->set('imageConfirmation', 'login-pass1')
        ->call('saveImage')->assertHasNoErrors();
    ictApprove($this->author);
    Livewire::test(MySignature::class)
        ->set('secretType', 'password')->set('currentSecret', 'login-pass1')->set('password', 'login-pass')
        ->set('secret', 'Zesco2026Sign')->set('secret_confirmation', 'Zesco2026Sign')
        ->call('saveSecret')->assertHasNoErrors();
    expect($this->author->fresh()->signature_secret_type)->toBe('password');

    $document = memoDraft($template);
    Livewire::test(Show::class, ['document' => $document])
        ->assertSeeHtml('placeholder="Signature password"')
        ->set('reviewed', true)->set('signaturePin', 'Zesco2026Sign')->call('signElectronically')->assertHasNoErrors();

    expect($document->fresh()->electronicSignature)->not->toBeNull();
});

test('changing the PIN needs the current one; a forgotten one is reset with the login password', function () {
    Notification::fake();
    signatureReady($this->author, '2468');

    Livewire::test(MySignature::class)
        ->set('password', 'login-pass')->set('secret', '1357')->set('secret_confirmation', '1357')
        ->call('saveSecret')->assertHasErrors('currentSecret');
    Livewire::test(MySignature::class)
        ->set('currentSecret', '9999')->set('password', 'login-pass')->set('secret', '1357')->set('secret_confirmation', '1357')
        ->call('saveSecret')->assertHasErrors('currentSecret');
    expect(Hash::check('2468', $this->author->fresh()->signature_pin))->toBeTrue();

    Livewire::test(MySignature::class)
        ->set('currentSecret', '2468')->set('password', 'login-pass')->set('secret', '1357')->set('secret_confirmation', '1357')
        ->call('saveSecret')->assertHasNoErrors();
    expect(Hash::check('1357', $this->author->fresh()->signature_pin))->toBeTrue();

    Livewire::test(MySignature::class)
        ->set('forgotten', true)->set('password', 'login-pass')->set('secret', '8642')->set('secret_confirmation', '8642')
        ->call('saveSecret')->assertHasNoErrors();

    $user = $this->author->fresh();
    expect(Hash::check('8642', $user->signature_pin))->toBeTrue()
        ->and($user->signatureEvents()->pluck('action')->take(2)->all())->toBe(['secret_reset', 'secret_changed']);
    Notification::assertSentTo($user, SignatureChangedNotification::class, fn ($n) => $n->action === 'secret_reset');
});

test('an administrator can reset a forgotten PIN; it is recorded with their name and the person is told', function () {
    Notification::fake();
    Permission::firstOrCreate(['name' => 'users.manage', 'guard_name' => 'web']);
    signatureReady($this->author, '2468');

    $admin = User::factory()->create(['man_no' => 'ADM-0001', 'name' => 'Benson Ngulube']);
    $admin->givePermissionTo('users.manage');

    Livewire::actingAs($admin)->test(UserEdit::class, ['user' => $this->author])
        ->assertSee('Electronic signature')
        ->assertSee('Reset signature PIN/password')
        ->call('resetSignatureSecret');

    $user = $this->author->fresh();
    $event = $user->signatureEvents()->first();

    expect($user->signature_pin)->toBeNull()
        ->and($user->signature_path)->not->toBeNull() // the picture stays
        ->and($user->canSignElectronically())->toBeFalse()
        ->and($event->action)->toBe('secret_reset_by_admin')
        ->and($event->actor_id)->toBe($admin->id);
    Notification::assertSentTo($user, SignatureChangedNotification::class, fn ($n) => $n->action === 'secret_reset_by_admin');

    // Someone without the permission cannot.
    $other = User::factory()->create(['man_no' => 'SIG-0009']);
    Livewire::actingAs($other)->test(UserEdit::class, ['user' => $admin])->call('resetSignatureSecret')->assertForbidden();
});

test('the on-page preview gets a copy the browser can draw; the real file keeps its QR box', function () {
    $template = zescoTemplate('ZESCO_Memorandum_Template.docx', 'Internal Memorandum');
    $document = memoDraft($template);

    $real = $this->get(route('pdts.documents.word', $document))->assertOk()->getContent();
    $preview = $this->get(route('pdts.documents.word', [$document, 'preview' => 1]))->assertOk()->getContent();

    $header = fn (string $docx) => collect(['word/header1.xml', 'word/header2.xml', 'word/header3.xml'])->map(fn ($p) => pdtsZipPart($docx, $p) ?? '')->implode('');
    expect($header($real))->toContain('<mc:AlternateContent>')->toContain('<mc:Fallback/>')
        ->and($header($preview))->not->toContain('AlternateContent')->toContain('<wp:anchor');
});

test('the memo keeps its TO/FROM columns, leaves out an empty CC line and prints no stray line for empty initials', function () {
    $template = zescoTemplate('ZESCO_Memorandum_Template.docx', 'Internal Memorandum');

    Livewire::test(InternalDocument::class)
        ->set('template_id', (string) $template->id)
        ->set('to', 'Director ICT')->set('from', 'Engineer - Software Developer')->set('cc', '')
        ->set('subject', 'Request to Purchase ICT Equipment')
        ->set('directorate_id', (string) $this->directorate->id)->set('department_id', (string) $this->department->id)->set('office_id', (string) $this->office->id)
        ->call('save')->assertHasNoErrors();

    $xml = pdtsZipPart(app(InternalDocuments::class)->wordFile(PdtsDocument::query()->latest('id')->first())['contents'], 'word/document.xml');
    $text = html_entity_decode(strip_tags(str_replace('</w:p>', "\n", $xml)));

    expect($xml)->toMatch('#<w:tc>(?:(?!</w:tc>).)*TO:(?:(?!</w:tc>).)*Director ICT(?:(?!</w:tc>).)*</w:tc><w:tc>(?:(?!</w:tc>).)*FROM:#s') // side by side, each in its own column
        ->and($text)->toMatch('/TO:\s*Director ICT\s*\nFROM:/')
        ->and($text)->not->toMatch('/^\s*CC:/m')                 // no empty CC line
        ->and($text)->not->toContain('____________________');   // empty initials print nothing
});

test('the signatory reads the draft as the exact PDF it will become, made once and reused', function () {
    $template = zescoTemplate('ZESCO_Memorandum_Template.docx', 'Internal Memorandum');
    signatureReady($this->author);
    $document = memoDraft($template);

    Livewire::test(Show::class, ['document' => $document])->assertSee('Preparing the document exactly as it will be signed');

    $first = $this->get(route('pdts.documents.word', [$document, 'pdf' => 1]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($first->getContent())->toStartWith('%PDF')
        ->and(pdtsZipPart(FakePdfConverter::$lastWord, 'word/document.xml'))->not->toContain('name="Signature"'); // not signed yet

    FakePdfConverter::$lastWord = null;
    $this->get(route('pdts.documents.word', [$document, 'pdf' => 1]))->assertOk();
    expect(FakePdfConverter::$lastWord)->toBeNull(); // reused, not made again

    // Once signed, there is no preview: the signed PDF is the document.
    Livewire::test(Show::class, ['document' => $document])->set('reviewed', true)->set('signaturePin', '2468')->call('signElectronically')->assertHasNoErrors();
    $this->get(route('pdts.documents.word', [$document->fresh(), 'pdf' => 1]))->assertForbidden();
});

test('a draft that can be signed in PDTS says it is waiting for signature and keeps signing on paper folded away', function () {
    $template = zescoTemplate('ZESCO_Memorandum_Template.docx', 'Internal Memorandum');
    signatureReady($this->author);
    $document = memoDraft($template);

    $page = Livewire::test(Show::class, ['document' => $document])
        ->assertSee('Draft: waiting for signature')->assertSee('Sign electronically')
        ->assertSee('Sign on paper instead')->assertDontSee('Movement history');
    expect($page->html())->toContain('<details data-test="sign-on-paper"')
        ->not->toMatch('#<details data-test="sign-on-paper"\s+open#');
});

/** A photo-like picture: grey paper, with marks at the brightness $level returns (null for paper). */
function greyPng(int $width, int $height, callable $level, int $paper = 205): string
{
    $image = imagecreatetruecolor($width, $height);
    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            $v = $level($x, $y) ?? $paper;
            imagesetpixel($image, $x, $y, imagecolorallocate($image, $v, $v, $v));
        }
    }
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

describe('cleaning up the signature picture', function () {
    beforeEach(function () {
        if (! app(SignatureImageNormaliser::class)->available()) {
            $this->markTestSkipped('Needs GD.');
        }
        $this->normaliser = app(SignatureImageNormaliser::class);
    });

    test('initials and a surname, or two names, are kept together', function () {
        // "E. LYAMBO": an initial, a dot, then the surname, with ordinary gaps between them.
        $initial = fn ($x, $y) => $x >= 40 && $x < 44 && $y >= 60 && $y < 140;
        $dot = fn ($x, $y) => $x >= 80 && $x < 88 && $y >= 130 && $y < 138;
        $surname = fn ($x, $y) => $x >= 150 && $x < 560 && abs($y - (int) (100 + sin($x / 15) * 35)) < 3;
        $png = inkPng(600, 200, fn ($x, $y) => $initial($x, $y) || $dot($x, $y) || $surname($x, $y));

        expect($this->normaliser->inkSize($png)[0])->toBeGreaterThan(500);
    });

    test('a long stroke that runs to the edge of the picture is part of the signature', function () {
        // A loop, a separate small piece, and a long line out to the left edge.
        $line = fn ($x, $y) => $x < 480 && abs($y - 150) < 2;
        $loop = fn ($x, $y) => abs(sqrt(($x - 200) ** 2 + (($y - 160) * 1.6) ** 2) - 110) < 2;
        $tail = fn ($x, $y) => $x > 500 && $x < 680 && abs($y - (int) (140 + sin($x / 12) * 8)) < 2;
        $png = inkPng(712, 338, fn ($x, $y) => $line($x, $y) || $loop($x, $y) || $tail($x, $y));

        expect($this->normaliser->inkSize($png)[0])->toBeGreaterThan(600);
    });

    test('the edge of the page along one side of a photo is still left out', function () {
        $pageEdge = fn ($x, $y) => $x < 4;
        $signature = fn ($x, $y) => $x > 200 && $x < 500 && abs($y - (int) (200 + sin($x / 20) * 30)) < 3;
        $png = inkPng(700, 400, fn ($x, $y) => $pageEdge($x, $y) || $signature($x, $y));

        expect($this->normaliser->inkSize($png)[0])->toBeLessThan(400);
    });

    test('a faint pencil signature comes out solid and dark, not faded', function () {
        // Pencil only a little darker than the grey paper, with fainter stretches.
        $png = greyPng(600, 220, fn ($x, $y) => $x > 60 && $x < 540 && abs($y - (int) (110 + sin($x / 25) * 40)) < 2
            ? ($x % 120 < 60 ? 132 : 172) : null);

        $out = imagecreatefromstring($this->normaliser->normalise($png));
        $solid = 0;
        $darkest = 255;
        for ($y = 0; $y < 140; $y++) {
            for ($x = 0; $x < 360; $x++) {
                $c = imagecolorat($out, $x, $y);
                if ((($c >> 24) & 0x7F) < 20) {
                    $solid++;
                    $darkest = min($darkest, ($c >> 16) & 0xFF);
                }
            }
        }

        expect($solid)->toBeGreaterThan(300)->and($darkest)->toBeLessThan(110);
    });

    test('the preview before saving is the cleaned signature exactly as it will be saved', function () {
        $png = inkPng(600, 200, fn ($x, $y) => $x > 100 && $x < 500 && abs($y - (int) (100 + sin($x / 20) * 40)) < 3);

        Livewire::test(MySignature::class)
            ->set('image', UploadedFile::fake()->createWithContent('signature.png', $png))
            ->assertHasNoErrors()
            ->assertSee('exactly as it will be saved')
            ->assertSeeHtml('src="data:image/png;base64,'.base64_encode($this->normaliser->normalise($png)).'"');
    });
});
