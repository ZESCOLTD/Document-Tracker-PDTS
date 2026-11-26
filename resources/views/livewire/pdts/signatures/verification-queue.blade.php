<div class="container-fluid py-4">
    <style>
        .sig-box { background: repeating-conic-gradient(#f3f5f5 0% 25%, #fff 0% 50%) 50% / 14px 14px; border: 1px solid #dfe6e6; border-radius: .5rem; padding: .5rem; text-align: center; min-height: 80px; display: flex; align-items: center; justify-content: center; }
        .sig-box img { max-width: 100%; max-height: 110px; }
    </style>

    <div class="d-flex flex-wrap justify-content-between align-items-start mb-3" style="gap: .5rem;">
        <div>
            <h1 class="h3 mb-1">Signature Verification</h1>
            <p class="text-muted mb-0">Compare each signature with the employee's specimen on file, then approve it or reject it with a reason. A signature can only be used to sign once it is approved. You cannot verify your own.</p>
        </div>
        <input type="search" class="form-control" style="max-width: 260px;" placeholder="Search name or man number" wire:model.live.debounce.300ms="search">
    </div>

    @if (session('success')) <div class="alert alert-success">{{ session('success') }}</div> @endif

    <ul class="nav nav-pills mb-3" style="gap: .25rem;">
        @foreach (['pending' => ['Waiting for verification', $pending->count()], 'in_use' => ['Signatures in use', $inUse->count()], 'decided' => ['Recent decisions', $decided->count()]] as $key => [$label, $count])
            <li class="nav-item"><a href="#" wire:click.prevent="$set('tab', '{{ $key }}')" class="nav-link {{ $tab === $key ? 'active' : '' }}">{{ $label }} <span class="badge {{ $tab === $key ? 'badge-light' : 'badge-secondary' }} ml-1">{{ $count }}</span></a></li>
        @endforeach
    </ul>

    @if ($tab === 'pending')
        @forelse ($pending as $image)
            @php
                $key = 'image-'.$image->id;
                $current = $currentByUser[$image->user_id] ?? null;
                $own = (int) $image->user_id === (int) auth()->id();
            @endphp
            <section class="bg-white border rounded p-3 mb-3" wire:key="pending-{{ $image->id }}" data-test="pending-signature">
                <div class="row">
                    <div class="col-lg-4 mb-2">
                        <div class="font-weight-bold">{{ $image->user?->name }}</div>
                        <div class="small text-muted">
                            Man No. {{ $image->user?->man_no ?? 'not recorded' }}<br>
                            {{ $image->user?->job_title }}@if ($image->user?->grade) · {{ $image->user->grade }}@endif<br>
                            {{ $image->user?->email }}<br>
                            Uploaded {{ $image->uploaded_at->format('j M Y, H:i') }} · {{ $image->width }}×{{ $image->height }} px
                        </div>
                        <div class="small mt-1">{{ $current ? 'Replaces the signature in use (shown for comparison).' : 'First signature.' }}</div>
                    </div>
                    <div class="col-lg-4 mb-2">
                        <div class="small text-muted mb-1">New signature</div>
                        <div class="sig-box"><img src="{{ route('pdts.signatures.verification.image', $image) }}" alt="New signature of {{ $image->user?->name }}"></div>
                        @if ($current)
                            <div class="small text-muted mt-2 mb-1">Signature in use now</div>
                            <div class="sig-box"><img src="{{ route('pdts.signatures.verification.image', $current) }}" alt="Current signature"></div>
                        @endif
                    </div>
                    <div class="col-lg-4">
                        @if ($own)
                            <div class="alert alert-light border small mb-0">This is your own signature. Another member of ICT must verify it.</div>
                        @else
                            <button type="button" class="btn btn-success btn-sm mb-2" wire:click="approve({{ $image->id }})" wire:confirm="Approve {{ $image->user?->name }}'s signature? It matches the specimen on file and they can sign with it.">
                                <i class="fas fa-check mr-1"></i>Approve
                            </button>
                            <textarea class="form-control form-control-sm mb-1" rows="2" maxlength="500" placeholder="Reason, if rejecting (e.g. does not match the specimen on file)" wire:model="reasons.{{ $key }}"></textarea>
                            <button type="button" class="btn btn-outline-danger btn-sm" wire:click="reject({{ $image->id }})"><i class="fas fa-times mr-1"></i>Reject</button>
                        @endif
                        @error($key) <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    </div>
                </div>
            </section>
        @empty
            <div class="bg-white border rounded text-center text-muted py-5">No signatures are waiting for verification.</div>
        @endforelse
    @elseif ($tab === 'in_use')
        <section class="bg-white border rounded">
            @forelse ($inUse as $image)
                @php
                    $key = 'user-'.$image->user_id;
                    $own = (int) $image->user_id === (int) auth()->id();
                @endphp
                <div class="d-flex flex-wrap align-items-center px-3 py-3 {{ ! $loop->last ? 'border-bottom' : '' }}" style="gap: 1rem;" wire:key="inuse-{{ $image->id }}">
                    <div class="sig-box" style="width: 180px; min-height: 60px;"><img src="{{ route('pdts.signatures.verification.image', $image) }}" alt="Signature of {{ $image->user?->name }}"></div>
                    <div class="flex-grow-1" style="min-width: 220px;">
                        <div class="font-weight-bold">{{ $image->user?->name }} <span class="text-muted font-weight-normal small">· {{ $image->user?->man_no }}</span></div>
                        <div class="small text-muted">Verified by {{ $image->reviewer?->name ?? 'ICT' }} {{ $image->reviewed_at?->format('j M Y') }}</div>
                        @if ($image->status === 'suspended')
                            <span class="badge badge-danger">Suspended</span> <span class="small text-muted">{{ $image->review_note }}</span>
                        @else
                            <span class="badge badge-success">Active</span>
                        @endif
                    </div>
                    @unless ($own)
                        <div style="min-width: 260px;">
                            @if ($image->status === 'suspended')
                                <button type="button" class="btn btn-outline-success btn-sm" wire:click="reinstate({{ $image->user_id }})" wire:confirm="Reinstate {{ $image->user?->name }}'s signature?">Reinstate</button>
                            @else
                                <div class="d-flex" style="gap: .375rem;">
                                    <input type="text" class="form-control form-control-sm" maxlength="500" placeholder="Reason for suspending" wire:model="reasons.{{ $key }}">
                                    <button type="button" class="btn btn-outline-danger btn-sm text-nowrap" wire:click="suspend({{ $image->user_id }})" wire:confirm="Suspend {{ $image->user?->name }}'s signature now? They will not be able to sign until it is reinstated. Documents already signed stay valid.">Suspend</button>
                                </div>
                            @endif
                            @error($key) <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                        </div>
                    @endunless
                </div>
            @empty
                <div class="text-center text-muted py-5">No signatures in use yet.</div>
            @endforelse
        </section>
    @else
        <section class="bg-white border rounded">
            @forelse ($decided as $image)
                <div class="d-flex flex-wrap justify-content-between px-3 py-2 {{ ! $loop->last ? 'border-bottom' : '' }}" style="gap: .5rem;">
                    <div>
                        <strong>{{ $image->user?->name }}</strong>
                        <span class="badge {{ ['active' => 'badge-success', 'rejected' => 'badge-danger', 'suspended' => 'badge-warning', 'retired' => 'badge-secondary'][$image->status] ?? 'badge-light' }} ml-1">{{ ucfirst($image->status) }}</span>
                        @if ($image->review_note) <span class="small text-muted">· {{ $image->review_note }}</span> @endif
                    </div>
                    <div class="small text-muted">{{ $image->reviewer?->name }} · {{ $image->reviewed_at?->format('j M Y H:i') }}</div>
                </div>
            @empty
                <div class="text-center text-muted py-5">No decisions yet.</div>
            @endforelse
        </section>
    @endif
</div>
