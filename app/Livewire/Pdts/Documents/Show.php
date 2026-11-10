<?php

namespace App\Livewire\Pdts\Documents;

use App\Enums\DocumentStatus;
use App\Exceptions\WorkflowTransitionException;
use App\Models\PdtsDocument;
use App\Services\Workflows\PdtsDocumentApprovalService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Show extends Component
{
    public PdtsDocument $document;
    public string $approvalComment = '';

    public function mount(PdtsDocument $document): void
    {
        $this->document = $document->load([
            'documentType',
            'registrar',
            'currentCustodian',
            'attachments.uploader',
            'auditLogs.actor',
        ]);
    }

    public function submitForApproval(): void
    {
        try {
            app(PdtsDocumentApprovalService::class)->submit($this->document, Auth::user());
            $this->resetErrorBag('approval');
            session()->flash('approval_success', 'Document submitted for approval.');
        } catch (WorkflowTransitionException $exception) {
            $this->addError('approval', $exception->getMessage());
        }
    }

    public function actOnApproval(string $action): void
    {
        try {
            app(PdtsDocumentApprovalService::class)->act(
                $this->document,
                Auth::user(),
                $action,
                $this->approvalComment,
            );
            $this->approvalComment = '';
            $this->resetErrorBag('approval');
            session()->flash('approval_success', 'Approval action recorded.');
        } catch (WorkflowTransitionException $exception) {
            $this->addError('approval', $exception->getMessage());
        }
    }

    public function render()
    {
        $this->document->refresh();
        $approvalInstances = $this->document->workflowInstances()
            ->with(['actions.actor', 'initiator', 'completedBy'])
            ->get();
        $currentApproval = $approvalInstances->firstWhere('status', 'Pending');
        $approvalStep = $currentApproval
            ? collect(data_get($currentApproval->workflow_snapshot, 'steps', []))
                ->first(fn (array $step) => (int) ($step['id'] ?? 0) === (int) $currentApproval->current_step_id)
            : null;
        $userId = (int) Auth::id();
        $canActOnApproval = $currentApproval
            && in_array($userId, array_map('intval', $currentApproval->current_approver_ids ?? []), true);
        $canSubmitForApproval = ! $currentApproval
            && ! in_array($this->document->status, [DocumentStatus::Completed->value, DocumentStatus::Cancelled->value], true)
            && ($userId === (int) $this->document->registered_by || $userId === (int) $this->document->current_custodian_id);

        return view('livewire.pdts.documents.show')
            ->with(compact('approvalInstances', 'currentApproval', 'approvalStep', 'canActOnApproval', 'canSubmitForApproval'))
            ->layout('layouts.master', ['title' => $this->document->reference_number]);
    }
}