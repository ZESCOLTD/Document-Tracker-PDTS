<?php

namespace App\Livewire\Pdts;

use App\Enums\DocumentStatus;
use App\Enums\MovementStatus;
use App\Models\DocumentMovement;
use App\Models\PdtsDocument;
use App\Models\PdtsDocumentAuditLog;
use App\Models\User;
use App\Services\Documents\WorkCounts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Home page. Everyone sees their own work; people with reports.view
 * (Management / Auditor, administrators) see figures for the whole organisation.
 */
class Dashboard extends Component
{
    private const OPEN_STATUSES = [
        DocumentStatus::Registered,
        DocumentStatus::AwaitingReceipt,
        DocumentStatus::Received,
    ];

    public function render()
    {
        /** @var User $user */
        $user = Auth::user();
        $overdueAfterDays = (int) config('pdts.overdue_after_days');
        $cutoff = now()->subDays($overdueAfterDays);
        $mine = $this->mine($user, $cutoff);

        return view('livewire.pdts.dashboard', [
            'firstName' => str($user->name ?? 'there')->before(' ')->toString(),
            'overdueAfterDays' => $overdueAfterDays,
            'cutoff' => $cutoff,
            'mine' => $mine,
            'board' => $this->board($user, $cutoff, $mine, $user->can('reports.view')),
        ])->layout('layouts.master', [
            'title' => 'PDTS Dashboard',
        ]);
    }

    private function mine(User $user, $cutoff): array
    {
        $waiting = app(WorkCounts::class)->pendingAcknowledgementsQuery($user);

        $sent = DocumentMovement::query()
            ->where('sender_id', $user->id)
            ->where('status', MovementStatus::AwaitingReceipt->value)
            ->whereHas('document');

        return [
            'canAcknowledge' => $user->can('documents.acknowledge'),
            'canForward' => $user->can('documents.forward'),
            'canRegister' => $user->can('documents.register'),
            'canViewDocuments' => $user->can('documents.view'),
            'waitingCount' => (clone $waiting)->count(),
            'waitingOverdue' => (clone $waiting)->where('forwarded_at', '<=', $cutoff)->count(),
            'waitingList' => (clone $waiting)
                ->with(['sender', 'document'])
                ->oldest('forwarded_at')
                ->limit(5)
                ->get(),
            'custodyCount' => app(WorkCounts::class)->custodyQuery($user)
                ->whereIn('status', $this->values(self::OPEN_STATUSES))
                ->count(),
            'sentCount' => (clone $sent)->count(),
            'sentOverdue' => (clone $sent)->where('forwarded_at', '<=', $cutoff)->count(),
            'sentList' => (clone $sent)
                ->with(['recipient', 'document'])
                ->oldest('forwarded_at')
                ->limit(5)
                ->get(),
            'registeredOpen' => PdtsDocument::query()
                ->where('registered_by', $user->id)
                ->whereIn('status', $this->values(self::OPEN_STATUSES))
                ->count(),
            'registeredTotal' => PdtsDocument::query()->where('registered_by', $user->id)->count(),
        ];
    }

    /**
     * Figures behind the KPI cards, charts and recent documents table.
     * Management sees every document; everyone else sees the documents
     * they registered, hold, sent or received.
     */
    private function board(User $user, $cutoff, array $mine, bool $organisationWide): array
    {
        $documents = fn (): Builder => $this->scopedDocuments($user, $organisationWide);
        $now = now();
        $last30 = $now->copy()->subDays(30);
        $prior30 = $now->copy()->subDays(60);

        $counts = $documents()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $byStatus = collect(DocumentStatus::cases())
            ->map(fn (DocumentStatus $status) => [
                'status' => $status,
                'total' => (int) ($counts[$status->value] ?? 0),
            ]);
        $countOf = fn (DocumentStatus $status): int => $byStatus->firstWhere('status', $status)['total'];

        $registeredBetween = fn ($from, $to) => $documents()
            ->where('registered_at', '>=', $from)->where('registered_at', '<', $to)->count();
        $completedBetween = fn ($from, $to) => $this->completions($documents)
            ->where('occurred_at', '>=', $from)->where('occurred_at', '<', $to)->count();
        $forwardedBetween = fn (string $column, $from, $to) => DocumentMovement::query()
            ->where($column, $user->id)
            ->where('forwarded_at', '>=', $from)
            ->where('forwarded_at', '<', $to)
            ->count();

        // Handovers that were (or still are) not acknowledged within the deadline,
        // grouped by when they were sent, to compare the two 30-day periods.
        $overdueAfterDays = (int) config('pdts.overdue_after_days');
        $handovers = DocumentMovement::query()
            ->when(! $organisationWide, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('sender_id', $user->id)
                ->orWhere('recipient_id', $user->id)))
            ->where('forwarded_at', '>=', $prior30)
            ->get(['forwarded_at', 'acknowledged_at', 'closed_at']);
        $lateBetween = fn ($from, $to) => $handovers
            ->filter(fn (DocumentMovement $movement) => $movement->forwarded_at->gte($from)
                && $movement->forwarded_at->lt($to)
                && ($movement->acknowledged_at ?? $movement->closed_at ?? $now)->gt($movement->forwarded_at->copy()->addDays($overdueAfterDays)))
            ->count();

