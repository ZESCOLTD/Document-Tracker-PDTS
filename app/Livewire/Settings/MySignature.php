<?php

namespace App\Livewire\Settings;

use App\Models\PdtsUserDelegation;
use App\Models\User;
use App\Services\Pdts\Signatures\PdfConverter;
use App\Services\Pdts\Signatures\PreparedDocuments;
use App\Services\Pdts\Signatures\SignatureImageNormaliser;
use App\Services\Pdts\Signatures\SignatureProfile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;
use Livewire\WithFileUploads;
use RuntimeException;

/**
 * The employee's own signature: the picture of their handwritten signature
 * and the PIN or signature password that authorises putting it on a
 * document. Only the employee can see or change it; every change is recorded
 * and they are told about it by email.
 */
class MySignature extends Component
{
    use WithFileUploads;

    public $image;

    /** Current PIN/password, or the login password before one is set. */
    public string $imageConfirmation = '';

    public string $secretType = 'pin';

    public string $currentSecret = '';

    public string $password = '';

    public string $secret = '';

    public string $secret_confirmation = '';

    public bool $forgotten = false;

    // Authorising someone to prepare documents for me.
    public string $preparerSearch = '';

    public string $preparerId = '';

    public string $preparerStarts = '';

    public string $preparerEnds = '';

    public bool $preparerIndefinite = true;

    public function mount(): void
    {
        $this->secretType = Auth::user()->signature_secret_type ?? 'pin';
        $this->preparerStarts = now()->toDateString();
    }

    /** Picks the person from the search results. */
    public function choosePreparer(int $id): void
    {
        if ($id !== (int) Auth::id() && User::query()->where('is_banned', false)->whereKey($id)->exists()) {
            $this->preparerId = (string) $id;
            $this->preparerSearch = '';
            $this->resetValidation('preparerId');
        }
    }

    public function clearPreparer(): void
    {
        $this->preparerId = '';
    }

    public function grantPreparer(PreparedDocuments $prepared): void
    {
        abort_unless(Auth::user()->canHavePreparers(), 403, 'Only Directors, Senior Managers and Managers can authorise someone.');

        $this->validate(
            [
                'preparerId' => ['required', 'integer', 'exists:users,id'],
                'preparerStarts' => ['required', 'date'],
                'preparerEnds' => [$this->preparerIndefinite ? 'nullable' : 'required', 'nullable', 'date', 'after:preparerStarts'],
            ],
            [
                'preparerId.required' => 'Choose the person.',
                'preparerEnds.required' => 'Choose an end date, or tick "No end date".',
                'preparerEnds.after' => 'The end date must be after the start date.',
            ],
        );

        $starts = Carbon::parse($this->preparerStarts)->startOfDay();
        if ($starts->isToday()) {
            $starts = now();
        }
        $ends = $this->preparerIndefinite ? null : Carbon::parse($this->preparerEnds)->endOfDay();

        try {
            $prepared->grant(Auth::user(), User::findOrFail((int) $this->preparerId), $starts, $ends, null, Auth::user());
        } catch (RuntimeException $e) {
            $this->addError('preparerId', $e->getMessage());

            return;
        }

        $this->reset('preparerSearch', 'preparerId', 'preparerEnds');
        $this->preparerIndefinite = true;
        $this->preparerStarts = now()->toDateString();
        session()->flash('success', 'Authorised. They can now prepare documents for you to review and sign. Only you can sign them.');
    }

    public function revokePreparer(int $id, PreparedDocuments $prepared): void
    {
        $delegation = PdtsUserDelegation::query()->preparing()->findOrFail($id);
        abort_unless(in_array((int) Auth::id(), [(int) $delegation->principal_user_id, (int) $delegation->delegate_user_id], true), 403);

        $prepared->revoke($delegation, Auth::user());
        session()->flash('success', (int) $delegation->delegate_user_id === (int) Auth::id()
            ? 'You no longer prepare documents for '.$delegation->principal?->name.'.'
            : 'Ended at once. '.$delegation->delegate?->name.' can no longer prepare documents for you.');
    }

    public function updatedImage(SignatureProfile $profile): void
    {
        $this->resetErrorBag('image');
        $this->validateOnly('image', ['image' => $this->imageRules()], $this->imageMessages());

        try {
            $profile->checkImage((string) file_get_contents($this->image->getRealPath()));
        } catch (RuntimeException $e) {
            $this->addError('image', $e->getMessage());
        }
    }

    public function saveImage(SignatureProfile $profile): void
    {
        $this->validate(
            ['image' => $this->imageRules(), 'imageConfirmation' => ['required', 'string']],
            $this->imageMessages() + ['imageConfirmation.required' => $this->confirmationPrompt().' to confirm.'],
        );

        try {
            $profile->saveImage(Auth::user(), $this->image, $this->imageConfirmation);
        } catch (RuntimeException $e) {
            $this->addError(str_contains($e->getMessage(), 'picture') ? 'image' : 'imageConfirmation', $e->getMessage());
            $this->reset('imageConfirmation');

            return;
        }

        $this->reset('image', 'imageConfirmation');
        session()->flash('success', 'Your signature has been sent to ICT to verify. You will be told by email when it is approved.');
    }

