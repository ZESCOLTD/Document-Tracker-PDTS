<?php

use App\Enums\DocumentStatus;
use App\Exceptions\WorkflowTransitionException;
use App\Livewire\Pdts\Approvals\Inbox as ApprovalInbox;
use App\Livewire\Pdts\Documents\Show as PdtsDocumentShow;
use App\Models\PdtsDocument;
use App\Models\User;
use App\Models\WorkflowAction;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowStep;
use App\Models\WorkflowStepApprover;
use App\Notifications\PdtsDocumentApprovalNotification;
use App\Services\Workflows\PdtsDocumentApprovalService;
use Livewire\Livewire;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();

    $this->initiator = User::factory()->create();
    $this->firstApprover = User::factory()->create();
    $this->secondApprover = User::factory()->create();
    $this->document = PdtsDocument::factory()->create([
        'registered_by' => $this->initiator->id,
        'current_custodian_id' => $this->initiator->id,
        'status' => DocumentStatus::Received->value,
    ]);

    $this->definition = WorkflowDefinition::query()->create([
        'name' => 'PDTS document approval',
        'module' => 'physical_document',
        'is_active' => true,
        'is_default' => true,
    ]);

    $this->firstStep = createStep(1, 'Supervisor review');
    $this->secondStep = createStep(2, 'Director approval');
    assignApprover($this->firstStep, $this->firstApprover);
    assignApprover($this->secondStep, $this->secondApprover);
});

test('submitted document follows configured sequential steps without changing custody', function () {
    $service = app(PdtsDocumentApprovalService::class);
    $instance = $service->submit($this->document, $this->initiator);

    Notification::assertSentTo($this->firstApprover, PdtsDocumentApprovalNotification::class, fn ($notification) => $notification->event === 'submitted');

    expect($instance->status)->toBe('Pending')
        ->and($instance->current_step_id)->toBe($this->firstStep->id)
        ->and($instance->current_approver_ids)->toBe([$this->firstApprover->id]);

    $service->act($this->document, $this->firstApprover, 'approved', 'Reviewed');
    $instance->refresh();

    Notification::assertSentTo($this->secondApprover, PdtsDocumentApprovalNotification::class, fn ($notification) => $notification->event === 'step_assigned');

    expect($instance->status)->toBe('Pending')
        ->and($instance->current_step_id)->toBe($this->secondStep->id)
        ->and($instance->current_approver_ids)->toBe([$this->secondApprover->id]);

    $service->act($this->document, $this->secondApprover, 'approved', 'Approved');
    $instance->refresh();

    Notification::assertSentTo($this->initiator, PdtsDocumentApprovalNotification::class, fn ($notification) => $notification->event === 'approved');

    expect($instance->status)->toBe('Approved')
        ->and($instance->completed_by)->toBe($this->secondApprover->id)
        ->and($this->document->fresh()->current_custodian_id)->toBe($this->initiator->id)
        ->and(WorkflowAction::query()->where('workflow_instance_id', $instance->id)->pluck('action')->all())
        ->toBe(['submitted', 'approved', 'approved']);
});

test('only an assigned approver can act on the current step', function () {
    $service = app(PdtsDocumentApprovalService::class);
    $service->submit($this->document, $this->initiator);
    $unassigned = User::factory()->create();

    expect(fn () => $service->act($this->document, $unassigned, 'approved', 'Not assigned'))
        ->toThrow(WorkflowTransitionException::class, 'not an assigned approver');
});

test('submission requires every active step to have an initiator-specific approver', function () {
    WorkflowStepApprover::query()->where('workflow_step_id', $this->secondStep->id)->delete();

    expect(fn () => app(PdtsDocumentApprovalService::class)->submit($this->document, $this->initiator))
        ->toThrow(WorkflowTransitionException::class, 'No active approver');
});

test('PDTS document screen submits and processes assigned approval steps', function () {
    Livewire::actingAs($this->initiator)
        ->test(PdtsDocumentShow::class, ['document' => $this->document])
        ->assertSee('Submit for approval')
        ->call('submitForApproval')
        ->assertSee('Supervisor review');

    Livewire::actingAs($this->firstApprover)
        ->test(ApprovalInbox::class)
        ->assertSee($this->document->reference_number)
        ->assertSee('Supervisor review');

    Livewire::actingAs($this->firstApprover)
        ->test(PdtsDocumentShow::class, ['document' => $this->document])
        ->assertSee('Approve')
        ->set('approvalComment', 'Reviewed')
        ->call('actOnApproval', 'approved')
        ->assertSee('Director approval');
});

test('approval notification channels respect user preferences', function () {
    $notification = new PdtsDocumentApprovalNotification(
        $this->document,
        'submitted',
        'Supervisor review',
        $this->initiator->name,
    );

    expect(config('mail.from.name'))->toBe('ZESCO PDTS')
        ->and($notification->toMail($this->firstApprover)->subject)->toContain('PDTS');

    expect($notification->via($this->firstApprover))->toBe(['database', 'mail']);

    $this->firstApprover->forceFill(['notify_email' => false])->save();
    expect($notification->via($this->firstApprover))->toBe(['database']);

    $this->firstApprover->forceFill(['notify_categories' => ['approvals' => false]])->save();
    expect($notification->via($this->firstApprover))->toBe([]);
});

function createStep(int $order, string $name): WorkflowStep
{
    return WorkflowStep::query()->create([
        'workflow_definition_id' => test()->definition->id,
        'step_order' => $order,
        'step_name' => $name,
        'approver_type' => 'user',
        'allow_approve' => true,
        'allow_reject' => true,
        'allow_revert' => true,
        'comment_required' => true,
        'is_active' => true,
    ]);
}

function assignApprover(WorkflowStep $step, User $approver): void
{
    WorkflowStepApprover::query()->create([
        'workflow_definition_id' => test()->definition->id,
        'workflow_step_id' => $step->id,
        'initiator_user_id' => test()->initiator->id,
        'user_id' => $approver->id,
        'is_primary' => true,
        'is_active' => true,
    ]);
}