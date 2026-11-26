<div class="container-fluid py-4">
    <div class="mb-3">
        <h1 class="h3 mb-1">Signatures</h1>
        <p class="text-muted mb-0">Documents prepared on someone's behalf: those waiting for your signature, and those you prepared for others.</p>
    </div>

    <ul class="nav nav-pills flex-wrap mb-3" style="gap: .25rem;">
        @foreach (\App\Livewire\Pdts\Signatures\SignatureRequests::TABS as $key => [$label, $icon])
            @continue($counts[$key] === 0 && ! in_array($key, ['waiting', 'sent'], true) && $tab !== $key)
            <li class="nav-item">
                <a href="#" wire:click.prevent="$set('tab', '{{ $key }}')" class="nav-link {{ $tab === $key ? 'active' : '' }}">
                    <i class="fas {{ $icon }} mr-1"></i>{{ $label }}
                    <span class="badge {{ $tab === $key ? 'badge-light' : 'badge-secondary' }} ml-1">{{ $counts[$key] }}</span>
                </a>
            </li>
        @endforeach
    </ul>

    <section class="bg-white border rounded">
        @forelse ($documents as $document)
            <a href="{{ route('pdts.documents.show', $document) }}" class="d-flex flex-wrap justify-content-between align-items-center px-3 py-3 text-reset {{ ! $loop->last ? 'border-bottom' : '' }}" style="gap: .5rem; text-decoration: none;" data-test="signature-row">
                <div style="min-width: 0;">
                    <div class="font-weight-bold">{{ $document->reference_number }} · {{ $document->subject }}</div>
                    <div class="small text-muted">
                        {{ $document->documentType?->name }}
                        @if (in_array($tab, ['waiting', 'returned_by_me', 'signed_by_me'], true))
                            · Prepared by {{ $document->registrar?->name }}
                        @else
                            · For {{ $document->signatory?->name }}
                        @endif
                    </div>
                    @if (in_array($tab, ['returned_by_me', 'returned_to_me'], true) && $document->signature_note)
                        <div class="small mt-1" style="color: #8a6100;"><i class="fas fa-undo mr-1"></i>"{{ \Illuminate\Support\Str::limit($document->signature_note, 160) }}"</div>
                    @endif
                </div>
                <div class="small text-muted text-nowrap">
                    @if ($document->electronicSignature)
                        Signed {{ $document->electronicSignature->signed_at->format('j M Y H:i') }}
                    @else
                        {{ $document->updated_at?->diffForHumans() }}
                    @endif
                    <i class="fas fa-chevron-right ml-2"></i>
                </div>
            </a>
        @empty
            <div class="text-center text-muted py-5">
                <i class="fas fa-inbox d-block mb-2" style="font-size: 1.75rem; opacity: .5;"></i>
                Nothing here.
            </div>
        @endforelse
    </section>
</div>
