<?php

namespace App\Livewire\Pdts\Signatures;

use App\Models\User;
use App\Models\UserSignatureImage;
use App\Services\Pdts\Signatures\SignatureVerification;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

/**
 * ICT's signature verification: uploads waiting to be verified (approve, or
 * reject with a reason), and signatures in use (suspend, reinstate).
 */
class VerificationQueue extends Component
{
    #[Url]
    public string $tab = 'pending';

    public string $search = '';

    /** Reasons typed per image (reject) or per person (suspend). */
    public array $reasons = [];

    public function approve(int $imageId, SignatureVerification $verification): void
    {
        $this->act(fn () => $verification->approve(UserSignatureImage::with('user')->findOrFail($imageId), Auth::user()), 'image-'.$imageId, 'Approved. The employee can now sign with it and has been told.');
    }

    public function reject(int $imageId, SignatureVerification $verification): void
    {
        $this->act(fn () => $verification->reject(UserSignatureImage::with('user')->findOrFail($imageId), Auth::user(), (string) ($this->reasons['image-'.$imageId] ?? '')), 'image-'.$imageId, 'Rejected. The employee has been told the reason.');
    }

    public function suspend(int $userId, SignatureVerification $verification): void
    {
        $this->act(fn () => $verification->suspend(User::findOrFail($userId), Auth::user(), (string) ($this->reasons['user-'.$userId] ?? '')), 'user-'.$userId, 'Suspended at once. The employee has been told.');
    }

    public function reinstate(int $userId, SignatureVerification $verification): void
    {
        $this->act(fn () => $verification->reinstate(User::findOrFail($userId), Auth::user()), 'user-'.$userId, 'Reinstated. The employee can sign again.');
    }

    private function act(callable $action, string $key, string $done): void
    {
        try {
            $action();
        } catch (RuntimeException $e) {
            $this->addError($key, $e->getMessage());

            return;
        }

        unset($this->reasons[$key]);
        session()->flash('success', $done);
    }

    public function render()
    {
        $search = trim($this->search);
        $matching = fn ($q) => $search === '' ? $q : $q->whereHas('user', fn ($u) => $u
            ->where('name', 'like', "%{$search}%")->orWhere('man_no', 'like', "%{$search}%"));

        $pending = UserSignatureImage::query()->where('status', UserSignatureImage::PENDING)
            ->with('user')->tap($matching)->orderBy('uploaded_at')->get();

        $inUse = UserSignatureImage::query()->whereIn('status', [UserSignatureImage::ACTIVE, UserSignatureImage::SUSPENDED])
            ->whereHas('user', fn ($u) => $u->whereColumn('users.signature_path', 'user_signature_images.storage_path'))
            ->with(['user', 'reviewer'])->tap($matching)->orderByDesc('reviewed_at')->limit(200)->get();

        $decided = UserSignatureImage::query()->whereNotNull('reviewed_at')
            ->with(['user', 'reviewer'])->tap($matching)->orderByDesc('reviewed_at')->limit(50)->get();

        // Each pending person's signature in use now, to compare with the new one.
        $currentByUser = UserSignatureImage::query()->where('status', UserSignatureImage::ACTIVE)
            ->whereIn('user_id', $pending->pluck('user_id'))->get()->keyBy('user_id');

        return view('livewire.pdts.signatures.verification-queue', [
            'pending' => $pending,
            'inUse' => $inUse,
            'decided' => $decided,
            'currentByUser' => $currentByUser,
        ])->layout('layouts.master', ['title' => 'Signature Verification']);
    }
}
