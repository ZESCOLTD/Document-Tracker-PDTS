<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">Approval Inbox</h1>
            <p class="text-muted mb-0">Documents awaiting your decision.</p>
        </div>
    </div>

    @forelse($approvals as $approval)
        @if($approval->workflowable)
            @php
                $currentStep = collect(data_get($approval->workflow_snapshot, 'steps', []))
                    ->firstWhere('order', (int) $approval->current_step_order);
            @endphp
            <section class="bg-white border rounded p-3 mb-3">
                <div class="d-flex flex-wrap justify-content-between align-items-start">
                    <div>
                        <a class="font-weight-bold" href="{{ route('pdts.documents.show', $approval->workflowable) }}">
                            {{ $approval->workflowable->reference_number }} · {{ $approval->workflowable->title }}
                        </a>
                        <div class="text-muted">{{ $approval->workflowable->subject }}</div>
                    </div>
                    <span class="badge badge-warning">Step {{ $approval->current_step_order }} · Round {{ $approval->approval_round }}</span>
                </div>
                <div class="mt-2">{{ $currentStep['name'] ?? 'Pending approval' }}</div>
                <small class="text-muted">Submitted {{ $approval->initiated_at?->diffForHumans() }}</small>
            </section>
        @endif
    @empty
        <div class="alert alert-light border">You have no documents awaiting your approval.</div>
    @endforelse

    {{ $approvals->links() }}
</div>