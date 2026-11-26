<?php

namespace App\Services\Pdts\Signatures;

use App\Models\User;
use App\Models\UserSignatureImage;
use App\Notifications\SignatureChangedNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Each employee's own signature: the image of their handwritten signature
 * and a signing credential, either a PIN (4-8 digits) or a signature
 * password (8+ characters, letters and numbers), separate from the login
 * password and stored only as a hash.
 *
 *  - Setting up the first time is confirmed with the login password.
 *  - Replacing or removing the image needs the current PIN/password.
 *  - Changing the PIN/password needs the current one and the login password.
 *  - A forgotten one can be reset with the login password, or by an
 *    administrator.
 *
 * Every change is recorded and the person is told by email and in PDTS.
 * Replaced images are kept, never deleted, so each signed document can be
 * traced to the exact image used. Too many wrong credentials pause signing.
 */
class SignatureProfile
{
    /** Signature shapes accepted, as width / height: from a short initial to a long full name. */
    public const MIN_RATIO = 1.5;

    public const MAX_RATIO = 6.0;

    public function saveImage(User $user, UploadedFile $file, string $confirmation): void
    {
        $bytes = (string) file_get_contents($file->getRealPath());
        [$type, $width, $height] = $this->checkImage($bytes);

        $this->confirm($user, $confirmation);

        // Every signature the same: trimmed and fitted into 360×140 (when GD is available).
        if ($standard = app(SignatureImageNormaliser::class)->normalise($bytes)) {
            [$bytes, $type, $width, $height] = [$standard, IMAGETYPE_PNG, SignatureImageNormaliser::WIDTH, SignatureImageNormaliser::HEIGHT];
        }

        $path = 'signatures/'.$user->getKey().'/'.Str::random(40).($type === IMAGETYPE_PNG ? '.png' : '.jpg');
        Storage::disk('private')->put($path, $bytes);
        $hash = hash('sha256', $bytes);

        try {
            $action = DB::transaction(function () use ($user, $path, $hash, $width, $height) {
                $current = $user->currentSignatureImage();
                // An earlier upload still waiting for ICT is replaced by this one (kept on record).
                $user->signatureImages()->where('status', UserSignatureImage::PENDING)
                    ->update(['status' => UserSignatureImage::WITHDRAWN, 'retired_at' => now()]);

                // It waits for ICT to verify it. A signature already in use keeps working until then.
                $user->signatureImages()->create([
                    'storage_path' => $path, 'sha256' => $hash, 'width' => $width, 'height' => $height,
                    'status' => UserSignatureImage::PENDING, 'uploaded_at' => now(),
                ]);
                $user->forceFill(['signature_updated_at' => now()])->save();

                $action = $current ? 'image_replaced' : 'image_uploaded';
                $this->record($user, $user, $action, array_filter([
                    'image_sha256' => $hash,
                    'previous_image_sha256' => $current?->sha256,
                    'size' => $width.'×'.$height,
                ]));

                return $action;
            });
        } catch (Throwable $e) {
            Storage::disk('private')->delete($path);

            throw $e;
        }

        $this->tell($user, $action);
        app(SignatureVerification::class)->tellVerifiers($user);
    }

