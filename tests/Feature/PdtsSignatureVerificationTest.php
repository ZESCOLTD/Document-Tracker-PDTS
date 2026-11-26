<?php

use App\Livewire\Pdts\Signatures\VerificationQueue;
use App\Livewire\Settings\MySignature;
use App\Models\User;
use App\Notifications\SignatureChangedNotification;
use App\Notifications\SignaturePendingNotification;
use App\Services\Pdts\Signatures\SignatureImageNormaliser;
use App\Services\Pdts\Signatures\SignatureVerification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../Support/signatures.php';

uses(RefreshDatabase::class);

function verificationPng(int $width = 360, int $height = 140): string
{
    $raw = '';
    for ($y = 0; $y < $height; $y++) {
        $raw .= "\0";
        for ($x = 0; $x < $width; $x++) {
            // A wavy stroke, like a handwritten signature.
            $raw .= abs($y - (int) ($height / 2 + sin($x / 20) * $height / 4)) < 3 ? "\x10\x20\x80\xff" : "\0\0\0\0";
        }
    }
    $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

    return "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0)).$chunk('IDAT', gzcompress($raw)).$chunk('IEND', '');
}

beforeEach(function () {
    Storage::fake('private');
    Notification::fake();

    $this->employee = User::factory()->create(['man_no' => 'EMP-01', 'name' => 'Chanda Mulenga', 'password' => Hash::make('login-pass')]);
    $this->ict = ictVerifier();
    $this->actingAs($this->employee);
});

function uploadSignature(User $user, string $confirm = 'login-pass'): void
{
    Livewire::actingAs($user)->test(MySignature::class)
        ->set('image', UploadedFile::fake()->createWithContent('signature.png', verificationPng()))
        ->set('imageConfirmation', $confirm)
        ->call('saveImage')->assertHasNoErrors();
    Livewire::actingAs($user)->test(MySignature::class)
        ->set('password', 'login-pass')->set('secret', '2468')->set('secret_confirmation', '2468')
        ->call('saveSecret');
}

test('an uploaded signature waits for ICT and cannot sign until approved', function () {
    uploadSignature($this->employee);
    $user = $this->employee->fresh();

    expect($user->pendingSignatureImage())->not->toBeNull()
        ->and($user->signature_path)->toBeNull()
        ->and($user->canSignElectronically())->toBeFalse();
    $this->get(route('settings.signature'))->assertSee('waiting for ICT to verify it')->assertDontSee('You are ready to sign');
    Notification::assertSentTo($this->ict, SignaturePendingNotification::class);

    // ICT sees it, with the picture, and approves it.
    Livewire::actingAs($this->ict)->test(VerificationQueue::class)
        ->assertSee('Chanda Mulenga')->assertSee('EMP-01')->assertSee('First signature.')
        ->call('approve', $user->pendingSignatureImage()->id)
        ->assertHasNoErrors();
    $this->actingAs($this->ict)->get(route('pdts.signatures.verification.image', $user->signatureImages()->sole()))->assertOk();

    $user->refresh();
    expect($user->canSignElectronically())->toBeTrue()
        ->and($user->currentSignatureImage()->reviewed_by)->toBe($this->ict->id)
        ->and($user->signatureEvents()->pluck('action')->all())->toContain('image_approved');
    Notification::assertSentTo($user, SignatureChangedNotification::class, fn ($n) => $n->action === 'image_approved');

    $this->actingAs($user)->get(route('settings.signature'))->assertSee('You are ready to sign')->assertSee('Verified by Ivy Tembo');
});

test('only ICT with the permission can verify, and nobody can verify their own signature', function () {
    uploadSignature($this->employee);

    $this->actingAs($this->employee)->get(route('pdts.signatures.verification'))->assertForbidden();
    $this->actingAs($this->employee)->get(route('pdts.signatures.verification.image', $this->employee->pendingSignatureImage()))->assertForbidden();
    expect(fn () => app(SignatureVerification::class)->approve($this->employee->pendingSignatureImage(), $this->employee))
        ->toThrow(RuntimeException::class, 'Verify signatures');

    // The ICT verifier's own signature needs another member of ICT.
    $this->ict->forceFill(['password' => Hash::make('login-pass')])->save();
    uploadSignature($this->ict);
    expect(fn () => app(SignatureVerification::class)->approve($this->ict->pendingSignatureImage(), $this->ict))
        ->toThrow(RuntimeException::class, 'Nobody can verify their own signature');
    Livewire::actingAs($this->ict)->test(VerificationQueue::class)->assertSee('This is your own signature');
});