        $overdue = DocumentMovement::query()
            ->where('status', MovementStatus::AwaitingReceipt->value)
            ->where('forwarded_at', '<=', $cutoff)
            ->whereHas('document');

        return [
            'organisationWide' => $organisationWide,
            'total' => $byStatus->sum('total'),
            'byStatus' => $byStatus,
            'open' => $byStatus->whereIn('status', self::OPEN_STATUSES)->sum('total'),
            'openTrend' => $this->trend($registeredBetween($last30, $now), $registeredBetween($prior30, $last30)),
            'pendingTrend' => $this->trend(
                $forwardedBetween('recipient_id', $last30, $now),
                $forwardedBetween('recipient_id', $prior30, $last30),
            ),
            'sentTrend' => $this->trend(
                $forwardedBetween('sender_id', $last30, $now),
                $forwardedBetween('sender_id', $prior30, $last30),
            ),
            'overdueCount' => $organisationWide ? $overdue->count() : $mine['waitingOverdue'] + $mine['sentOverdue'],
            'overdueTrend' => $this->trend($lateBetween($last30, $now), $lateBetween($prior30, $last30)),
            'completed' => $countOf(DocumentStatus::Completed),
            'completedTrend' => $this->trend($completedBetween($last30, $now), $completedBetween($prior30, $last30)),
            'workflow' => [
                ['label' => 'Registered', 'total' => $countOf(DocumentStatus::Registered)],
                ['label' => 'With Sender', 'total' => $countOf(DocumentStatus::AwaitingReceipt)],
                ['label' => 'With Receiver', 'total' => $countOf(DocumentStatus::Received)],
                ['label' => 'Completed', 'total' => $countOf(DocumentStatus::Completed)],
            ],
            'trendChart' => $this->movementTrend($documents),
            'recent' => $documents()
                ->with(['currentCustodian', 'pendingMovement'])
                ->latest('registered_at')
                ->latest('id')
                ->limit(5)
                ->get(),
        ];
    }

    private function scopedDocuments(User $user, bool $organisationWide): Builder
    {
        $query = PdtsDocument::query();

        if ($organisationWide) {
            return $query;
        }

        return $query->where(fn (Builder $query) => $query
            ->where('registered_by', $user->id)
            ->orWhere('current_custodian_id', $user->id)
            ->orWhereHas('movements', fn (Builder $movements) => $movements
                ->where('sender_id', $user->id)
                ->orWhere('recipient_id', $user->id)));
    }

    private function completions(callable $documents): Builder
    {
        return PdtsDocumentAuditLog::query()
            ->where('action', 'document_completed')
            ->whereIn('pdts_document_id', $documents()->select('id'));
    }

    /**
     * Documents registered and completed on each of the last seven days.
     */
    private function movementTrend(callable $documents): array
    {
        $from = now()->subDays(6)->startOfDay();

        $registered = $documents()
            ->where('registered_at', '>=', $from)
            ->selectRaw('DATE(registered_at) as day, count(*) as total')
            ->groupByRaw('DATE(registered_at)')
            ->pluck('total', 'day');

        $completed = $this->completions($documents)
            ->where('occurred_at', '>=', $from)
            ->selectRaw('DATE(occurred_at) as day, count(*) as total')
            ->groupByRaw('DATE(occurred_at)')
            ->pluck('total', 'day');

        $days = collect(range(0, 6))->map(fn (int $offset) => $from->copy()->addDays($offset));

        return [
            'labels' => $days->map(fn ($day) => $day->format('j M'))->all(),
            'registered' => $days->map(fn ($day) => (int) ($registered[$day->toDateString()] ?? 0))->all(),
            'completed' => $days->map(fn ($day) => (int) ($completed[$day->toDateString()] ?? 0))->all(),
        ];
    }

    /**
     * Change between the last 30 days and the 30 days before, as a whole
     * percentage. Growth from nothing counts as +100%.
     */
    private function trend(int $current, int $previous): int
    {
        if ($previous === 0) {
            return $current > 0 ? 100 : 0;
        }

        return (int) round(($current - $previous) / $previous * 100);
    }

    /**
     * @param  array<int, DocumentStatus>  $statuses
     * @return array<int, string>
     */
    private function values(array $statuses): array
    {
        return array_map(fn (DocumentStatus $status) => $status->value, $statuses);
    }
}
