<?php

namespace App\Services\Pdts\Signatures;

use App\Models\User;
use App\Models\UserSignatureImage;
use App\Notifications\SignaturePendingNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * ICT verifies each uploaded signature before it can be used: they compare it
 * with the employee's specimen on file and approve it, or reject it with a
 * reason. They can also suspend a signature in use, at once (e.g. suspected
 * misuse), and reinstate it. Nobody can verify their own signature.
 *
 * Every decision is recorded in the person's signature history with the
 * verifier's name, and the person is told by email.
 */
class SignatureVerification
{
    public const PERMISSION = 'signatures.verify';

    public function __construct(private readonly SignatureProfile $profile) {}

    public function pending(): Builder
    {
        return UserSignatureImage::query()->where('status', UserSignatureImage::PENDING);
    }

    public function approve(UserSignatureImage $image, User $verifier): void
    {
        $this->guard($image->user, $verifier);
        if ($image->status !== UserSignatureImage::PENDING) {
            throw new RuntimeException('This signature is not waiting for verification.');
        }

        $user = $image->user;
        DB::transaction(function () use ($image, $user, $verifier) {
            // The signature it replaces is kept on record, no longer used.
            $previous = $user->currentSignatureImage();
            $previous?->update(['status' => UserSignatureImage::RETIRED, 'retired_at' => now()]);

            $image->update(['status' => UserSignatureImage::ACTIVE, 'reviewed_by' => $verifier->id, 'reviewed_at' => now(), 'review_note' => null]);
            $user->forceFill(['signature_path' => $image->storage_path, 'signature_updated_at' => now()])->save();

            $this->profile->record($user, $verifier, 'image_approved', array_filter([
                'image_sha256' => $image->sha256,
                'previous_image_sha256' => $previous?->sha256,
            ]));
        });

        $this->profile->tell($user, 'image_approved', $verifier);
    }

    public function reject(UserSignatureImage $image, User $verifier, string $reason): void
    {
        $this->guard($image->user, $verifier);
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new RuntimeException('Give the reason, so the employee knows what to fix.');
        }
        if ($image->status !== UserSignatureImage::PENDING) {
            throw new RuntimeException('This signature is not waiting for verification.');
        }

        DB::transaction(function () use ($image, $verifier, $reason) {
            $image->update(['status' => UserSignatureImage::REJECTED, 'reviewed_by' => $verifier->id, 'reviewed_at' => now(), 'review_note' => $reason, 'retired_at' => now()]);
            $this->profile->record($image->user, $verifier, 'image_rejected', ['image_sha256' => $image->sha256, 'reason' => $reason]);
        });

        $this->profile->tell($image->user, 'image_rejected', $verifier);
    }

    /** Stops a signature in use from signing, at once. Documents already signed stay valid. */
    public function suspend(User $user, User $verifier, string $reason): void
    {
        $this->guard($user, $verifier);
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new RuntimeException('Give the reason for suspending the signature.');
        }
        $image = $user->currentSignatureImage();
        if ($image?->status !== UserSignatureImage::ACTIVE) {
            throw new RuntimeException('This person has no signature in use.');
        }

        DB::transaction(function () use ($image, $user, $verifier, $reason) {
            $image->update(['status' => UserSignatureImage::SUSPENDED, 'reviewed_by' => $verifier->id, 'reviewed_at' => now(), 'review_note' => $reason]);
            $this->profile->record($user, $verifier, 'signature_suspended', ['image_sha256' => $image->sha256, 'reason' => $reason]);
        });

        $this->profile->tell($user, 'signature_suspended', $verifier);
    }

    public function reinstate(User $user, User $verifier): void
    {
        $this->guard($user, $verifier);
        $image = $user->currentSignatureImage();
        if ($image?->status !== UserSignatureImage::SUSPENDED) {
            throw new RuntimeException('This signature is not suspended.');
        }

        DB::transaction(function () use ($image, $user, $verifier) {
            $image->update(['status' => UserSignatureImage::ACTIVE, 'reviewed_by' => $verifier->id, 'reviewed_at' => now(), 'review_note' => null]);
            $this->profile->record($user, $verifier, 'signature_reinstated', ['image_sha256' => $image->sha256]);
        });

        $this->profile->tell($user, 'signature_reinstated', $verifier);
    }

    /** ICT staff are told in PDTS (the bell) that a signature is waiting for them. */
    public function tellVerifiers(User $owner): void
    {
        try {
            User::permission(self::PERMISSION)
                ->where('id', '!=', $owner->id)
                ->where('is_banned', false)
                ->get()
                ->each(fn (User $verifier) => $verifier->notify(new SignaturePendingNotification($owner)));
        } catch (Throwable $e) {
            report($e); // e.g. the permission is not set up yet
        }
    }

    private function guard(?User $owner, User $verifier): void
    {
        if (! $verifier->can(self::PERMISSION)) {
            throw new RuntimeException('Only ICT staff with the "Verify signatures" permission can do this.');
        }
        if (! $owner || (int) $owner->id === (int) $verifier->id) {
            throw new RuntimeException('Nobody can verify their own signature. Ask a colleague in ICT.');
        }
    }
}
