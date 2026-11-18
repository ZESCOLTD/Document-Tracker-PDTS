<?php

namespace App\Services\Documents;

use App\Models\PdtsDocument;
use App\Models\PdtsDocumentAuditLog;
use App\Models\User;
use App\Notifications\DocumentReportedNotification;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * A person not involved with a document scanned it and reports that the
 * paper is with them by mistake. Custody does not change: the report is
 * recorded in the history and the people responsible are told.
 */
class FoundDocumentReports
{
    public const DAILY_LIMIT = 10;

    public function alreadyReported(PdtsDocument $document, User $user): bool
    {
        return $document->auditLogs()
            ->where('action', 'reported_found')
            ->where('actor_id', $user->getKey())
            ->exists();
    }

    public function report(PdtsDocument $document, User $reporter, ?string $note): void
    {
        $note = trim((string) $note) ?: null;

        if ($this->alreadyReported($document, $reporter)) {
            throw new CustodyException('You have already reported this document. The people responsible have been told.');
        }

        $today = PdtsDocumentAuditLog::query()
            ->where('action', 'reported_found')
            ->where('actor_id', $reporter->getKey())
            ->where('occurred_at', '>=', now()->startOfDay())
            ->count();

        if ($today >= self::DAILY_LIMIT) {
            throw new CustodyException('You have reached today\'s limit for reports. Please hand the document to your nearest registry.');
        }

        $document->auditLogs()->create([
            'actor_id' => $reporter->getKey(),
            'action' => 'reported_found',
            'details' => [
                'note' => $note,
                'reporter_office' => $reporter->officeName(),
            ],
            'occurred_at' => now(),
        ]);

        $pending = $document->pendingMovement()->with(['sender', 'recipient'])->first();
        $people = collect([$document->currentCustodian, $pending?->sender, $pending?->recipient])
            ->filter()
            ->unique('id')
            ->reject(fn (User $user) => $user->is($reporter));

        try {
            Notification::send($people, new DocumentReportedNotification($document, $reporter, $note));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
