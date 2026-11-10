<?php

namespace App\Livewire\Pdts\Approvals;

use App\Models\PdtsDocument;
use App\Models\WorkflowInstance;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class Inbox extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    public function render()
    {
        $approvals = WorkflowInstance::query()
            ->where('workflowable_type', PdtsDocument::class)
            ->where('status', 'Pending')
            ->whereJsonContains('current_approver_ids', (int) Auth::id())
            ->with('workflowable')
            ->orderBy('initiated_at')
            ->paginate(15);

        return view('livewire.pdts.approvals.inbox', compact('approvals'))
            ->layout('layouts.master', ['title' => 'Approval Inbox']);
    }
}