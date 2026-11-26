<?php

namespace App\Services\Pdts\Signatures;

use App\Enums\DocumentStatus;
use App\Models\PdtsDocument;
use App\Models\User;
use App\Models\UserSignatureImage;
use App\Services\Pdts\Distribution\DocumentDistribution;
use App\Services\Pdts\InternalDocuments;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Signs an internal draft in PDTS instead of on paper:
 *
 *  1. the author's signature PIN or password is checked;
 *  2. the template is filled with what the author entered, with their
 *     signature image on the template's signature spot and the QR code;
 *  3. it is turned into a PDF, which becomes the document's official copy;
 *  4. who signed, when, from where and the SHA-256 fingerprint of the PDF
 *     are recorded, and the draft is registered (so it can be sent).
 *
 * Only the author can sign, only with their own signature, and only once.
 */
class ElectronicSigning
{
    public function __construct(
        private readonly SignatureProfile $profile,
        private readonly InternalDocuments $documents,
        private readonly PdfConverter $converter,
    ) {}

    /** Why this person cannot sign this document now, or null when they can. */
    public function blocker(PdtsDocument $document, User $user): ?string
    {
        return match (true) {
            ! $document->isDraft() => 'This document has already been registered.',
            $document->signature_status === PreparedDocuments::DISCARDED => 'This draft was discarded.',
            $document->signatoryId() !== (int) $user->getKey() => 'Only '.($document->isPreparedOnBehalf() ? $document->signatory?->name : 'the author').' can sign this document.',
            $document->isPreparedOnBehalf() && $document->signature_status !== PdtsDocument::SIGNATURE_AWAITING => $document->signature_status === PdtsDocument::SIGNATURE_RETURNED
                ? 'You returned this draft for correction. It can be signed when it is sent back to you.'
                : 'This draft has not been sent to you for signature yet.',
            ! $document->template?->supportsElectronicSignature() => 'This template has no place for a signature, so it is signed on paper.',
            ! $user->canSignElectronically() => $this->notReady($user),
            ! $this->converter->available() => 'Signing in PDTS is not available on this server yet (LibreOffice is not installed). Sign on paper and upload the scan, or ask the system administrator.',
            default => null,
        };
    }

    /** Why this person's signature cannot be used yet. */
    private function notReady(User $user): string
    {
        $current = $user->currentSignatureImage();

        return match (true) {
            $current?->status === UserSignatureImage::SUSPENDED => 'Your signature has been suspended by ICT. Contact ICT.',
            ! $current && $user->pendingSignatureImage() => 'Your signature is waiting for ICT to verify it. You can sign once it is approved.',
            ! $current => 'Set up your signature first; ICT then verifies it.',
            default => 'Set your signing PIN or password first.',
        };
    }

