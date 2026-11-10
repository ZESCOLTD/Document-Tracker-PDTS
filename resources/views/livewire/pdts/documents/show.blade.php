<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start mb-4">
        <div>
            <a href="{{ route('pdts.documents.index') }}" class="text-muted">Registered Documents</a>
            <h1 class="h3 mt-2 mb-1">{{ $document->reference_number }}</h1>
            <span class="badge badge-secondary">{{ str($document->status)->replace('_', ' ')->title() }}</span>
        </div>
        <a href="{{ route('pdts.documents.index') }}" class="btn btn-outline-secondary">Back to documents</a>
    </div>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <div class="row">
        <div class="col-lg-8 mb-3">
            <section class="bg-white border rounded p-4 h-100">
                <h2 class="h5 mb-3">Document details</h2>
                <dl class="row mb-0">
                    <dt class="col-sm-4">Title</dt><dd class="col-sm-8">{{ $document->title }}</dd>
                    <dt class="col-sm-4">Subject</dt><dd class="col-sm-8">{{ $document->subject }}</dd>
                    <dt class="col-sm-4">Type</dt><dd class="col-sm-8">{{ $document->documentType->name }}</dd>
                    <dt class="col-sm-4">Originating department</dt><dd class="col-sm-8">{{ $document->originating_department }}</dd>
                    <dt class="col-sm-4">Originating office</dt><dd class="col-sm-8">{{ $document->originating_office }}</dd>
                    <dt class="col-sm-4">Registered by</dt><dd class="col-sm-8">{{ $document->registrar->name }}</dd>
                    <dt class="col-sm-4">Current custodian</dt><dd class="col-sm-8">{{ $document->currentCustodian->name }}</dd>
                    <dt class="col-sm-4">Registered</dt><dd class="col-sm-8">{{ $document->registered_at->format('d M Y H:i') }}</dd>
                    @if($document->description)
                        <dt class="col-sm-4">Description</dt><dd class="col-sm-8">{{ $document->description }}</dd>
                    @endif
                </dl>
            </section>
        </div>

        <div class="col-lg-4 mb-3">
            <section class="bg-white border rounded p-4 h-100">
                <h2 class="h5 mb-3">Scanned files</h2>
                @forelse($document->attachments as $attachment)
                    <div class="d-flex justify-content-between align-items-start border-bottom py-2">
                        <div class="mr-2">
                            <div class="font-weight-bold">{{ $attachment->original_filename }}</div>
                            <small class="text-muted">{{ number_format($attachment->file_size / 1024, 1) }} KB</small>
                        </div>
                        <a class="btn btn-sm btn-outline-primary" href="{{ route('pdts.documents.attachments.download', [$document, $attachment]) }}">Download</a>
                    </div>
                @empty
                    <p class="text-muted mb-0">No scanned file is attached.</p>
                @endforelse
            </section>
        </div>
    </div>

    <section class="bg-white border rounded p-4 mb-3">
        <div class="d-flex flex-wrap justify-content-between align-items-start mb-3">
            <div>
                <h2 class="h5 mb-1">Approval</h2>
                <p class="text-muted mb-0">Approval decisions are recorded separately from physical custody.</p>
            </div>
            @if($canSubmitForApproval)
                <button type="button" class="btn btn-primary" wire:click="submitForApproval" wire:loading.attr="disabled">
                    Submit for approval
                </button>
            @endif
        </div>

        @if(session('approval_success'))
            <div class="alert alert-success">{{ session('approval_success') }}</div>
        @endif
        @error('approval')
            <div class="alert alert-danger">{{ $message }}</div>
        @enderror

        @if($currentApproval)
            <div class="border rounded p-3 mb-3">
                <div class="d-flex flex-wrap justify-content-between">
                    <strong>{{ data_get($currentApproval->workflow_snapshot, 'definition_name', 'Approval workflow') }}</strong>
                    <span class="badge badge-warning">Pending</span>
                </div>
                <div class="mt-2">
                    Step {{ $currentApproval->current_step_order }}: {{ $approvalStep['name'] ?? 'Current step' }}
                </div>
                @if($canActOnApproval && $approvalStep)
                    <div class="mt-3">
                        <label for="approval-comment">Comment</label>
                        <textarea id="approval-comment" class="form-control" rows="3" wire:model="approvalComment"></textarea>
                        @if($approvalStep['comment_required'] ?? false)
                            <small class="text-muted">A comment is required for this step.</small>
                        @endif
                    </div>
                    <div class="d-flex flex-wrap mt-3" style="gap: .5rem">
                        @if($approvalStep['allow_approve'] ?? false)
                            <button type="button" class="btn btn-success" wire:click="actOnApproval('approved')" wire:loading.attr="disabled">Approve</button>
                        @endif
                        @if($approvalStep['allow_reject'] ?? false)
                            <button type="button" class="btn btn-danger" wire:click="actOnApproval('rejected')" wire:loading.attr="disabled">Reject</button>
                        @endif
                        @if($approvalStep['allow_revert'] ?? false)
                            <button type="button" class="btn btn-outline-secondary" wire:click="actOnApproval('reverted')" wire:loading.attr="disabled">Return for changes</button>
                        @endif
                    </div>
                @else
                    <p class="text-muted mb-0 mt-2">Waiting for the assigned approver to act.</p>
                @endif
            </div>
        @elseif($approvalInstances->isEmpty())
            <p class="text-muted mb-0">This document has not been submitted for approval.</p>
        @else
            <p class="text-muted mb-3">No approval is currently in progress.</p>
        @endif

        @if($approvalInstances->isNotEmpty())
            <h3 class="h6 mt-4">Approval history</h3>
            @foreach($approvalInstances as $instance)
                <div class="border-top py-3">
                    <div class="d-flex flex-wrap justify-content-between">
                        <strong>{{ data_get($instance->workflow_snapshot, 'definition_name', $instance->workflowDefinition?->name ?? 'Approval workflow') }} · Round {{ $instance->approval_round }}</strong>
                        <span class="badge badge-secondary">{{ $instance->status }}</span>
                    </div>
                    <ol class="mt-2 mb-0">
                        @foreach($instance->actions->sortBy('acted_at') as $action)
                            <li class="mb-1">
                                <strong>{{ str($action->action)->replace('_', ' ')->title() }}</strong>
                                @if($action->step_name) at {{ $action->step_name }} @endif
                                by {{ $action->actor?->name ?? 'Former user' }}
                                <small class="text-muted">{{ $action->acted_at?->format('d M Y H:i:s') }}</small>
                                @if($action->comment)
                                    <div>{{ $action->comment }}</div>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                </div>
            @endforeach
        @endif
    </section>

    <section class="bg-white border rounded p-4">
        <h2 class="h5 mb-3">Registration audit</h2>
        <ol class="mb-0">
            @foreach($document->auditLogs as $event)
                <li class="mb-2">
                    <strong>{{ str($event->action)->replace('_', ' ')->title() }}</strong>
                    <span>by {{ $event->actor->name }}</span>
                    <small class="text-muted">{{ $event->occurred_at->format('d M Y H:i:s') }}</small>
                </li>
            @endforeach
        </ol>
    </section>
</div>