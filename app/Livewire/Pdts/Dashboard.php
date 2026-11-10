<?php

namespace App\Livewire\Pdts;

use App\Enums\DocumentStatus;
use App\Enums\MovementStatus;
use App\Models\DocumentMovement;
use App\Models\PdtsDocument;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Home page. Everyone sees their own work; people with reports.view
 * (Management / Auditor, administrators) also see the whole organisation.
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

        return view('livewire.pdts.dashboard', [
            'firstName' => str($user->name ?? 'there')->before(' ')->toString(),
            'overdueAfterDays' => $overdueAfterDays,
            'cutoff' => $cutoff,
            'mine' => $this->mine($user, $cutoff),
            'organisation' => $user->can('reports.view') ? $this->organisation($cutoff) : null,
        ])->layout('layouts.master', [
            'title' => 'PDTS Dashboard',
        ]);
    }

    private function mine(User $user, $cutoff): array
    {
        $waiting = DocumentMovement::query()
            ->where('recipient_id', $user->id)
            ->where('status', MovementStatus::AwaitingReceipt->value)
            ->whereHas('document');

        $sent = DocumentMovement::query()
            ->where('sender_id', $user->id)
            ->where('status', MovementStatus::AwaitingReceipt->value)
            ->whereHas('document');

        return [
            'canAcknowledge' => $user->can('documents.acknowledge'),
            'canForward' => $user->can('documents.forward'),
            'canRegister' => $user->can('documents.register'),
            'waitingCount' => (clone $waiting)->count(),
            'waitingOverdue' => (clone $waiting)->where('forwarded_at', '<=', $cutoff)->count(),
            'waitingList' => (clone $waiting)
                ->with(['sender', 'document'])
                ->oldest('forwarded_at')
                ->limit(5)
                ->get(),
            'custodyCount' => PdtsDocument::query()
                ->where('current_custodian_id', $user->id)
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

    private function organisation($cutoff): array
    {
        $counts = PdtsDocument::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $byStatus = collect(DocumentStatus::cases())
            ->map(fn (DocumentStatus $status) => [
                'status' => $status,
                'total' => (int) ($counts[$status->value] ?? 0),
            ]);

        $since = now()->subDays(30);
        $overdue = DocumentMovement::query()
            ->where('status', MovementStatus::AwaitingReceipt->value)
            ->where('forwarded_at', '<=', $cutoff)
            ->whereHas('document');

        return [
            'total' => $byStatus->sum('total'),
            'byStatus' => $byStatus,
            'open' => $byStatus->whereIn('status', self::OPEN_STATUSES)->sum('total'),
            'registered30' => PdtsDocument::query()->where('registered_at', '>=', $since)->count(),
            'received30' => DocumentMovement::query()
                ->where('status', MovementStatus::Acknowledged->value)
                ->where('acknowledged_at', '>=', $since)
                ->count(),
            'declined30' => DocumentMovement::query()
                ->where('status', MovementStatus::Declined->value)
                ->where('closed_at', '>=', $since)
                ->count(),
            'overdueCount' => (clone $overdue)->count(),
            'overdueList' => (clone $overdue)
                ->with(['sender', 'recipient', 'document'])
                ->oldest('forwarded_at')
                ->limit(8)
                ->get(),
        ];
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