    /**
     * @param  array{ip?: ?string, user_agent?: ?string}  $context
     */
    public function sign(PdtsDocument $document, User $user, string $pin, array $context = [], bool $reviewed = true): PdtsDocument
    {
        $document->loadMissing('template', 'registrar', 'signatory');

        if ($reason = $this->blocker($document, $user)) {
            throw new RuntimeException($reason);
        }
        if (! $reviewed) {
            throw new RuntimeException('Confirm that you have reviewed the document before signing.');
        }

        $this->profile->verifySecret($user, $pin);

        $image = Storage::disk('private')->get($user->signature_path);
        if (! $image) {
            throw new RuntimeException('Your signature picture is missing. Upload it again under My Signature.');
        }

        $pdf = $this->converter->convert($this->documents->filledWord($document, $this->signatureFor($image)));
        $hash = hash('sha256', $pdf);

        $path = 'pdts/documents/'.Str::random(40).'.pdf';
        Storage::disk('private')->put($path, $pdf);

        try {
            $signed = DB::transaction(function () use ($document, $user, $context, $path, $pdf, $hash, $image): PdtsDocument {
                $locked = PdtsDocument::query()->lockForUpdate()->findOrFail($document->getKey());
                if (! $locked->isDraft() || ($locked->isPreparedOnBehalf() && $locked->signature_status !== PdtsDocument::SIGNATURE_AWAITING)) {
                    throw new RuntimeException('This document is no longer waiting for a signature.');
                }

                $subject = Str::of($locked->subject)->replaceMatches('/[^A-Za-z0-9 ._-]+/', '')->squish()->limit(60, '');

                $attachment = $locked->attachments()->create([
                    'uploaded_by' => $user->id,
                    'storage_path' => $path,
                    'original_filename' => trim($locked->reference_number.' '.$subject).' (signed).pdf',
                    'mime_type' => 'application/pdf',
                    'file_size' => strlen($pdf),
                    'is_primary' => true,
                ]);

                $locked->electronicSignature()->create([
                    'user_id' => $user->id,
                    'attachment_id' => $attachment->id,
                    'signature_image_id' => $user->currentSignatureImage()?->id,
                    'signed_at' => now(),
                    'file_sha256' => $hash,
                    'signature_image_sha256' => hash('sha256', $image),
                    'ip_address' => $context['ip'] ?? null,
                    'user_agent' => isset($context['user_agent']) ? Str::limit((string) $context['user_agent'], 490, '') : null,
                ]);

                // Custody does not change: whoever prepared the draft holds it and sends it on.
                $locked->update([
                    'status' => DocumentStatus::Registered->value,
                    'registered_at' => now(),
                    'signatory_id' => $user->id,
                    'signature_status' => PdtsDocument::SIGNATURE_SIGNED,
                ]);

                $locked->auditLogs()->create([
                    'actor_id' => $user->id,
                    'action' => 'signed_electronically',
                    'details' => array_filter([
                        'reference_number' => $locked->reference_number,
                        'file_sha256' => $hash,
                        'prepared_by' => (int) $locked->registered_by !== (int) $user->id ? $locked->registered_by : null,
                        'ip_address' => $context['ip'] ?? null,
                    ]),
                    'occurred_at' => now(),
                ]);

                $locked->auditLogs()->create([
                    'actor_id' => $user->id,
                    'action' => 'registered',
                    'details' => [
                        'reference_number' => $locked->reference_number,
                        'initial_custodian_id' => $locked->current_custodian_id,
                        'from_draft' => true,
                        'signed_electronically' => true,
                    ],
                    'occurred_at' => now(),
                ]);

                return $locked;
            });
        } catch (Throwable $exception) {
            Storage::disk('private')->delete($path);

            throw $exception;
        }

        // Tell the secretary who prepared it that it is signed and with them to send on.
        if ($signed->isPreparedOnBehalf()) {
            app(PreparedDocuments::class)->tell($signed->registrar, $signed, 'signed', $user);
        }

        // The person it is for, and the people copied, are told and can open it.
        app(DocumentDistribution::class)->onRegistered($signed);

        return $signed;
    }

    /**
     * The signature picture at its printed size: a set height, narrower when
     * a wide signature would be too long.
     *
     * @return array{image: string, extension: string, width_mm: float, height_mm: float}
     */
    private function signatureFor(string $image): array
    {
        $info = @getimagesizefromstring($image);
        $ratio = $info && $info[1] > 0 ? $info[0] / $info[1] : 3.0;

        $height = (float) config('pdts.signatures.height_mm', 14.0);
        $width = $height * $ratio;
        $maxWidth = (float) config('pdts.signatures.max_width_mm', 50.0);
        if ($width > $maxWidth) {
            $width = $maxWidth;
            $height = $maxWidth / $ratio;
        }

        return [
            'image' => $image,
            'extension' => ($info[2] ?? null) === IMAGETYPE_JPEG ? 'jpg' : 'png',
            'width_mm' => round($width, 2),
            'height_mm' => round($height, 2),
        ];
    }
}