test('a rejected signature tells the employee why, and they can upload again', function () {
    uploadSignature($this->employee);
    $image = $this->employee->pendingSignatureImage();

    Livewire::actingAs($this->ict)->test(VerificationQueue::class)
        ->call('reject', $image->id)->assertHasErrors('image-'.$image->id) // a reason is required
        ->set('reasons.image-'.$image->id, 'Does not match the specimen on your file.')
        ->call('reject', $image->id)->assertHasNoErrors();

    expect($image->fresh()->status)->toBe('rejected')
        ->and($this->employee->fresh()->canSignElectronically())->toBeFalse();
    Notification::assertSentTo($this->employee, SignatureChangedNotification::class, fn ($n) => $n->action === 'image_rejected');
    $this->actingAs($this->employee)->get(route('settings.signature'))
        ->assertSee('ICT rejected your last upload')->assertSee('Does not match the specimen on your file.');

    // A new upload goes back to ICT (confirmed with the PIN, which is set by now).
    Livewire::actingAs($this->employee->fresh())->test(MySignature::class)
        ->set('image', UploadedFile::fake()->createWithContent('again.png', verificationPng(380, 140)))
        ->set('imageConfirmation', '2468')->call('saveImage')->assertHasNoErrors();
    expect($this->employee->fresh()->pendingSignatureImage())->not->toBeNull();
    $this->actingAs($this->employee)->get(route('settings.signature'))->assertDontSee('ICT rejected your last upload');
});

test('ICT can suspend a signature at once and reinstate it', function () {
    uploadSignature($this->employee);
    ictApprove($this->employee);
    expect($this->employee->fresh()->canSignElectronically())->toBeTrue();

    $page = Livewire::actingAs($this->ict)->test(VerificationQueue::class)->set('tab', 'in_use')->assertSee('Chanda Mulenga');
    $page->call('suspend', $this->employee->id)->assertHasErrors('user-'.$this->employee->id); // reason required
    $page->set('reasons.user-'.$this->employee->id, 'Reported misuse, under investigation.')->call('suspend', $this->employee->id)->assertHasNoErrors();

    $user = $this->employee->fresh();
    expect($user->canSignElectronically())->toBeFalse()
        ->and($user->currentSignatureImage()->status)->toBe('suspended');
    $this->actingAs($user)->get(route('settings.signature'))->assertSee('suspended by ICT')->assertSee('Reported misuse');
    Notification::assertSentTo($user, SignatureChangedNotification::class, fn ($n) => $n->action === 'signature_suspended');

    Livewire::actingAs($this->ict)->test(VerificationQueue::class)->call('reinstate', $this->employee->id)->assertHasNoErrors();
    expect($this->employee->fresh()->canSignElectronically())->toBeTrue();
});

test('without GD the picture is kept as uploaded; with GD it is standardised to 360x140', function () {
    $normaliser = app(SignatureImageNormaliser::class);

    if (! $normaliser->available()) {
        expect($normaliser->normalise(verificationPng(500, 200)))->toBeNull();
        uploadSignature($this->employee);
        expect($this->employee->fresh()->pendingSignatureImage()->only(['width', 'height']))->toBe(['width' => 360, 'height' => 140]);

        return;
    }

    $png = $normaliser->normalise(verificationPng(900, 300));
    expect(getimagesizefromstring($png)[0])->toBe(360)
        ->and(getimagesizefromstring($png)[1])->toBe(140);
    // A picture with no ink in it is refused.
    $blank = str_replace("\x10\x20\x80\xff", "\0\0\0\0", '');
    $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    $empty = "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', 400, 150, 8, 6, 0, 0, 0)).$chunk('IDAT', gzcompress(str_repeat("\0".str_repeat("\0\0\0\0", 400), 150))).$chunk('IEND', $blank);
    expect(fn () => $normaliser->normalise($empty))->toThrow(RuntimeException::class, 'No signature could be found');
});
