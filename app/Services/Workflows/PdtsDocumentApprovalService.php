<?php

namespace App\Services\Workflows;

use App\Enums\DocumentStatus;
use App\Exceptions\WorkflowTransitionException;
use App\Models\PdtsDocument;
use App\Models\User;
use App\Models\WorkflowAction;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowInstance;
use App\Models\WorkflowStepApprover;
use App\Notifications\PdtsDocumentApprovalNotification;
use Illuminate\Support\Facades\DB;
use Throwable;

class PdtsDocumentApprovalService
{
    private const MODULE = 'physical_document';

    public function submit(PdtsDocument $document, User $user): WorkflowInstance
    {
        $instance = DB::transaction(function () use ($document, $user): WorkflowInstance {
            $lockedDocument = PdtsDocument::query()->whereKey($document->getKey())->lockForUpdate()->firstOrFail();

            if ((int) $lockedDocument->registered_by !== (int) $user->getKey()
                && (int) $lockedDocument->current_custodian_id !== (int) $user->getKey()) {
                throw new WorkflowTransitionException('Only the registrar or current custodian can submit this document for approval.');
            }

            if (in_array($lockedDocument->status, [DocumentStatus::Completed->value, DocumentStatus::Cancelled->value], true)) {
                throw new WorkflowTransitionException('Completed or cancelled documents cannot be submitted for approval.');
            }

            $hasPendingInstance = WorkflowInstance::query()
                ->where('workflowable_type', PdtsDocument::class)
                ->where('workflowable_id', $lockedDocument->getKey())
                ->where('status', 'Pending')
                ->lockForUpdate()
                ->exists();

            if ($hasPendingInstance) {
                throw new WorkflowTransitionException('This document already has an approval in progress.');
            }

            $definition = $this->resolveDefinition();
            $steps = $definition->steps()->where('is_active', true)->get();

            if ($steps->isEmpty()) {
                throw new WorkflowTransitionException('The physical document workflow has no active steps.');
            }

            $snapshotSteps = [];

            foreach ($steps as $step) {
                $approverIds = WorkflowStepApprover::query()
                    ->where('workflow_definition_id', $definition->getKey())
                    ->where('workflow_step_id', $step->getKey())
                    ->where('initiator_user_id', $user->getKey())
                    ->where('is_active', true)
                    ->whereHas('user')
                    ->orderByDesc('is_primary')
                    ->orderBy('user_id')
                    ->pluck('user_id')
                    ->map(fn ($id) => (int) $id)
                    ->unique()
                    ->values()
                    ->all();

                if ($approverIds === []) {
                    throw new WorkflowTransitionException("No active approver is assigned for workflow step '{$step->step_name}' and this submitter.");
                }

                $snapshotSteps[] = [
                    'id' => (int) $step->getKey(),
                    'order' => (int) $step->step_order,
                    'name' => $step->step_name,
                    'approver_ids' => $approverIds,
                    'allow_approve' => (bool) $step->allow_approve,
                    'allow_reject' => (bool) $step->allow_reject,
                    'allow_revert' => (bool) $step->allow_revert,
                    'comment_required' => (bool) $step->comment_required,
                ];
            }

            $firstStep = $snapshotSteps[0];
            $previousRounds = WorkflowInstance::query()
                ->where('workflowable_type', PdtsDocument::class)
                ->where('workflowable_id', $lockedDocument->getKey())
                ->max('approval_round');

            $instance = WorkflowInstance::query()->create([
                'workflow_definition_id' => $definition->getKey(),
                'workflowable_id' => $lockedDocument->getKey(),
                'workflowable_type' => PdtsDocument::class,
                'current_step_id' => $firstStep['id'],
                'current_step_order' => $firstStep['order'],
                'status' => 'Pending',
                'approval_round' => ((int) $previousRounds) + 1,
                'workflow_snapshot' => [
                    'definition_name' => $definition->name,
                    'module' => self::MODULE,
                    'steps' => $snapshotSteps,
                ],
                'current_approver_ids' => $firstStep['approver_ids'],
                'initiated_by' => $user->getKey(),
                'initiated_at' => now(),
            ]);

            $this->recordAction($instance, $lockedDocument, $user, 'submitted', $firstStep, null);

            return $instance;
        });

        $this->notifyUsers(
            $instance->current_approver_ids ?? [],
            $document->fresh(),
            'submitted',
            (string) data_get($instance->workflow_snapshot, 'steps.0.name', 'Approval'),
            $user->name,
        );

        return $instance;
    }