    public function removeImage(SignatureProfile $profile): void
    {
        $this->validate(['imageConfirmation' => ['required', 'string']], ['imageConfirmation.required' => $this->confirmationPrompt().' to remove your signature.']);

        try {
            $profile->removeImage(Auth::user(), $this->imageConfirmation);
        } catch (RuntimeException $e) {
            $this->addError('imageConfirmation', $e->getMessage());
            $this->reset('imageConfirmation');

            return;
        }

        $this->reset('image', 'imageConfirmation');
        session()->flash('success', 'Your signature has been removed. Documents you already signed are not affected.');
    }

    public function saveSecret(SignatureProfile $profile): void
    {
        $user = Auth::user();
        $changing = $user->signature_pin !== null && ! $this->forgotten;

        $this->validate(
            [
                'secretType' => ['required', 'in:pin,password'],
                'currentSecret' => [$changing ? 'required' : 'nullable', 'string'],
                'password' => ['required', 'string'],
                'secret' => ['required', 'string', 'confirmed'],
            ],
            [
                'currentSecret.required' => 'Enter your current '.$user->signatureSecretName().'.',
                'password.required' => 'Enter your login password to confirm it is you.',
                'secret.required' => 'Enter the new '.($this->secretType === 'pin' ? 'PIN' : 'signature password').'.',
                'secret.confirmed' => 'The two entries do not match.',
            ],
        );

        try {
            $profile->setSecret($user, $this->password, $this->secretType, $this->secret, $this->currentSecret, $this->forgotten && $user->signature_pin !== null);
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
            $field = match (true) {
                str_contains($message, 'login password is not') => 'password',
                str_contains($message, 'is not correct'), str_contains($message, 'Too many') => 'currentSecret',
                default => 'secret',
            };
            $this->addError($field, $message);
            $this->reset('password', 'currentSecret');

            return;
        }

        $this->reset('password', 'currentSecret', 'secret', 'secret_confirmation', 'forgotten');
        session()->flash('success', 'Your signature '.($this->secretType === 'pin' ? 'PIN' : 'password').' has been saved. A notice has been sent to your email.');
    }

    /**
     * The new signature exactly as it will be saved (cleaned and fitted), so
     * the employee sees what ICT and documents will see. Without GD, the
     * picture as uploaded, which is then also what is saved.
     */
    private function previewUrl(): ?string
    {
        return rescue(function () {
            $bytes = (string) file_get_contents($this->image->getRealPath());
            $standard = Cache::remember('signature-preview:'.Auth::id().':'.hash('sha256', $bytes), now()->addMinutes(15),
                fn () => app(SignatureImageNormaliser::class)->normalise($bytes) ?? '');

            return $standard !== '' ? 'data:image/png;base64,'.base64_encode($standard) : $this->image->temporaryUrl();
        }, null, false);
    }

    private function confirmationPrompt(): string
    {
        return Auth::user()->signature_pin ? 'Enter your current '.Auth::user()->signatureSecretName() : 'Enter your login password';
    }

    private function imageRules(): array
    {
        return ['required', 'file', 'mimes:png,jpg,jpeg', 'max:2048'];
    }

    private function imageMessages(): array
    {
        return [
            'image.required' => 'Choose the picture of your signature.',
            'image.mimes' => 'The signature must be a PNG or JPG picture.',
            'image.max' => 'The picture must be 2 MB or smaller.',
        ];
    }

    public function render(PdfConverter $converter)
    {
        $user = Auth::user();

        return view('livewire.settings.my-signature', [
            'user' => $user,
            'hasImage' => (bool) $user->signature_path,
            'currentImage' => $current = $user->currentSignatureImage()?->loadMissing('reviewer'),
            'pendingImage' => $pending = $user->pendingSignatureImage(),
            // A rejection is shown until a newer upload replaces it.
            'rejectedImage' => $pending ? null : $user->signatureImages()->where('status', 'rejected')
                ->where('id', '>', (int) $current?->id)->latest('id')->first(),
            'ready' => $user->canSignElectronically(),
            'hasSecret' => (bool) $user->signature_pin,
            'secretName' => $user->signatureSecretName(),
            'signingAvailable' => $converter->available(),
            'events' => $user->signatureEvents()->with('actor')->limit(10)->get(),
            'canHavePreparers' => $user->canHavePreparers(),
            // Authorisations to prepare documents, current and upcoming, both ways.
            'myPreparers' => PdtsUserDelegation::query()->preparing()->whereNull('revoked_at')
                ->where('principal_user_id', $user->id)
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->with('delegate')->orderBy('starts_at')->get(),
            'preparingFor' => PdtsUserDelegation::query()->preparing()->whereNull('revoked_at')
                ->where('delegate_user_id', $user->id)
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->with('principal')->orderBy('starts_at')->get(),
            'preparerMatches' => mb_strlen(trim($this->preparerSearch)) < 2 ? collect() : User::query()
                ->where('is_banned', false)
                ->where('id', '!=', $user->id)
                ->where(fn ($q) => $q->where('name', 'like', '%'.trim($this->preparerSearch).'%')->orWhere('man_no', 'like', '%'.trim($this->preparerSearch).'%'))
                ->orderBy('name')->limit(15)->get(['id', 'name', 'man_no', 'job_title']),
            'chosenPreparer' => $this->preparerId !== '' ? User::query()->find((int) $this->preparerId, ['id', 'name', 'man_no', 'job_title']) : null,
            'previewUrl' => $this->image && ! $this->getErrorBag()->has('image') ? $this->previewUrl() : null,
        ])->layout('layouts.master', ['title' => 'My Signature']);
    }
}
