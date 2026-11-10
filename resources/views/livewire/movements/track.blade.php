<div style="padding: 0 .75rem 90px;">
    <style>
        .track-steps { display: flex; align-items: center; list-style: none; margin: 0 0 .25rem; padding: 0; }
        .track-steps li { align-items: center; display: flex; }
        .track-steps li + li::before { background: #ced4da; content: ''; display: block; height: 2px; width: 14px; }
        .track-steps li.done + li.done::before { background: {{ config('theme.color_success') }}; }
        .track-step { border: 2px solid #ced4da; border-radius: 50%; display: block; height: 12px; width: 12px; }
        .track-steps li.done .track-step { background: {{ config('theme.color_success') }}; border-color: {{ config('theme.color_success') }}; }
        .track-steps li.current .track-step { box-shadow: 0 0 0 3px rgba(0, 112, 60, .2); }
    </style>

    <div class="d-flex justify-content-between align-items-start flex-wrap mb-3">
        <div class="mb-2 mr-3">
            <h3 class="font-weight-bold mb-1">Track My Documents</h3>
            <p class="text-muted mb-0">Everything you registered, how far it has got and where it is now.</p>
        </div>

        @include('livewire.movements.partials.search')
    </div>

    <ul class="nav nav-tabs mb-3" role="tablist">
        @foreach ($tabs as $key => $label)
            <li class="nav-item">
                <a href="#" wire:click.prevent="setTab('{{ $key }}')" role="tab"
                   class="nav-link {{ $tab === $key ? 'active font-weight-bold' : '' }}"
                   aria-selected="{{ $tab === $key ? 'true' : 'false' }}">
                    {{ $label }} <span class="badge badge-light ml-1" data-tab-count="{{ $key }}">{{ $counts[$key] }}</span>
                </a>
            </li>
        @endforeach
    </ul>

    <div class="card elevation-2 border-0">
        <div class="card-body p-0 table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Reference</th>
                        <th>Subject</th>
                        <th>Progress</th>
                        <th>Where it is now</th>
                        <th>Since</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($documents as $document)
                        @php
                            $pending = $document->pendingMovement;
                            $receipt = $document->latestReceipt;
                            $status = $document->statusEnum();
                            $step = match ($status) {
                                \App\Enums\DocumentStatus::Registered => 1,
                                \App\Enums\DocumentStatus::AwaitingReceipt => 2,
                                \App\Enums\DocumentStatus::Received => 3,
                                \App\Enums\DocumentStatus::Completed => 4,
                                default => 0,
                            };
                            $since = $pending?->forwarded_at ?? $receipt?->acknowledged_at ?? $document->registered_at;
                            $overdue = $pending && $pending->forwarded_at->lte($cutoff);
                            $officer = $pending?->recipient ?? $document->currentCustodian;
                        @endphp
                        <tr wire:key="track-{{ $document->id }}">
                            <td class="text-nowrap">{{ $document->reference_number }}</td>
                            <td>
                                {{ $document->title }}
                                <div class="small text-muted">{{ $document->documentType?->name }}</div>
                            </td>
                            <td class="text-nowrap" data-progress-step="{{ $step }}">
                                <ol class="track-steps" aria-label="Step {{ $step }} of 4">
                                    @foreach (['Registered', 'Sent', 'Received', 'Completed'] as $index => $stepLabel)
                                        <li class="{{ $index < $step ? 'done' : '' }} {{ $index + 1 === $step ? 'current' : '' }}" title="{{ $stepLabel }}">
                                            <span class="track-step"></span>
                                        </li>
                                    @endforeach
                                </ol>
                                <span class="badge badge-light border">{{ $status->plainLabel() }}</span>
                            </td>
                            <td>
                                <span data-where>
                                    @if ($pending)
                                        On its way to {{ $pending->recipient?->name }}, waiting for receipt
                                    @elseif ($status === \App\Enums\DocumentStatus::Received)
                                        Received by {{ $receipt?->recipient?->name }} on {{ $receipt?->acknowledged_at->format('d M Y') }}
                                    @elseif ($status === \App\Enums\DocumentStatus::Registered)
                                        Not sent yet
                                    @else
                                        {{ $status->plainLabel() }}
                                    @endif
                                </span>
                                @if ($status !== \App\Enums\DocumentStatus::Registered && $officer?->positionAndOffice())
                                    <div class="small text-muted">{{ $officer->positionAndOffice() }}</div>
                                @endif
                            </td>
                            <td class="text-nowrap" title="{{ $since->format('d M Y H:i') }}">
                                {{ $since->diffForHumans() }}
                                @if ($overdue)
                                    <span class="badge badge-danger ml-1" title="Not received within {{ $overdueAfterDays }} days">Overdue</span>
                                @endif
                            </td>
                            <td class="text-right">
                                <a href="{{ route('movements.history', $document) }}" class="btn btn-sm btn-outline-secondary">Track</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">
                                @if ($search !== '')
                                    No documents match "{{ $search }}".
                                @elseif ($tab === 'completed')
                                    None of your documents have been completed yet.
                                @else
                                    You have not registered any documents.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $documents->links() }}</div>
</div>