    /**
     * A PNG or JPG, large enough, and shaped like a signature: wider than
     * high, from a short initial (1.5:1) to a long full name (6:1).
     *
     * @return array{0: int, 1: int, 2: int} image type, width, height
     */
    public function checkImage(string $bytes): array
    {
        $info = @getimagesizefromstring($bytes);
        $type = $info[2] ?? null;

        if (! in_array($type, [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
            throw new RuntimeException('The signature must be a PNG or JPG picture.');
        }
        [$width, $height] = $info;
        if ($width < 150 || $height < 40) {
            throw new RuntimeException('The picture is too small ('.$width.'×'.$height.' pixels). Scan or photograph your signature more closely; about 360×140 pixels or larger works well.');
        }

        // With GD, PDTS finds the signature inside the picture (a whole phone
        // photo is fine) and checks the shape of the signature itself.
        $ink = app(SignatureImageNormaliser::class)->inkSize($bytes);
        [$shapeWidth, $shapeHeight] = $ink ?? [$width, $height];
        $ratio = $shapeWidth / max(1, $shapeHeight);

        if ($ratio < self::MIN_RATIO) {
            throw new RuntimeException($ink
                ? 'The signature in the picture is taller than it is wide. Sign across the page, as on a document, and photograph it again.'
                : 'The picture is too tall for a signature ('.$width.'×'.$height.' pixels). Crop it close around the signature so it is wider than it is high, for example 360×140 pixels.');
        }
        if ($ratio > self::MAX_RATIO) {
            throw new RuntimeException($ink
                ? 'The signature in the picture is too long and thin. Make sure only your signature is in the photo, without lines or other writing.'
                : 'The picture is too long and thin ('.$width.'×'.$height.' pixels). Crop it so the signature fills it, for example 360×140 pixels.');
        }

        return [$type, $width, $height];
    }

    /** Stops using the image. The file is kept for the record. */
    public function removeImage(User $user, string $confirmation): void
    {
        $this->confirm($user, $confirmation);

        DB::transaction(function () use ($user) {
            $current = $user->currentSignatureImage();
            $current?->update(['status' => UserSignatureImage::RETIRED, 'retired_at' => now()]);
            $user->signatureImages()->where('status', UserSignatureImage::PENDING)
                ->update(['status' => UserSignatureImage::WITHDRAWN, 'retired_at' => now()]);
            $user->forceFill(['signature_path' => null, 'signature_updated_at' => now()])->save();
            $this->record($user, $user, 'image_removed', array_filter(['image_sha256' => $current?->sha256]));
        });

        $this->tell($user, 'image_removed');
    }

    /**
     * Sets the PIN or signature password.
     *
     * @param  string  $type  "pin" or "password"
     * @param  string|null  $current  the current PIN/password, when changing it
     * @param  bool  $forgotten  resetting a forgotten one with the login password alone
     */
    public function setSecret(User $user, string $accountPassword, string $type, string $secret, ?string $current = null, bool $forgotten = false): void
    {
        if (! Hash::check($accountPassword, (string) $user->password)) {
            throw new RuntimeException('Your login password is not correct.');
        }

        $this->checkStrength($type, $secret);
        if (Hash::check($secret, (string) $user->password)) {
            throw new RuntimeException('Use a signing '.($type === 'pin' ? 'PIN' : 'password').' that is different from your login password.');
        }

        $hadOne = $user->signature_pin !== null;
        if ($hadOne && ! $forgotten) {
            $this->verifySecret($user, (string) $current);
        }

        $action = ! $hadOne ? 'secret_set' : ($forgotten ? 'secret_reset' : 'secret_changed');
        $user->forceFill([
            'signature_pin' => Hash::make($secret),
            'signature_secret_type' => $type,
            'signature_pin_updated_at' => now(),
        ])->save();
        RateLimiter::clear($this->limiterKey($user));

        $this->record($user, $user, $action, ['type' => $type]);
        $this->tell($user, $action);
    }

    /** An administrator clears a forgotten PIN/password; the person then sets a new one. */
    public function resetByAdministrator(User $user, User $administrator): void
    {
        $user->forceFill(['signature_pin' => null, 'signature_secret_type' => null, 'signature_pin_updated_at' => now()])->save();
        RateLimiter::clear($this->limiterKey($user));

        $this->record($user, $administrator, 'secret_reset_by_admin');
        $this->tell($user, 'secret_reset_by_admin', $administrator);
    }

    /**
     * Checks the signing PIN/password. Throws when it is wrong, or when
     * signing is paused after too many wrong tries.
     */
    public function verifySecret(User $user, string $secret): void
    {
        $key = $this->limiterKey($user);
        $max = max(1, (int) config('pdts.signatures.max_pin_attempts', 5));
        $name = $user->signatureSecretName();

        if (RateLimiter::tooManyAttempts($key, $max)) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);

            throw new RuntimeException('Too many wrong attempts. Signing is paused for '.$minutes.' minute'.($minutes === 1 ? '' : 's').'.');
        }

        if (! $user->signature_pin || ! Hash::check($secret, $user->signature_pin)) {
            RateLimiter::hit($key, 60 * max(1, (int) config('pdts.signatures.lockout_minutes', 15)));
            $left = $max - RateLimiter::attempts($key);

            throw new RuntimeException($left > 0
                ? 'The '.$name.' is not correct. '.$left.' attempt'.($left === 1 ? '' : 's').' left.'
                : 'The '.$name.' is not correct. Signing is now paused for a while.');
        }

        RateLimiter::clear($key);
    }

    /**
     * Changes to the image are confirmed with the current PIN/password, or,
     * before one has been set, with the login password.
     */
    private function confirm(User $user, string $confirmation): void
    {
        if ($user->signature_pin !== null) {
            $this->verifySecret($user, $confirmation);

            return;
        }

        if (! Hash::check($confirmation, (string) $user->password)) {
            throw new RuntimeException('Your login password is not correct.');
        }
    }

    private function checkStrength(string $type, string $secret): void
    {
        if ($type === 'pin') {
            if (! preg_match('/^\d{4,8}$/', $secret)) {
                throw new RuntimeException('The signature PIN must be 4 to 8 digits.');
            }

            return;
        }

        if ($type !== 'password') {
            throw new RuntimeException('Choose a PIN or a signature password.');
        }
        if (mb_strlen($secret) < 8 || mb_strlen($secret) > 64 || ! preg_match('/\pL/u', $secret) || ! preg_match('/\d/', $secret)) {
            throw new RuntimeException('The signature password must be 8 to 64 characters with both letters and numbers.');
        }
    }

    public function record(User $user, User $actor, string $action, array $details = []): void
    {
        $user->signatureEvents()->create([
            'actor_id' => $actor->getKey(),
            'action' => $action,
            'details' => $details ?: null,
            'ip_address' => request()?->ip(),
            'occurred_at' => now(),
        ]);
    }

    /** Email and bell notice. A mail server that cannot be reached never stops the change. */
    public function tell(User $user, string $action, ?User $actor = null): void
    {
        try {
            $user->notify(new SignatureChangedNotification($action, $actor ?? $user));
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function limiterKey(User $user): string
    {
        return 'pdts-signature-pin:'.$user->getKey();
    }
}
