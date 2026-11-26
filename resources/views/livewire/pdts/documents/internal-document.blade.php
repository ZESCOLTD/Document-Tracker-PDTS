<div class="{{ $preview ? '' : 'container-fluid py-4' }}">
    @unless ($preview)
    <div class="d-flex flex-wrap justify-content-between align-items-start mb-4" style="gap: .5rem;">
        <div>
            @if ($editing)
                <a href="{{ route('pdts.documents.show', $editing) }}" class="small text-muted"><i class="fas fa-arrow-left mr-1"></i>{{ $editing->reference_number }}</a>
                <h1 class="h3 mb-1 mt-1">Edit draft {{ $editing->reference_number }}</h1>
                <p class="text-muted mb-0">Saving keeps the earlier version, so the changes can be seen.</p>
            @else
                <h1 class="h3 mb-1">New Internal Document</h1>
                <p class="text-muted mb-0">PDTS reserves a tracking reference and gives you the template's own file (Word or PDF) with your details filled in and the QR code already placed.</p>
            @endif
        </div>
        @unless ($editing)
            <a href="{{ route('pdts.documents.register') }}" class="btn btn-outline-secondary">Register a received document instead</a>
        @endunless
    </div>

    @if ($editing?->signature_status === \App\Models\PdtsDocument::SIGNATURE_RETURNED && $editing->signature_note)
        <div class="alert alert-warning"><i class="fas fa-undo mr-1"></i><strong>{{ $editing->signatory?->name }} returned it:</strong> "{{ $editing->signature_note }}"</div>
    @endif
    @if ($replyTo)
        <div class="alert alert-info"><i class="fas fa-reply mr-1"></i>This is a reply to <a href="{{ route('pdts.documents.show', $replyTo) }}" class="alert-link">{{ $replyTo->reference_number }}</a> ({{ $replyTo->subject }}). The two are linked.</div>
    @endif

    <ol class="d-flex flex-wrap list-unstyled small text-muted mb-4" style="gap: 1.25rem;">
        <li><span class="badge badge-primary mr-1">1</span>Fill in the details</li>
        <li><span class="badge badge-secondary mr-1">2</span>Check the draft</li>
        <li><span class="badge badge-secondary mr-1">3</span>{{ $selectedTemplate?->isUnsigned() ? 'Issue it in PDTS' : 'Sign it in PDTS with your signature PIN, or print, sign and upload the scan' }}</li>
    </ol>

    @endunless

    <form wire:submit.prevent="{{ $preview ? 'checkPreview' : 'save' }}">
        @unless ($preview)
        <section class="bg-white border rounded p-4 mb-4">
            <h2 class="h5 mb-3">Template</h2>

            @if ($templates->isEmpty())
                <div class="alert alert-warning mb-0">
                    <i class="fas fa-exclamation-triangle mr-1"></i>There are no active templates.
                    @if ($canManageTemplates)
                        <a href="{{ route('pdts.organization.document-templates') }}" class="alert-link">Add one in Configurations.</a>
                    @else
                        Ask an administrator to add one.
                    @endif
                </div>
            @else
                <style>
                    .ti-info { display: flex; flex-direction: column; gap: .25rem; border: 1px solid #dfe6e6; border-radius: .75rem; padding: .75rem 1rem; background: #f5fafa; margin-top: .75rem; }
                    .ti-desc { font-size: .8125rem; color: #5b6b6b; }
                    .ti-meta { font-size: .8125rem; color: #3d4b4b; display: flex; flex-wrap: wrap; gap: .25rem 1rem; }
                    .ti-meta i { color: #093f42; }
                </style>
                @php
                    // Editing a draft: just the template it was made from (even if since replaced).
                    $pickable = $editing ? collect([$editing->template])->filter() : $templates;
                    $signing = fn ($t) => match (true) {
                        $t->isPdf() => ['fa-print', 'Filled in and signed on paper'],
                        $t->supportsElectronicSignature() => ['fa-signature', 'Signed in PDTS'],
                        $t->isUnsigned() => ['fa-check', 'Not signed: issued in PDTS'],
                        default => ['fa-print', 'Signed on paper'],
                    };
                @endphp
                <div class="form-row" data-test="template-picker">
                    <div class="form-group col-md-6 mb-0">
                        <label for="internal-template">{{ $editing ? 'Made from' : 'Template' }}</label>
                        <select id="internal-template" class="form-control @error('template_id') is-invalid @enderror" wire:model.live="template_id" @disabled($editing) required>
                            <option value="">Choose a template…</option>
                            @foreach ($pickable->sortBy('name') as $template)
                                <option value="{{ $template->id }}">{{ $template->name }}</option>
                            @endforeach
                        </select>
                        @error('template_id') <div class="text-danger mt-1">{{ $message }}</div> @enderror
                    </div>
                </div>

                @if ($selectedTemplate)
                    {{-- What the chosen template is: its description and how it is signed (its document type is set behind the scenes). --}}
                    @php [$signIcon, $signText] = $signing($selectedTemplate); @endphp
                    <div class="ti-info" data-test="template-info">
                        @if ($selectedTemplate->description)
                            <span class="ti-desc">{{ $selectedTemplate->description }}</span>
                        @endif
                        <span class="ti-meta">
                            <span><i class="fas {{ $signIcon }} mr-1" aria-hidden="true"></i>{{ $signText }}</span>
                        </span>
                    </div>
                @endif

                {{-- The document type comes from the template; asked only when the template leaves it to the author. --}}
                @if ($selectedTemplate && ! $typeFromTemplate)
                    <div class="form-row mt-3">
                        <div class="form-group col-md-6 mb-0">
                            <label for="document-type">Document type</label>
                            <select id="document-type" class="form-control @error('document_type_id') is-invalid @enderror" wire:model="document_type_id" required>
                                <option value="">Select a type</option>
                                @foreach ($documentTypes as $documentType)
                                    <option value="{{ $documentType->id }}">{{ $documentType->name }}</option>
                                @endforeach
                            </select>
                            <small class="form-text text-muted">This template can be used for more than one type of document.</small>
                            @error('document_type_id') <div class="text-danger mt-1">{{ $message }}</div> @enderror
                        </div>
                    </div>
                @endif

                @if ($signatories->count() > 1 || (int) $signatory_id !== (int) auth()->id())
                    <div class="form-row mt-3" data-test="signatory-choice">
                        <div class="form-group col-md-6 mb-0">
                            <label for="internal-signatory">{{ $selectedTemplate?->isUnsigned() ? 'To be issued by' : 'To be signed by' }}</label>
                            <select id="internal-signatory" class="form-control @error('signatory_id') is-invalid @enderror" wire:model.live="signatory_id">
                                @foreach ($signatories as $person)
                                    <option value="{{ $person->id }}">{{ (int) $person->id === (int) auth()->id() ? 'Me ('.$person->name.')' : $person->name.($person->job_title ? ' · '.$person->job_title : '') }}</option>
                                @endforeach
                            </select>
                            @if ((int) $signatory_id !== (int) auth()->id())
                                <small class="form-text text-muted">You prepare it; they review and sign it with their own signature. You keep the document to send on once it is signed.</small>
                            @endif
                            @error('signatory_id') <div class="text-danger mt-1">{{ $message }}</div> @enderror
                        </div>
                    </div>
                @endif
            @endif
        </section>
        @endunless

        @if (! $selectedTemplate)
            @unless ($templates->isEmpty() || $preview)
                <div class="alert alert-light border mb-4" data-test="choose-template-first">
                    <i class="fas fa-hand-point-up mr-1 text-muted"></i>Choose a template above. The form then shows the fields that template uses.
                </div>
            @endunless
        @else
        <section class="bg-white {{ $preview ? '' : 'border rounded p-4' }} mb-4" wire:key="fields-{{ $selectedTemplate->id }}" data-test="template-fields">
            <h2 class="h5 mb-1">Details in this template</h2>
            <p class="text-muted small mb-3">
                @if ($formFields)
                    These are the fill-in lines in "{{ $selectedTemplate->name }}", in the order they appear. PDTS writes what you enter onto those lines.
                @else
                    "{{ $selectedTemplate->name }}" has no fill-in lines for PDTS to complete{{ $selectedTemplate->isPdf() ? '; it is printed as it is and filled in by hand' : '' }}.
                @endif
            </p>

            @if ($formFields)
                <div class="form-row">
                    @foreach ($formFields as $field)
                        @php
                            $name = $field['name'];
                            $setting = $field['setting'] ?? [];
                            $writing = \App\Models\PdtsDocumentTemplate::isWritingSpace($name) || ($setting['type'] ?? '') === 'long';
                            $required = ($setting['required'] ?? null) ?? in_array($name, ['to', 'from', 'date'], true);
                            $optional = $required ? '' : ' <span class="text-muted">(optional)</span>';
                            $help = $setting['help'] ?? '';
                        @endphp
                        <div class="form-group {{ $writing && $field['custom'] ? 'col-12' : 'col-md-6' }}" wire:key="field-{{ $name }}">
                            @switch($name)
                                @case('to')
                                    <label for="internal-to">{{ $field['label'] }}{!! $optional !!}</label>
                                    <input id="internal-to" type="text" class="form-control" wire:model.live.debounce.400ms="to" maxlength="255" placeholder="e.g. Director - Corporate Affairs" autocomplete="off" @required($required)>
                                    @error('to') <div class="text-danger mt-1">{{ $message }}</div> @enderror
                                    @unless ($preview)
                                        {{-- Typing in TO also finds the person in PDTS: picked, they are told when it is signed. --}}
                                        <div data-test="to-in-pdts">
                                            @if ($recipient)
                                                <div class="small text-muted mt-1" data-test="recipient-chosen"><i class="fas fa-user-check text-success mr-1" aria-hidden="true"></i>{{ $recipient->name }} is told in PDTS when it is {{ $selectedTemplate?->isUnsigned() ? 'issued' : 'signed' }} · <a href="#" class="text-danger" wire:click.prevent="clearRecipient">remove</a></div>
                                            @elseif ($toMatches->isNotEmpty())
                                                <div class="list-group shadow-sm mt-1" data-test="to-suggestions">
                                                    @foreach ($toMatches as $match)
                                                        <button type="button" class="list-group-item list-group-item-action py-1 small" wire:click="chooseRecipient({{ $match->id }})">{{ $match->name }}{{ $match->job_title ? ' · '.$match->job_title : '' }} <span class="text-muted">: tell them in PDTS</span></button>
                                                    @endforeach
                                                </div>
                                            @endif
                                        </div>
                                    @endunless
                                    @break
                                @case('from')
                                    <label for="internal-from">{{ $field['label'] }}{!! $optional !!}</label>
                                    <input id="internal-from" type="text" class="form-control" wire:model="from" maxlength="255" placeholder="e.g. Director - Investments & Finance" @required($required)>
                                    @error('from') <div class="text-danger mt-1">{{ $message }}</div> @enderror
                                    @break
                                @case('cc')
                                    <label for="internal-cc">{{ $field['label'] }}{!! $optional !!} <span class="text-muted">(one per line)</span></label>
                                    <textarea id="internal-cc" class="form-control" rows="2" wire:model.live.debounce.400ms="cc" maxlength="1000" placeholder="Managing Director&#10;All Directors"></textarea>
                                    @error('cc') <div class="text-danger mt-1">{{ $message }}</div> @enderror
                                    @unless ($preview)
                                        {{-- The line being typed also finds people in PDTS: picked, they are told when it is signed. --}}
                                        <div data-test="cc-in-pdts">
                                            @if ($ccLineMatches->isNotEmpty())
                                                <div class="list-group shadow-sm mt-1" data-test="cc-suggestions">
                                                    @foreach ($ccLineMatches as $match)
                                                        <button type="button" class="list-group-item list-group-item-action py-1 small" wire:click="copyFromLine({{ $match->id }})">{{ $match->name }}{{ $match->job_title ? ' · '.$match->job_title : '' }} <span class="text-muted">: tell them in PDTS</span></button>
                                                    @endforeach
                                                </div>
                                            @endif
                                            @if ($copies->isNotEmpty())
                                                <div class="small text-muted mt-1" data-test="copies-chosen"><i class="fas fa-user-check text-success mr-1" aria-hidden="true"></i>Told in PDTS:
                                                    @foreach ($copies as $person)
                                                        {{ $person->name }} <a href="#" class="text-danger" wire:click.prevent="removeCopy({{ $person->id }})" aria-label="Remove {{ $person->name }}">×</a>@unless ($loop->last), @endunless
                                                    @endforeach
                                                </div>
                                            @endif
                                        </div>
                                    @endunless
                                    @break
                                @case('office_reference')
                                    <label for="internal-office-ref">{{ $field['label'] }}{!! $optional !!}</label>
                                    <input id="internal-office-ref" type="text" class="form-control" wire:model="office_reference" maxlength="100" placeholder="e.g. DIF/DDAS/054/2025">
                                    @if ($help === '')
                                        <small class="form-text text-muted">Your office's own REF. The PDTS tracking reference is printed with the QR code.</small>
                                    @endif
                                    @error('office_reference') <div class="text-danger mt-1">{{ $message }}</div> @enderror
                                    @break
                                @case('date')
                                    <label for="internal-date">{{ $field['label'] }}{!! $optional !!}</label>
                                    <input id="internal-date" type="date" class="form-control" wire:model="memo_date" @required($required)>
                                    @error('memo_date') <div class="text-danger mt-1">{{ $message }}</div> @enderror
                                    @break
                                @default
                                    <label for="custom-{{ $name }}">{{ $field['label'] }}{!! $optional !!}</label>
                                    @php $type = $setting['type'] ?? ''; @endphp
                                    @if ($type === 'rich')
                                        {{-- Formatted text: bold, italic and lists; cleaned again when saved. --}}
                                        <div class="border rounded bg-white" wire:ignore data-test="rich-{{ $name }}"
                                            x-data="{
                                                value: $wire.entangle('custom.{{ $name }}'),
                                                init() {
                                                    let v = this.value || '';
                                                    if (! /<[a-z][^>]*>/i.test(v)) { v = v.split(/\n+/).filter(l => l.trim() !== '').map(l => '<p>' + l.replace(/&/g, '&amp;').replace(/</g, '&lt;') + '</p>').join(''); }
                                                    this.$refs.editor.innerHTML = v;
                                                },
                                                cmd(name) { this.$refs.editor.focus(); document.execCommand(name, false, null); this.sync(); },
                                                sync() { this.value = this.$refs.editor.innerHTML; },
                                            }">
                                            <div class="d-flex flex-wrap border-bottom px-1 py-1" style="gap: .25rem; background: #f7fafa;" role="toolbar" aria-label="Formatting">
                                                <button type="button" class="btn btn-sm btn-light" title="Bold" aria-label="Bold" x-on:mousedown.prevent="cmd('bold')"><i class="fas fa-bold"></i></button>
                                                <button type="button" class="btn btn-sm btn-light" title="Italic" aria-label="Italic" x-on:mousedown.prevent="cmd('italic')"><i class="fas fa-italic"></i></button>
                                                <button type="button" class="btn btn-sm btn-light" title="Bullet list" aria-label="Bullet list" x-on:mousedown.prevent="cmd('insertUnorderedList')"><i class="fas fa-list-ul"></i></button>
                                                <button type="button" class="btn btn-sm btn-light" title="Numbered list" aria-label="Numbered list" x-on:mousedown.prevent="cmd('insertOrderedList')"><i class="fas fa-list-ol"></i></button>
                                            </div>
                                            <div id="custom-{{ $name }}" x-ref="editor" contenteditable="true" role="textbox" aria-multiline="true" class="p-2" style="min-height: {{ $name === 'text_body' ? '12rem' : '7rem' }}; outline: none;"
                                                x-on:input="sync()" x-on:blur="sync()"></div>
                                        </div>
                                    @elseif (in_array($type, ['person', 'people'], true))
                                        @php $chosen = array_filter(array_map('intval', explode(',', (string) ($custom[$name] ?? '')))); @endphp
                                        <div data-test="person-{{ $name }}">
                                            @if ($chosen)
                                                <div class="d-flex flex-wrap mb-2" style="gap: .375rem;">
                                                    @foreach ($chosen as $id)
                                                        @php $person = $pickedPeople[$id] ?? null; @endphp
                                                        <span class="badge badge-light border p-2" style="font-size: .8125rem; font-weight: 500;">
                                                            <i class="fas fa-user mr-1 text-muted"></i>{{ $person?->name ?? 'Unknown' }}@if ($person?->job_title)<span class="text-muted">, {{ $person->job_title }}</span>@endif
                                                            <button type="button" class="btn btn-link btn-sm p-0 ml-1 text-muted" wire:click="removePerson('{{ $name }}', {{ $id }})" aria-label="Remove {{ $person?->name }}"><i class="fas fa-times"></i></button>
                                                        </span>
                                                    @endforeach
                                                </div>
                                            @endif
                                            @if ($type === 'people' || ! $chosen)
                                                <div class="position-relative">
                                                    <input id="custom-{{ $name }}" type="search" class="form-control" wire:model.live.debounce.300ms="personSearch.{{ $name }}" autocomplete="off"
                                                        placeholder="{{ $type === 'people' ? 'Search by name, man number or job title to add people' : 'Search by name, man number or job title' }}">
                                                    @if (($personMatches[$name] ?? collect())->isNotEmpty())
                                                        <div class="list-group position-absolute w-100 shadow-sm" style="z-index: 20;">
                                                            @foreach ($personMatches[$name] as $match)
                                                                <button type="button" class="list-group-item list-group-item-action py-2" wire:click="choosePerson('{{ $name }}', {{ $match->id }})">
                                                                    {{ $match->name }} <span class="small text-muted">{{ $match->man_no }}{{ $match->job_title ? ' · '.$match->job_title : '' }}</span>
                                                                </button>
                                                            @endforeach
                                                        </div>
                                                    @endif
                                                </div>
                                            @endif
                                        </div>
                                    @elseif ($type === 'table')
                                        {{-- Table: the administrator's columns, rows the author adds --}}
                                        <div class="table-responsive" data-test="table-{{ $name }}">
                                            <table class="table table-sm table-bordered mb-2">
                                                <thead class="thead-light">
                                                    <tr>
                                                        @foreach ($setting['columns'] ?? [] as $column)<th class="small">{{ $column }}</th>@endforeach
                                                        <th style="width: 2.5rem;"></th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    @foreach ($tableRows[$name] ?? [] as $r => $tableRow)
                                                        <tr wire:key="table-{{ $name }}-{{ $r }}">
                                                            @foreach ($setting['columns'] ?? [] as $col => $column)
                                                                <td class="p-1"><input type="text" class="form-control form-control-sm" maxlength="500" wire:model="tableRows.{{ $name }}.{{ $r }}.{{ $col }}" aria-label="{{ $column }}, row {{ $r + 1 }}"></td>
                                                            @endforeach
                                                            <td class="p-1 text-center align-middle">
                                                                <button type="button" class="btn btn-link btn-sm p-0 text-muted" wire:click="removeTableRow('{{ $name }}', {{ $r }})" title="Remove this row" aria-label="Remove row {{ $r + 1 }}"><i class="fas fa-times"></i></button>
                                                            </td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="addTableRow('{{ $name }}')"><i class="fas fa-plus mr-1"></i>Add a row</button>
                                        </div>
                                    @elseif ($type === 'attachment')
                                        <div data-test="attachment-{{ $name }}">
                                            @if (! empty($keptFiles[$name]) && empty($customFiles[$name]))
                                                <div class="d-flex align-items-center mb-2" style="gap: .5rem;">
                                                    <span class="small"><i class="fas fa-paperclip mr-1"></i>{{ $keptFiles[$name] }}</span>
                                                    <button type="button" class="btn btn-link btn-sm p-0" wire:click="removeAttachment('{{ $name }}')">Remove</button>
                                                </div>
                                            @endif
                                            <input id="custom-{{ $name }}" type="file" class="form-control-file" wire:model="customFiles.{{ $name }}" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.png,.jpg,.jpeg">
                                            <small class="form-text text-muted" wire:loading wire:target="customFiles.{{ $name }}">Uploading…</small>
                                            <small class="form-text text-muted">PDF, Word, Excel, PowerPoint or a picture, up to 20 MB. It is kept with the document in PDTS{{ ! empty($keptFiles[$name]) ? '; choosing a new file replaces it' : '' }}.</small>
                                            @error('customFiles.'.$name) <div class="text-danger mt-1">{{ $message }}</div> @enderror
                                        </div>
                                    @elseif ($writing)
                                        <textarea id="custom-{{ $name }}" class="form-control" rows="{{ $name === 'text_body' ? 8 : 4 }}" wire:model="custom.{{ $name }}" maxlength="20000" placeholder="Type the text here. Each new line starts a new paragraph."></textarea>
                                    @elseif (($setting['type'] ?? '') === 'date')
                                        <input id="custom-{{ $name }}" type="date" class="form-control" wire:model="custom.{{ $name }}">
                                    @elseif (($setting['type'] ?? '') === 'number')
                                        <input id="custom-{{ $name }}" type="text" inputmode="decimal" class="form-control" wire:model="custom.{{ $name }}" maxlength="40" placeholder="e.g. 1,250.00">
                                    @elseif (($setting['type'] ?? '') === 'choice')
                                        <select id="custom-{{ $name }}" class="form-control" wire:model="custom.{{ $name }}">
                                            <option value="">Choose…</option>
                                            @foreach ($setting['choices'] ?? [] as $choice)
                                                <option value="{{ $choice }}">{{ $choice }}</option>
                                            @endforeach
                                        </select>
                                    @elseif ($name === $addresseeField && ! $preview)
                                        {{-- A letter's addressee line ("Recipient's full name"), as TO on a memo: typing also finds the person in PDTS. --}}
                                        <input id="custom-{{ $name }}" type="text" class="form-control" wire:model.live.debounce.400ms="custom.{{ $name }}" maxlength="2000" autocomplete="off">
                                        <div data-test="addressee-in-pdts">
                                            @if ($recipient)
                                                <div class="small text-muted mt-1" data-test="recipient-chosen"><i class="fas fa-user-check text-success mr-1" aria-hidden="true"></i>{{ $recipient->name }} is told in PDTS when it is {{ $selectedTemplate?->isUnsigned() ? 'issued' : 'signed' }} · <a href="#" class="text-danger" wire:click.prevent="clearRecipient">remove</a></div>
                                            @elseif ($addresseeMatches->isNotEmpty())
                                                <div class="list-group shadow-sm mt-1" data-test="addressee-suggestions">
                                                    @foreach ($addresseeMatches as $match)
                                                        <button type="button" class="list-group-item list-group-item-action py-1 small" wire:click="chooseRecipient({{ $match->id }})">{{ $match->name }}{{ $match->job_title ? ' · '.$match->job_title : '' }} <span class="text-muted">: tell them in PDTS</span></button>
                                                    @endforeach
                                                </div>
                                            @endif
                                        </div>
                                    @else
                                        <input id="custom-{{ $name }}" type="text" class="form-control" wire:model="custom.{{ $name }}" maxlength="2000">
                                    @endif
                                    @error('custom.'.$name) <div class="text-danger mt-1">{{ $message }}</div> @enderror
                            @endswitch
                            @if ($help !== '')
                                <small class="form-text text-muted">{{ $help }}</small>
                            @endif
                        </div>
                    @endforeach
                </div>
                @if (collect($formFields)->contains('custom', true))
                    <div class="small text-muted mb-3"><i class="fas fa-info-circle mr-1"></i>A field left empty is left out of the document, with its label.</div>
                @endif
            @endif

            @if ($automaticName !== null)
                {{-- No subject line on the template: PDTS names the document itself, nothing to type. --}}
                <div class="border rounded px-3 py-2 small" style="background: #f6f9f9;" data-test="automatic-name">
                    <i class="fas fa-tag mr-1 text-muted"></i>Name in PDTS: <strong>{{ $automaticName }}</strong>
                    <div class="text-muted mt-1">
                        This template has no subject line, so PDTS names the document {{ $selectedTemplate?->titleField() ? 'after its title, or else ' : '' }}from the template, {{ $selectedTemplate?->isUnsigned() ? 'the person issuing it' : 'the signatory' }} and the date. It is how the document is found in lists, searches and the QR scan page.
                    </div>
                </div>
            @else
            <div class="form-row">
                <div class="form-group col-md-8 mb-md-0">
                    <label for="document-subject">Subject</label>
                    <input id="document-subject" type="text" class="form-control" wire:model="subject" maxlength="255" placeholder="e.g. Revision of petty cash limit on mechanical workshop requirements" required>
                    @error('subject') <div class="text-danger mt-1">{{ $message }}</div> @enderror
                </div>
                <div class="form-group col-md-4 mb-0">
                    <label for="document-title">Short title <span class="text-muted">(optional)</span></label>
                    <input id="document-title" type="text" class="form-control" wire:model="title" maxlength="255" placeholder="Defaults to the subject">
                    @error('title') <div class="text-danger mt-1">{{ $message }}</div> @enderror
                </div>
            </div>
            @endif
        </section>
        @endif

        @if ($preview)
            <div class="d-flex flex-wrap align-items-center border-top pt-3" style="gap: .75rem;" data-test="preview-actions">
                <button type="submit" class="btn btn-outline-primary btn-sm" wire:loading.attr="disabled" wire:target="checkPreview">
                    <span wire:loading.remove wire:target="checkPreview"><i class="fas fa-check-double mr-1"></i>Check my answers</span>
                    <span wire:loading wire:target="checkPreview"><i class="fas fa-circle-notch fa-spin mr-1"></i>Checking…</span>
                </button>
                {{-- The document itself, filled with these answers: where each answer sits on the page. --}}
                <button type="button" class="btn btn-primary btn-sm" wire:click="previewDocument" wire:loading.attr="disabled" wire:target="previewDocument" data-test="preview-document">
                    <span wire:loading.remove wire:target="previewDocument"><i class="fas fa-file-pdf mr-1"></i>Preview the document</span>
                    <span wire:loading wire:target="previewDocument"><i class="fas fa-circle-notch fa-spin mr-1"></i>Making the document…</span>
                </button>
                @if ($previewPassed)
                    <span class="small text-success" data-test="preview-passed"><i class="fas fa-check-circle mr-1"></i>These answers pass the form's checks.</span>
                @elseif ($errors->any())
                    <span class="small text-danger"><i class="fas fa-exclamation-circle mr-1"></i>Some answers need attention: see the messages above.</span>
                @endif
                <span class="small text-muted ml-auto"><i class="fas fa-eye mr-1"></i>Preview only: nothing is saved, no reference number is used. Authors also choose the type, office and who it is for.</span>
            </div>
            @error('preview') <div class="text-danger small mt-2">{{ $message }}</div> @enderror
            @if ($previewPdfUrl)
                <div class="mt-3" data-test="preview-pdf">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="small text-muted"><i class="fas fa-info-circle mr-1"></i>The document with these answers, a sample reference and QR code{{ $selectedTemplate?->supportsElectronicSignature() ? ', and a sample signature' : '' }}. Not saved.</span>
                        <span class="d-flex align-items-center flex-shrink-0 ml-2" style="gap: .75rem;">
                            <a href="{{ $previewPdfUrl }}" target="_blank" rel="noopener" class="small">Open in a new tab</a>
                            <button type="button" class="btn btn-outline-secondary btn-sm" wire:click="closePreview" data-test="close-preview"><i class="fas fa-times mr-1"></i>Close the preview</button>
                        </span>
                    </div>
                    <iframe src="{{ $previewPdfUrl }}" title="The document with these answers" style="width: 100%; height: 80vh; border: 1px solid #dfe6e6; border-radius: .5rem; background: #e9eef0;"></iframe>
                </div>
            @endif
        @endif

        {{-- No separate "Who it is for": people are named in the document's own TO, addressee and CC lines; a document without them reaches people by forwarding. --}}

        @unless ($preview)
        <section class="bg-white border rounded p-4 mb-4">
            <h2 class="h5 mb-3">Originating office</h2>
            <div class="form-row">
                <div class="form-group col-md-4">
                    <label for="originating-directorate">Directorate</label>
                    <select id="originating-directorate" class="form-control @error('directorate_id') is-invalid @enderror" wire:model.live="directorate_id" required @disabled($directorates->isEmpty())>
                        <option value="">Select a directorate</option>
                        @foreach ($directorates as $directorate)
                            <option value="{{ $directorate->id }}">{{ $directorate->name }}</option>
                        @endforeach
                    </select>
                    @error('directorate_id') <div class="text-danger mt-1">{{ $message }}</div> @enderror
                </div>
                <div class="form-group col-md-4">
                    <label for="originating-department">Department <span class="text-muted">(optional)</span></label>
                    <select id="originating-department" class="form-control @error('department_id') is-invalid @enderror" wire:model.live="department_id" @disabled($directorate_id === '')>
                        <option value="">{{ $directorate_id === '' ? 'Choose a directorate first' : 'None — the directorate itself' }}</option>
                        @foreach ($departments as $department)
                            <option value="{{ $department->id }}">{{ $department->name }}</option>
                        @endforeach
                    </select>
                    @error('department_id') <div class="text-danger mt-1">{{ $message }}</div> @enderror
                </div>
                <div class="form-group col-md-4">
                    <label for="originating-office">Office</label>
                    <select id="originating-office" class="form-control @error('office_id') is-invalid @enderror" wire:model="office_id" required @disabled($directorate_id === '' || $offices->isEmpty())>
                        <option value="">{{ $directorate_id === '' ? 'Choose a directorate first' : ($offices->isEmpty() ? 'No active offices' : 'Select an office') }}</option>
                        @foreach ($offices as $office)
                            <option value="{{ $office->id }}">{{ $office->name }}</option>
                        @endforeach
                    </select>
                    @error('office_id') <div class="text-danger mt-1">{{ $message }}</div> @enderror
                </div>
            </div>

            <div class="form-group mb-0">
                <label for="document-description">Note for the tracking record <span class="text-muted">(optional, not printed)</span></label>
                <textarea id="document-description" class="form-control" rows="2" wire:model="description" maxlength="10000"></textarea>
                @error('description') <div class="text-danger mt-1">{{ $message }}</div> @enderror
            </div>
        </section>

        <div class="d-flex justify-content-end">
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save" @disabled($templates->isEmpty())>
                <span wire:loading.remove wire:target="save"><i class="fas {{ $editing ? 'fa-save' : 'fa-file-word' }} mr-1"></i>{{ $editing ? 'Save changes' : 'Create draft' }}</span>
                <span wire:loading wire:target="save">{{ $editing ? 'Saving...' : 'Creating...' }}</span>
            </button>
        </div>
        @endunless
    </form>
</div>
