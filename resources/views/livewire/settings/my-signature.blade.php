<div class="container-fluid py-3">
    <style>
        .sig-step { display: inline-flex; align-items: center; justify-content: center; width: 1.6rem; height: 1.6rem; border-radius: 50%; font-size: .8125rem; font-weight: 700; margin-right: .5rem; }
        .sig-step.is-done { background: #e6f4ea; color: #1e7b3c; }
        .sig-step.is-todo { background: #fff3cd; color: #8a6100; }
        .sig-preview { background: repeating-conic-gradient(#f3f5f5 0% 25%, #fff 0% 50%) 50% / 16px 16px; border: 1px dashed #c9d3d3; border-radius: .5rem; padding: 1rem; text-align: center; }
        .sig-preview img { max-width: 100%; max-height: 120px; }
        .sig-preview.is-new { border-color: #075B35; }
        .sig-choice { display: flex; gap: .5rem; }
        .sig-choice label { flex: 1; margin: 0; border: 1.5px solid #dfe6e6; border-radius: .5rem; padding: .5rem .75rem; cursor: pointer; font-size: .875rem; }
        .sig-choice label.is-on { border-color: #075B35; background: #f3faf6; }
        .sig-choice input { margin-right: .375rem; }
    </style>

    <div class="mb-3">
        <h3 class="font-weight-bold mb-1">My Signature</h3>
        <p class="text-muted mb-0">Sign internal documents in PDTS instead of on paper. Your signature is only put on a document when you enter your signature PIN or password, and only you can use it.</p>
    </div>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    @if ($ready)
        <div class="alert alert-success"><i class="fas fa-check-circle mr-1"></i>You are ready to sign. Open a draft you created from a template and choose <strong>Sign electronically</strong>.</div>
    @elseif ($currentImage?->status === 'suspended')
        <div class="alert alert-danger"><i class="fas fa-ban mr-1"></i>Your signature has been <strong>suspended by ICT</strong>{{ $currentImage->review_note ? ': '.$currentImage->review_note : '' }}. You cannot sign until ICT reinstates it.</div>
    @elseif ($pendingImage && ! $currentImage)
        <div class="alert alert-info"><i class="fas fa-hourglass-half mr-1"></i>Your signature is <strong>waiting for ICT to verify it</strong>. You will be told by email when it is approved; you can sign after that.</div>
    @endif
    @unless ($signingAvailable)
        <div class="alert alert-warning small"><i class="fas fa-exclamation-triangle mr-1"></i>Signing is not switched on on this server yet (LibreOffice is not installed). You can still set up your signature now.</div>
    @endunless

    <div class="row">
        {{-- 1. The signature picture --}}
        <div class="col-lg-6 mb-3">
            <div class="card h-100">
                @php
                    $approved = $currentImage?->status === 'active';
                @endphp
                <div class="card-header"><span class="sig-step {{ $approved ? 'is-done' : 'is-todo' }}">{!! $approved ? '<i class="fas fa-check"></i>' : '1' !!}</span><strong>Your handwritten signature</strong></div>
                <div class="card-body">
                    @if ($currentImage)
                        <div class="small text-muted mb-1">
                            Signature in use
                            @if ($currentImage->status === 'suspended')
                                <span class="badge badge-danger ml-1">Suspended</span>
                            @else
                                <span class="badge badge-success ml-1">Verified</span>
                            @endif
                        </div>
                        <div class="sig-preview mb-1"><img src="{{ route('settings.signature.image', ['v' => $currentImage->id]) }}" alt="Your signature"></div>
                        <div class="small text-muted mb-3">Verified by {{ $currentImage->reviewer?->name ?? 'ICT' }}{{ $currentImage->reviewed_at ? ' on '.$currentImage->reviewed_at->format('j M Y') : '' }}.</div>
                    @endif

                    @if ($pendingImage)
                        <div class="small mb-1" style="color: #1f5fbf;"><i class="fas fa-hourglass-half mr-1"></i>{{ $currentImage ? 'New signature waiting for ICT to verify it. The one above stays in use until then.' : 'Waiting for ICT to verify it.' }}</div>
                        <div class="sig-preview mb-3" style="border-color: #9ec1f0;"><img src="{{ route('settings.signature.image', ['pending' => 1, 'v' => $pendingImage->id]) }}" alt="Signature waiting for verification"></div>
                    @endif

                    @if ($rejectedImage)
                        <div class="alert alert-warning small py-2"><i class="fas fa-times-circle mr-1"></i><strong>ICT rejected your last upload</strong>{{ $rejectedImage->review_note ? ': '.$rejectedImage->review_note : '' }}. Upload a new picture below.</div>
                    @endif

                    <form wire:submit.prevent="saveImage" autocomplete="off">
                        <label for="signature-image" class="mb-1">{{ $hasImage ? 'Replace it with a new picture' : 'Upload a picture of your signature' }}</label>
                        <input id="signature-image" type="file" class="form-control-file mb-2" wire:model="image" accept=".png,.jpg,.jpeg">
                        <div wire:loading wire:target="image" class="small text-muted mb-2">Loading the picture…</div>
                        @error('image') <div class="text-danger small mb-2">{{ $message }}</div> @enderror

                        @if ($previewUrl)
                            <div class="small font-weight-bold mb-1" style="color: #075B35;">{{ $hasImage ? 'New signature, not saved yet' : 'Preview, not saved yet' }} <span class="font-weight-normal text-muted">· exactly as it will be saved</span></div>
                            <div class="sig-preview is-new mb-3"><img src="{{ $previewUrl }}" alt="New signature preview"></div>
                        @else
                            <ul class="small text-muted pl-3 mb-3">
                                <li>Sign on plain white paper in dark ink, then scan or photograph it.</li>
                                <li>Crop it close around the signature: wider than it is high, ideally about 360×140 pixels. A PNG with a transparent background looks best.</li>
                                <li>PNG or JPG, up to 2 MB.</li>
                                <li>ICT checks it against the specimen signature on your file before you can sign with it.</li>
                            </ul>
                        @endif

                        <div class="form-group">
                            <label for="image-confirmation" class="mb-1">{{ $hasSecret ? 'Your current '.$secretName : 'Your login password' }} <span class="text-muted small">(to confirm it is you)</span></label>
                            <input id="image-confirmation" type="password" class="form-control" style="max-width: 18rem;" wire:model="imageConfirmation" autocomplete="off" @if ($hasSecret && $user->signature_secret_type === 'pin') inputmode="numeric" @endif>
                            @error('imageConfirmation') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                        </div>

                        <div class="d-flex" style="gap: .5rem;">
                            <button type="submit" class="btn btn-primary btn-sm" wire:loading.attr="disabled" wire:target="image,saveImage">
                                <i class="fas fa-upload mr-1"></i>{{ $hasImage ? 'Replace signature' : 'Save signature' }}
                            </button>
                            @if ($hasImage)
                                <button type="button" class="btn btn-outline-danger btn-sm" wire:click="removeImage" wire:confirm="Remove your signature? You will not be able to sign in PDTS until you upload one again. Documents you already signed are not affected.">Remove</button>
                            @endif
                        </div>
                    </form>
                </div>
            </div>
        </div>

        {{-- 2. PIN or signature password --}}
        <div class="col-lg-6 mb-3">
            <div class="card h-100">
                <div class="card-header"><span class="sig-step {{ $hasSecret ? 'is-done' : 'is-todo' }}">{!! $hasSecret ? '<i class="fas fa-check"></i>' : '2' !!}</span><strong>Signature PIN or password</strong></div>
                <div class="card-body">
                    <p class="small text-muted">
                        @if ($hasSecret)
                            You sign with a <strong>{{ $secretName }}</strong>, set {{ $user->signature_pin_updated_at?->format('j M Y') }}. You can change it, or switch between a PIN and a password.
                        @else
                            You enter this each time you sign. It is different from your login password, and PDTS never stores it in a readable form.
                        @endif
                    </p>

                    <form wire:submit.prevent="saveSecret" autocomplete="off">
                        <div class="sig-choice mb-3" role="radiogroup" aria-label="Sign with">
                            <label class="{{ $secretType === 'pin' ? 'is-on' : '' }}"><input type="radio" value="pin" wire:model.live="secretType"><strong>PIN</strong><span class="d-block small text-muted">4–8 digits</span></label>
                            <label class="{{ $secretType === 'password' ? 'is-on' : '' }}"><input type="radio" value="password" wire:model.live="secretType"><strong>Password</strong><span class="d-block small text-muted">8+ characters, letters and numbers</span></label>
                        </div>

                        @if ($hasSecret && ! $forgotten)
                            <div class="form-group">
                                <label for="current-secret" class="d-flex justify-content-between">
                                    <span>Current {{ $secretName }}</span>
                                    <a href="#" class="small" wire:click.prevent="$set('forgotten', true)">Forgot it?</a>
                                </label>
                                <input id="current-secret" type="password" class="form-control" wire:model="currentSecret" autocomplete="off" @if ($user->signature_secret_type === 'pin') inputmode="numeric" @endif>
                                @error('currentSecret') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                            </div>
                        @elseif ($hasSecret && $forgotten)
                            <div class="alert alert-light border small py-2">
                                <i class="fas fa-info-circle mr-1"></i>Set a new one with your login password below. An email will tell you it was reset. If you cannot, ask an administrator to reset it.
                                <a href="#" class="d-block mt-1" wire:click.prevent="$set('forgotten', false)">I remember it</a>
                            </div>
                        @endif

                        <div class="form-row">
                            <div class="form-group col-6">
                                <label for="new-secret">{{ $hasSecret ? 'New ' : '' }}{{ $secretType === 'pin' ? 'PIN' : 'password' }}</label>
                                <input id="new-secret" type="password" class="form-control" wire:model="secret" autocomplete="new-password" maxlength="{{ $secretType === 'pin' ? 8 : 64 }}" @if ($secretType === 'pin') inputmode="numeric" @endif>
                            </div>
                            <div class="form-group col-6">
                                <label for="new-secret-confirm">Repeat it</label>
                                <input id="new-secret-confirm" type="password" class="form-control" wire:model="secret_confirmation" autocomplete="new-password" maxlength="{{ $secretType === 'pin' ? 8 : 64 }}" @if ($secretType === 'pin') inputmode="numeric" @endif>
                            </div>
                        </div>
                        @error('secret') <div class="text-danger small mb-2">{{ $message }}</div> @enderror

                        <div class="form-group">
                            <label for="login-password">Your login password <span class="text-muted small">(to confirm it is you)</span></label>
                            <input id="login-password" type="password" class="form-control" wire:model="password" autocomplete="current-password">
                            @error('password') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                        </div>

                        <button type="submit" class="btn btn-primary btn-sm" wire:loading.attr="disabled" wire:target="saveSecret">
                            <i class="fas fa-key mr-1"></i>{{ $hasSecret ? ($forgotten ? 'Reset' : 'Change') : 'Save' }} {{ $secretType === 'pin' ? 'PIN' : 'password' }}
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- Prepare on behalf of: for Directors, Senior Managers and Managers --}}
    @if ($canHavePreparers || $myPreparers->isNotEmpty())
    <div class="card mb-3" data-test="preparers">
        <div class="card-header"><strong>People who can prepare documents for me</strong></div>
        <div class="card-body">
            <p class="small text-muted">A secretary or assistant you authorise here can prepare memos and letters for you. You review each one and sign it with your own signature, or return it with a comment. They can never sign for you, and nobody gets this permission automatically.</p>

            @forelse ($myPreparers as $delegation)
                <div class="d-flex flex-wrap justify-content-between align-items-center py-2 {{ ! $loop->last ? 'border-bottom' : '' }}" style="gap: .5rem;">
                    <div>
                        <div class="font-weight-bold">{{ $delegation->delegate?->name }}@if ($delegation->delegate?->job_title)<span class="text-muted font-weight-normal"> · {{ $delegation->delegate->job_title }}</span>@endif</div>
                        <div class="small text-muted">
                            {{ $delegation->starts_at->isFuture() ? 'From '.$delegation->starts_at->format('j M Y') : 'Since '.$delegation->starts_at->format('j M Y') }}
                            · {{ $delegation->expires_at ? 'until '.$delegation->expires_at->format('j M Y') : 'no end date' }}
                        </div>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-danger" wire:click="revokePreparer({{ $delegation->id }})" wire:confirm="End {{ $delegation->delegate?->name }}'s authorisation now? Drafts they prepared stay with you to sign or discard.">End now</button>
                </div>
            @empty
                <div class="small text-muted mb-2">Nobody yet.</div>
            @endforelse

            @unless ($canHavePreparers)
                <div class="small text-muted border-top pt-2 mt-2"><i class="fas fa-info-circle mr-1"></i>Only Directors, Senior Managers and Managers can authorise someone. These authorisations no longer work; you can end them.</div>
            @else
            <form wire:submit.prevent="grantPreparer" class="border-top pt-3 mt-2" autocomplete="off">
                <div class="form-row">
                    <div class="form-group col-md-5">
                        <label for="preparer-search">Person</label>
                        @if ($chosenPreparer)
                            {{-- The person chosen, with a way to change it --}}
                            <div class="d-flex justify-content-between align-items-center border rounded px-3 py-2 bg-light" data-test="chosen-preparer">
                                <span>
                                    <span class="font-weight-bold">{{ $chosenPreparer->name }}</span>
                                    <span class="small text-muted d-block">{{ collect([$chosenPreparer->man_no, $chosenPreparer->job_title])->filter()->implode(' · ') }}</span>
                                </span>
                                <button type="button" class="btn btn-link btn-sm p-0" wire:click="clearPreparer">Change</button>
                            </div>
                        @else
                            <input id="preparer-search" type="search" class="form-control" placeholder="Type a name or man number" wire:model.live.debounce.300ms="preparerSearch">
                            @if ($preparerMatches->isNotEmpty())
                                <div class="list-group mt-1 shadow-sm" role="listbox" aria-label="Matching people">
                                    @foreach ($preparerMatches as $match)
                                        <button type="button" class="list-group-item list-group-item-action py-2" role="option" wire:key="preparer-match-{{ $match->id }}" wire:click="choosePreparer({{ $match->id }})">
                                            <span class="font-weight-bold">{{ $match->name }}</span>
                                            <span class="small text-muted d-block">{{ collect([$match->man_no, $match->job_title])->filter()->implode(' · ') }}</span>
                                        </button>
                                    @endforeach
                                </div>
                            @elseif (mb_strlen(trim($preparerSearch)) >= 2)
                                <div class="small text-muted mt-1">Nobody found with that name or man number.</div>
                            @endif
                        @endif
                        @error('preparerId') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    </div>
                    <div class="form-group col-md-3">
                        <label for="preparer-starts">From</label>
                        <input id="preparer-starts" type="date" class="form-control" wire:model="preparerStarts">
                        @error('preparerStarts') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    </div>
                    <div class="form-group col-md-4">
                        <label for="preparer-ends">Until</label>
                        <input id="preparer-ends" type="date" class="form-control" wire:model="preparerEnds" @disabled($preparerIndefinite)>
                        <div class="custom-control custom-checkbox mt-1">
                            <input type="checkbox" class="custom-control-input" id="preparer-indefinite" wire:model.live="preparerIndefinite">
                            <label class="custom-control-label small" for="preparer-indefinite">No end date (until I end it)</label>
                        </div>
                        @error('preparerEnds') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-sm" wire:loading.attr="disabled" wire:target="grantPreparer"><i class="fas fa-user-check mr-1"></i>Authorise</button>
            </form>
            @endunless
        </div>
    </div>
    @endif

    @if ($preparingFor->isNotEmpty())
        <div class="card mb-3" data-test="preparing-for">
            <div class="card-header"><strong>Officers I prepare documents for</strong></div>
            <div class="card-body p-0">
                @foreach ($preparingFor as $delegation)
                    <div class="d-flex flex-wrap justify-content-between align-items-center px-3 py-2 {{ ! $loop->last ? 'border-bottom' : '' }}" style="gap: .5rem;">
                        <div>
                            <div class="font-weight-bold">{{ $delegation->principal?->name }}</div>
                            <div class="small text-muted">{{ $delegation->expires_at ? 'Until '.$delegation->expires_at->format('j M Y') : 'No end date' }}</div>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="revokePreparer({{ $delegation->id }})" wire:confirm="Stop preparing documents for {{ $delegation->principal?->name }}?">Give up</button>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- History --}}
    <div class="card mb-3">
        <div class="card-header"><strong>Signature history</strong> <span class="small text-muted">(latest 10)</span></div>
        <div class="card-body p-0">
            @forelse ($events as $event)
                <div class="d-flex justify-content-between flex-wrap px-3 py-2 {{ ! $loop->last ? 'border-bottom' : '' }}" style="gap: .5rem;">
                    <div>
                        <div class="font-weight-bold small">{{ $event->label() }}</div>
                        <div class="small text-muted">
                            @if ((int) $event->actor_id !== (int) $user->id) By {{ $event->actor?->name ?? 'an administrator' }} · @endif
                            @if ($event->ip_address) From {{ $event->ip_address }} · @endif
                            @if (! empty($event->details['image_sha256'])) Image fingerprint {{ \Illuminate\Support\Str::limit($event->details['image_sha256'], 12, '…') }} @endif
                        </div>
                    </div>
                    <div class="small text-muted text-nowrap">{{ $event->occurred_at->format('j M Y, H:i') }}</div>
                </div>
            @empty
                <div class="px-3 py-3 small text-muted">No changes yet.</div>
            @endforelse
        </div>
    </div>

    <div class="small text-muted"><i class="fas fa-shield-alt mr-1"></i>Every change is recorded here and emailed to you. Replaced signatures are kept on record, never deleted, and every signed document keeps the fingerprint of the signature used. After {{ config('pdts.signatures.max_pin_attempts', 5) }} wrong attempts, signing pauses for {{ config('pdts.signatures.lockout_minutes', 15) }} minutes.</div>
</div>