    public function act(PdtsDocument $document, User $user, string $action, ?string $comment = null): WorkflowInstance
    {
        if (! in_array($action, ['approved', 'rejected', 'reverted'], true)) {
            throw new WorkflowTransitionException('That approval action is not supported.');
        }

        $transition = DB::transaction(function () use ($document, $user, $action, $comment): array {
            $lockedDocument = PdtsDocument::query()->whereKey($document->getKey())->lockForUpdate()->firstOrFail();
            $instance = WorkflowInstance::query()
                ->where('workflowable_type', PdtsDocument::class)
                ->where('workflowable_id', $lockedDocument->getKey())
                ->where('status', 'Pending')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if (! $instance) {
                throw new WorkflowTransitionException('This document has no approval awaiting action.');
            }

            $approverIds = array_map('intval', $instance->current_approver_ids ?? []);
            if (! in_array((int) $user->getKey(), $approverIds, true)) {
                throw new WorkflowTransitionException('You are not an assigned approver for the current workflow step.');
            }

            $snapshot = $instance->workflow_snapshot ?? [];
            $steps = $snapshot['steps'] ?? [];
            $currentIndex = collect($steps)->search(
                fn (array $step) => (int) $step['id'] === (int) $instance->current_step_id,
            );

            if ($currentIndex === false) {
                throw new WorkflowTransitionException('The current approval step is missing from the workflow snapshot.');
            }

            $currentStep = $steps[$currentIndex];
            $allowed = match ($action) {
                'approved' => $currentStep['allow_approve'],
                'rejected' => $currentStep['allow_reject'],
                'reverted' => $currentStep['allow_revert'],
            };

            if (! $allowed) {
                throw new WorkflowTransitionException('This action is not allowed at the current workflow step.');
            }

            $comment = trim((string) $comment);
            if ($currentStep['comment_required'] && $comment === '') {
                throw new WorkflowTransitionException('A comment is required for this approval action.');
            }

            $this->recordAction($instance, $lockedDocument, $user, $action, $currentStep, $comment ?: null);
            $recipientIds = [];
            $notificationEvent = $action;
            $notificationStep = $currentStep['name'];

            if ($action === 'approved') {
                $nextStep = $steps[$currentIndex + 1] ?? null;

                if ($nextStep) {
                    $instance->update([
                        'current_step_id' => $nextStep['id'],
                        'current_step_order' => $nextStep['order'],
                        'current_approver_ids' => $nextStep['approver_ids'],
                    ]);
                    $recipientIds = $nextStep['approver_ids'];
                    $notificationEvent = 'step_assigned';
                    $notificationStep = $nextStep['name'];
                } else {
                    $instance->update([
                        'status' => 'Approved',
                        'current_step_id' => null,
                        'current_step_order' => null,
                        'current_approver_ids' => [],
                        'completed_by' => $user->getKey(),
                        'completed_at' => now(),
                    ]);
                    $recipientIds = [(int) $instance->initiated_by];
                }
            } elseif ($action === 'rejected') {
                $instance->update([
                    'status' => 'Rejected',
                    'current_step_id' => null,
                    'current_step_order' => null,
                    'current_approver_ids' => [],
                    'completed_by' => $user->getKey(),
                    'completed_at' => now(),
                ]);
                $recipientIds = [(int) $instance->initiated_by];
            } elseif ($currentIndex > 0) {
                $previousStep = $steps[$currentIndex - 1];
                $instance->update([
                    'current_step_id' => $previousStep['id'],
                    'current_step_order' => $previousStep['order'],
                    'current_approver_ids' => $previousStep['approver_ids'],
                ]);
                $recipientIds = $previousStep['approver_ids'];
                $notificationEvent = 'returned_to_step';
                $notificationStep = $previousStep['name'];
            } else {
                $instance->update([
                    'status' => 'Returned',
                    'current_step_id' => null,
                    'current_step_order' => null,
                    'current_approver_ids' => [],
                ]);
                $recipientIds = [(int) $instance->initiated_by];
                $notificationEvent = 'returned';
            }

            return [
                'instance' => $instance->refresh(),
                'recipient_ids' => $recipientIds,
                'event' => $notificationEvent,
                'step_name' => $notificationStep,
                'comment' => $comment ?: null,
            ];
        });

        $this->notifyUsers(
            $transition['recipient_ids'],
            $document->fresh(),
            $transition['event'],
            $transition['step_name'],
            $user->name,
            $transition['comment'],
        );

        return $transition['instance'];
    }

    private function resolveDefinition(): WorkflowDefinition
    {
        $definitions = WorkflowDefinition::query()
            ->where('module', self::MODULE)
            ->where('is_active', true)
            ->get();

        $definition = $definitions->firstWhere('is_default', true);
        if (! $definition && $definitions->count() === 1) {
            $definition = $definitions->first();
        }

        if (! $definition) {
            throw new WorkflowTransitionException('Configure one active default workflow for the physical_document module before submitting documents.');
        }

        return $definition;
    }

    private function recordAction(
        WorkflowInstance $instance,
        PdtsDocument $document,
        User $user,
        string $action,
        array $step,
        ?string $comment,
    ): void {
        WorkflowAction::query()->create([
            'workflow_instance_id' => $instance->getKey(),
            'workflowable_id' => $document->getKey(),
            'workflowable_type' => PdtsDocument::class,
            'workflow_step_id' => $step['id'],
            'step_order' => $step['order'],
            'step_name' => $step['name'],
            'action' => $action,
            'comment' => $comment,
            'acted_by' => $user->getKey(),
            'acted_at' => now(),
        ]);
    }

    /** @param array<int, int|string> $userIds */
    private function notifyUsers(
        array $userIds,
        PdtsDocument $document,
        string $event,
        string $stepName,
        string $actorName,
        ?string $comment = null,
    ): void {
        try {
            $recipients = User::query()->whereKey($userIds)->get();

            foreach ($recipients as $recipient) {
                try {
                    $recipient->notify(new PdtsDocumentApprovalNotification(
                        $document,
                        $event,
                        $stepName,
                        $actorName,
                        $comment,
                    ));
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}