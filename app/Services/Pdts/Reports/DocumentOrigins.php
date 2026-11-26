<?php

namespace App\Services\Pdts\Reports;

use App\Models\PdtsDocument;
use App\Models\User;
use App\Models\UserSignatureImage;
use Illuminate\Database\Eloquent\Builder;

/**
 * How documents come into PDTS, to measure the move away from paper:
 *  - made in PDTS with no paper: signed electronically, or issued from a
 *    template marked as not signed;
 *  - made in PDTS but signed on paper (printed, signed, scanned back);
 *  - received on paper (external documents registered from the paper).
 *
 * Drafts and cancelled documents are not counted.
 */
class DocumentOrigins
{
    public const ELECTRONIC = 'electronic';

    public const PAPER_SIGNED = 'paper_signed';

    public const RECEIVED_ON_PAPER = 'received_on_paper';

    public const LABELS = [
        self::ELECTRONIC => 'Made in PDTS, no paper (signed in PDTS, or not signed)',
        self::PAPER_SIGNED => 'Made in PDTS, signed on paper',
        self::RECEIVED_ON_PAPER => 'Received on paper',
    ];

    public function summary(int $months = 6): array
    {
        $from = now()->startOfMonth()->subMonths($months - 1);
        $rows = $this->counted()->where('registered_at', '>=', $from)
            ->get(['id', 'origin', 'registered_at', 'originating_directorate', 'signature_status'])
            ->map(fn (PdtsDocument $document) => [
                'month' => $document->registered_at?->format('Y-m'),
                'directorate' => trim((string) $document->originating_directorate) ?: 'Not stated',
                'kind' => $this->kind($document),
            ]);

        $monthly = collect(range(0, $months - 1))->map(function (int $offset) use ($from, $rows) {
            $month = $from->copy()->addMonths($offset);

            return ['label' => $month->format('M Y')] + $this->tally($rows->where('month', $month->format('Y-m')));
        });

        $byDirectorate = $rows->groupBy('directorate')
            ->map(fn ($group, $name) => ['label' => $name] + $this->tally($group))
            ->sortByDesc('total')->values();

        return [
            'months' => $months,
            'monthly' => $monthly->all(),
            'byDirectorate' => $byDirectorate->all(),
            'overall' => $this->tally($rows),
            'signatures' => $this->signatureReadiness(),
            'paperSigning' => self::paperSigningMode(),
        ];
    }

    /** Documents that count: registered (not drafts) and not cancelled. */
    private function counted(): Builder
    {
        return PdtsDocument::query()
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->whereNotNull('registered_at')
            ->withExists('electronicSignature');
    }

    private function kind(PdtsDocument $document): string
    {
        if ($document->origin !== 'internal') {
            return self::RECEIVED_ON_PAPER;
        }

        return $document->electronic_signature_exists || $document->signature_status === PdtsDocument::SIGNATURE_NOT_REQUIRED
            ? self::ELECTRONIC
            : self::PAPER_SIGNED;
    }

    private function tally($rows): array
    {
        $counts = collect(array_keys(self::LABELS))->mapWithKeys(fn ($kind) => [$kind => $rows->where('kind', $kind)->count()]);
        $internal = $counts[self::ELECTRONIC] + $counts[self::PAPER_SIGNED];

        return $counts->all() + [
            'total' => $counts->sum(),
            // Of the documents ZESCO writes itself, the share made with no paper.
            'electronicShare' => $internal > 0 ? (int) round($counts[self::ELECTRONIC] / $internal * 100) : null,
        ];
    }

    /** Staff and signing officers who have a verified signature, ready to sign in PDTS. */
    private function signatureReadiness(): array
    {
        $active = User::query()->where('is_banned', 0);
        $verified = fn (Builder $query) => $query->whereNotNull('signature_pin')
            ->whereHas('signatureImages', fn (Builder $images) => $images
                ->whereColumn('user_signature_images.storage_path', 'users.signature_path')
                ->where('status', UserSignatureImage::ACTIVE));

        $grades = (array) config('pdts.signatures.officer_grades', []);
        $officers = fn () => (clone $active)->whereIn('grade', $grades);

        return [
            'staff' => (clone $active)->count(),
            'staffVerified' => $verified(clone $active)->count(),
            'officers' => $officers()->count(),
            'officersVerified' => $verified($officers())->count(),
            'awaitingCheck' => UserSignatureImage::query()->where('status', UserSignatureImage::PENDING)->count(),
        ];
    }

    /**
     * Whether internal documents may still be signed on paper:
     *  - "allowed": always offered beside signing in PDTS (the default);
     *  - "fallback": only when the signatory has no verified signature yet,
     *    or the template has no signature spot;
     *  - "off": only for templates without a signature spot.
     */
    public static function paperSigningMode(): string
    {
        $mode = strtolower((string) config('pdts.signatures.paper_signing', 'allowed'));

        return in_array($mode, ['allowed', 'fallback', 'off'], true) ? $mode : 'allowed';
    }
}
