<?php

namespace App\Livewire\Pdts\Documents;

use App\Models\PdtsDocument;
use App\Models\PdtsDocumentAttachment;
use App\Models\PdtsDocumentTemplate;
use App\Models\PdtsDocumentType;
use App\Models\PdtsOffice;
use App\Models\User;
use App\Services\Pdts\InternalDocuments;
use App\Services\Pdts\PositionText;
use App\Services\Pdts\RegisterPdtsDocument;
use App\Services\Pdts\Signatures\PreparedDocuments;
use App\Services\Pdts\Templates\RichText;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;

/**
 * Creates an internal document from a Word template. It reuses the
 * registration form's organisation choices, but instead of a scan it reserves
 * the reference as a draft and gives the author a Word file with the QR code
 * already in the footer.
 *
 * The form follows the chosen template: heading fields appear only when the
 * template uses them, and the template's own placeholders (e.g. ${amount})
 * appear as extra fields.
 */
class InternalDocument extends Registration
{
    public string $template_id = '';

    public string $to = '';

    public string $from = '';

    public string $cc = '';

    public string $office_reference = '';

    public string $memo_date = '';

    /** Values for the template's own placeholders, keyed by placeholder name. */
    public array $custom = [];

    /** Who signs: the user, or an officer they are authorised to prepare documents for. */
    public string $signatory_id = '';

    /** The draft being edited, when editing. */
    #[Locked]
    public ?int $draftId = null;

    /** The document this one replies to. */
    #[Locked]
    public ?int $replyToId = null;

    /** Who it is for in PDTS (the TO person), and who gets a copy. */
    public string $recipient_user_id = '';

    /** @var list<int> */
    public array $cc_user_ids = [];

    public string $recipientSearch = '';

    public string $ccSearch = '';

    /** Files for the template's attachment questions, by field name. */
    public array $customFiles = [];

    /** What is typed in each person question's search box, by field name. */
    public array $personSearch = [];

    /** Table questions: field name => rows, each a list of cells. */
    public array $tableRows = [];

    public function addTableRow(string $name): void
    {
        $columns = $this->selectedTemplate()?->fieldSetting($name)['columns'] ?? [];
        if ($columns && count($this->tableRows[$name] ?? []) < 100) {
            $this->tableRows[$name][] = array_fill(0, count($columns), '');
        }
    }

    public function removeTableRow(string $name, int $row): void
    {
        unset($this->tableRows[$name][$row]);
        $this->tableRows[$name] = array_values($this->tableRows[$name] ?? []);
        if ($this->tableRows[$name] === []) {
            $this->addTableRow($name);
        }
    }

    /** The template's tables, from the saved answers (or one empty row). */
    private function loadTables(?PdtsDocumentTemplate $template): void
    {
        $tables = [];
        foreach (array_keys($template?->customFields() ?? []) as $name) {
            $setting = $template->fieldSetting($name);
            if ($setting['type'] !== 'table') {
                continue;
            }
            $count = max(1, count($setting['columns']));
            $rows = array_map(fn ($row) => array_pad(array_slice($row, 0, $count), $count, ''), InternalDocuments::tableRows((string) ($this->custom[$name] ?? '')));
            $tables[$name] = $rows ?: [array_fill(0, $count, '')];
        }
        $this->tableRows = $tables;
    }

    /** The tables go into the answers as JSON (filled rows only). */
    private function storeTables(): void
    {
        foreach ($this->tableRows as $name => $rows) {
            $filled = array_values(array_filter(array_map(fn ($row) => array_map(fn ($cell) => mb_substr(trim((string) $cell), 0, 500), (array) $row), (array) $rows),
                fn ($row) => implode('', $row) !== ''));
            $this->custom[$name] = $filled ? json_encode($filled, JSON_UNESCAPED_UNICODE) : '';
        }
    }

    /** Person questions: one person (custom[name] = id) or several (custom[name] = "id,id"). */
    public function choosePerson(string $name, int $id): void
    {
        $type = $this->selectedTemplate()?->fieldSetting($name)['type'] ?? null;
        if (! in_array($type, ['person', 'people'], true) || ! User::query()->where('is_banned', false)->whereKey($id)->exists()) {
            return;
        }

        $this->custom[$name] = $type === 'person'
            ? (string) $id
            : implode(',', array_unique([...self::ids($this->custom[$name] ?? ''), $id]));
        $this->personSearch[$name] = '';
        $this->resetValidation('custom.'.$name);
    }

    public function removePerson(string $name, int $id): void
    {
        $this->custom[$name] = implode(',', array_diff(self::ids($this->custom[$name] ?? ''), [$id]));
    }

    /** Takes off the file kept for an attachment question (or the one about to be uploaded). */
    public function removeAttachment(string $name): void
    {
        $this->custom[$name] = '';
        unset($this->customFiles[$name]);
    }

    /** @return list<int> */
    private static function ids(string $value): array
    {
        return array_values(array_unique(array_filter(array_map('intval', explode(',', $value)))));
    }

    public function chooseRecipient(int $id): void
    {
        $person = User::query()->where('is_banned', false)->find($id);
        if ($person) {
            $this->recipient_user_id = (string) $person->id;
            $this->cc_user_ids = array_values(array_diff($this->cc_user_ids, [$person->id]));
            // TO becomes their title: when empty, or when it holds what was typed to find them.
            if (trim($this->to) === '' || $this->peopleMatching($this->to)->contains('id', $person->id)) {
                $this->to = PositionText::tidy($person->job_title ?: $person->name);
            }
            // A letter's addressee line: their name, and their job title on the line for it when that is empty.
            if ($addressee = $this->addresseeField($this->selectedTemplate())) {
                $this->custom[$addressee] = (string) $person->name;
                if (array_key_exists('position_organisation', $this->selectedTemplate()->customFields()) && trim((string) ($this->custom['position_organisation'] ?? '')) === '') {
                    $this->custom['position_organisation'] = PositionText::tidy($person->job_title);
                }
            }
        }
        $this->recipientSearch = '';
    }

    public function clearRecipient(): void
    {
        $this->recipient_user_id = '';
    }

    public function addCopy(int $id): void
    {
        $person = $id !== (int) $this->recipient_user_id ? User::query()->where('is_banned', false)->find($id) : null;
        if ($person) {
            $this->cc_user_ids = array_values(array_unique([...$this->cc_user_ids, $id]));
            // On a template with a CC line: their title goes on it too (once).
            $line = self::copyLine($person);
            if ($this->showsCcLine() && ! in_array(mb_strtolower($line), array_map('mb_strtolower', $this->ccLines()), true)) {
                $this->cc = implode("\n", [...$this->ccLines(), $line]);
            }
        }
        $this->ccSearch = '';
    }

    /**
     * A person picked from the suggestions under the CC line: the line being
     * typed becomes their title, and they are told in PDTS.
     */
    public function copyFromLine(int $id): void
    {
        $lines = preg_split('/\R/u', $this->cc) ?: [];
        array_pop($lines);
        $this->cc = implode("\n", array_values(array_filter(array_map('trim', $lines), fn ($l) => $l !== '')));
        $this->addCopy($id);
    }

    public function removeCopy(int $id): void
    {
        $this->cc_user_ids = array_values(array_diff($this->cc_user_ids, [$id]));
        // And their line comes off the CC line, when it is still exactly as it was added.
        $person = User::query()->find($id);
        if ($person && $this->showsCcLine()) {
            $line = mb_strtolower(self::copyLine($person));
            $this->cc = implode("\n", array_values(array_filter($this->ccLines(), fn ($l) => mb_strtolower($l) !== $line)));
        }
    }

    /**
     * The line naming who a document without TO is addressed to (a letter's
     * "Recipient's full name"), when the form shows it. Null on a template
     * with TO: there, TO does it.
     */
    private function addresseeField(?PdtsDocumentTemplate $template): ?string
    {
        if (! $template || $template->usesField('to')) {
            return null;
        }
        $setting = array_key_exists('recipient_name', $template->customFields()) ? $template->fieldSetting('recipient_name') : null;

        return $setting && ! $setting['hidden'] && $setting['type'] === 'text' ? 'recipient_name' : null;
    }

    /** How someone copied is named on the paper: their job title, else their name. */
    private static function copyLine(User $person): string
    {
        return PositionText::tidy($person->job_title ?: $person->name);
    }

    /** @return list<string> the CC lines typed so far */
    private function ccLines(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/u', $this->cc) ?: []), fn ($l) => $l !== ''));
    }

    /** Whether this template's form has a CC line (not hidden). */
    private function showsCcLine(): bool
    {
        $template = $this->selectedTemplate();

        return (bool) $template?->usesField('cc') && ! $template->fieldSetting('cc')['hidden'];
    }

    private function peopleMatching(string $search)
    {
        $search = trim($search);
        if (mb_strlen($search) < 2) {
            return collect();
        }

        return User::query()->where('is_banned', false)
            ->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('man_no', 'like', "%{$search}%")->orWhere('job_title', 'like', "%{$search}%"))
            ->orderBy('name')->limit(8)->get(['id', 'name', 'man_no', 'job_title']);
    }

    /**
     * Preview mode (the template review page): the form authors fill in for
     * this template, whatever its status, with nothing saved.
     */
    #[Locked]
    public ?int $previewTemplateId = null;

    /** Preview: the answers passed the form's own checks. */
    public bool $previewPassed = false;

    public function mount(?PdtsDocument $document = null, ?int $previewTemplateId = null): void
    {
        parent::mount();

        $user = Auth::user();
        $this->signatory_id = (string) $user->id;
        $this->from = PositionText::tidy($user->job_title ?: $user->name);
        $this->memo_date = now()->toDateString();

        if ($previewTemplateId) {
            abort_unless($user->can('document_types.manage'), 403);
            $this->previewTemplateId = $previewTemplateId;
            $this->template_id = (string) $previewTemplateId;
            $this->updatedTemplateId();

            return;
        }

        if ($document?->exists) {
            abort_unless(app(PreparedDocuments::class)->canEdit($document, $user), 403, 'This draft cannot be edited now.');
            $this->draftId = $document->id;
            $this->fillFrom($document);

            return;
        }

        // Reply: a new document to the author of a received one, linked to it.
        $replyTo = request()->integer('reply_to');
        if ($replyTo) {
            $original = PdtsDocument::query()->with(['template', 'signatory', 'registrar'])->whereKey($replyTo)->where('status', '!=', 'draft')->first();
            if ($original) {
                $this->replyToId = $original->id;
                $author = $original->signatory ?? $original->registrar;
                $template = $original->template && $original->template->is_active && $original->template->isConfigured() && ! $original->template->isSuperseded()
                    ? $original->template
                    : $this->activeTemplates()->first();
                if ($template) {
                    $this->template_id = (string) $template->id;
                    $this->updatedTemplateId();
                }
                $this->to = (string) ($original->template_data['from'] ?? ($author?->job_title ?: $author?->name ?? ''));
                $this->subject = str_starts_with(mb_strtoupper((string) $original->subject), 'RE:') ? (string) $original->subject : 'RE: '.$original->subject;
                $this->office_reference = (string) $original->reference_number;
                if ($author && (int) $author->id !== (int) $user->id) {
                    $this->recipient_user_id = (string) $author->id;
                }

                return;
            }
        }

        $templates = $this->activeTemplates();
        if ($templates->count() === 1) {
            $this->template_id = (string) $templates->first()->id;
            $this->updatedTemplateId();
        }
    }

    /** The form filled in from a draft being edited. */
    private function fillFrom(PdtsDocument $document): void
    {
        $data = $document->template_data ?? [];

        $this->template_id = (string) $document->template_id;
        $this->document_type_id = (string) $document->document_type_id;
        $this->title = $document->title === $document->subject ? '' : (string) $document->title;
        $this->subject = (string) $document->subject;
        $this->description = (string) $document->description;
        $this->signatory_id = (string) $document->signatoryId();
        $this->to = (string) ($data['to'] ?? '');
        $this->from = (string) ($data['from'] ?? '');
        $this->cc = (string) ($data['cc'] ?? '');
        $this->office_reference = (string) ($data['office_reference'] ?? '');
        $this->memo_date = (string) ($data['date'] ?? now()->toDateString());
        $this->custom = array_map('strval', (array) ($data['custom'] ?? []));
        $this->loadTables(PdtsDocumentTemplate::find((int) $document->template_id));
        $this->recipient_user_id = (string) ($document->recipient_user_id ?? '');
        $this->cc_user_ids = array_map('intval', (array) ($document->cc_user_ids ?? []));

        $office = isset($data['office_id'])
            ? PdtsOffice::with(['department', 'directorate'])->find((int) $data['office_id'])
            : PdtsOffice::with(['department', 'directorate'])->where('name', $document->originating_office)->first();
        if ($office) {
            $this->directorate_id = (string) $office->owningDirectorate()?->id;
            $this->department_id = (string) ($office->department_id ?? '');
            $this->office_id = (string) $office->id;
        }
    }

    public function updatedSignatoryId(): void
    {
        $signatory = User::find((int) $this->signatory_id);
        if ($signatory) {
            $this->from = PositionText::tidy($signatory->job_title ?: $signatory->name);
        }

        // Fields filled with the signatory's details follow the new signatory.
        $template = $this->selectedTemplate();
        foreach (array_keys($template?->customFields() ?? []) as $name) {
            $auto = $template->fieldSetting($name)['auto'];
            if (str_starts_with($auto, 'author_')) {
                $this->custom[$name] = (string) $this->autoValue($auto);
            }
        }
    }

    /** The user and the officers they may prepare documents for. */
    public function signatories()
    {
        $user = Auth::user();

        return collect([$user])->merge(app(PreparedDocuments::class)->officersFor($user))->unique('id')->values();
    }

    public function updatedTemplateId(): void
    {
        $template = $this->selectedTemplate();
        $allowed = $this->documentTypes($template)->pluck('id')->map(fn ($id) => (string) $id);

        // The template's own type, or the only one there is; otherwise keep the
        // author's choice only if it is still one of the offered types.
        if ($allowed->count() === 1) {
            $this->document_type_id = $allowed->first();
        } elseif (! $allowed->contains($this->document_type_id)) {
            $this->document_type_id = '';
        }

        // Keep what was typed for fields the new template shares; drop the rest.
        $fields = array_keys($template?->customFields() ?? []);
        $this->custom = array_intersect_key($this->custom, array_flip($fields))
            + array_fill_keys($fields, '');
        $this->applyAutoFill($template);
        $this->loadTables($template);

        $this->resetValidation();
    }

    /**
     * Document types offered: the chosen template's own type, or the types of
     * the uploaded templates. Only if no template has a type are all shown.
     */
    public function documentTypes(?PdtsDocumentTemplate $template)
    {
        if ($template?->document_type_id && $template->documentType?->is_active) {
            return collect([$template->documentType]);
        }

        $fromTemplates = PdtsDocumentType::query()
            ->where('is_active', true)
            ->whereIn('id', PdtsDocumentTemplate::query()
                ->where('is_active', true)
                ->whereNotNull('configured_at')
                ->whereNotNull('document_type_id')
                ->select('document_type_id'))
            ->orderBy('name')
            ->get();

        return $fromTemplates->isNotEmpty()
            ? $fromTemplates
            : PdtsDocumentType::query()->where('is_active', true)->orderBy('name')->get();
    }

    /**
     * The template's fill-in lines in the order they appear in the document:
     * heading lines (to, from, cc, office_reference, date) and the template's
     * own fields. The subject is asked for separately.
     *
     * @return list<array{name: string, custom: bool, label: string}>
     */
    public function formFields(?PdtsDocumentTemplate $template): array
    {
        if (! $template) {
            return [];
        }

        $labels = ['to' => 'To', 'from' => 'From', 'cc' => 'CC', 'office_reference' => 'Office file reference', 'date' => 'Date'];
        $custom = $template->customFields();
        $fields = [];

        foreach ($template->placeholderNames() as $name) {
            if (isset($fields[$name])) {
                continue;
            }
            $setting = $template->fieldSetting($name);
            if ($setting['hidden']) {
                continue; // the administrator hid it: left empty on the document
            }
            if (isset($labels[$name])) {
                $fields[$name] = ['name' => $name, 'custom' => false, 'label' => $setting['label'] ?? $labels[$name], 'setting' => $setting];
            } elseif (isset($custom[$name])) {
                $fields[$name] = ['name' => $name, 'custom' => true, 'label' => $custom[$name], 'setting' => $setting];
            }
        }

        return array_values($fields);
    }

    /** A value filled in automatically for the signatory, per the template's settings. */
    private function autoValue(string $auto): ?string
    {
        $person = User::find((int) $this->signatory_id) ?? Auth::user();

        return match ($auto) {
            'author_name' => (string) $person->name,
            'author_title' => PositionText::tidy($person->job_title),
            'author_man_no' => (string) ($person->man_no ?: ''),
            'author_department' => (string) ($person->functional_section ?: $person->division ?: $person->directorate ?: ''),
            'today' => now()->toDateString(),
            default => null,
        };
    }

    /** Fills the template's auto-filled fields that are still empty. */
    private function applyAutoFill(?PdtsDocumentTemplate $template): void
    {
        foreach (array_keys($template?->customFields() ?? []) as $name) {
            $value = $this->autoValue($template->fieldSetting($name)['auto']);
            if ($value !== null && trim((string) ($this->custom[$name] ?? '')) === '') {
                $this->custom[$name] = $value;
            }
        }
    }

    public function selectedTemplate(): ?PdtsDocumentTemplate
    {
        // Preview: that template, even before it is offered to authors.
        if ($this->previewTemplateId) {
            return PdtsDocumentTemplate::query()->with('documentType')->whereNotNull('configured_at')->find($this->previewTemplateId);
        }

        if ($this->template_id === '') {
            return null;
        }

        return PdtsDocumentTemplate::query()
            ->with('documentType')
            ->where('is_active', true)
            ->whereNotNull('configured_at')
            ->find((int) $this->template_id);
    }

    protected function rules(): array
    {
        $rules = parent::rules();
        unset($rules['attachment']);
        $rules['title'] = ['nullable', 'string', 'max:255'];
        // Only the types offered for the chosen template.
        $rules['document_type_id'][] = Rule::in($this->documentTypes($this->selectedTemplate())->pluck('id')->map(fn ($id) => (string) $id)->all());

        $template = $this->selectedTemplate();
        $uses = fn (string $field) => $template?->usesField($field) ?? false;

        $rules += [
            'template_id' => [
                'required',
                'integer',
                Rule::exists('pdts_document_templates', 'id')->where('is_active', true)->whereNotNull('configured_at'),
            ],
            'to' => [$uses('to') ? 'required' : 'nullable', 'string', 'max:255'],
            'from' => [$uses('from') ? 'required' : 'nullable', 'string', 'max:255'],
            'cc' => ['nullable', 'string', 'max:1000'],
            'office_reference' => ['nullable', 'string', 'max:100'],
            'memo_date' => [$uses('date') ? 'required' : 'nullable', 'date'],
            'custom' => ['array'],
            'signatory_id' => ['required', Rule::in($this->signatories()->pluck('id')->map(fn ($id) => (string) $id)->all())],
            'recipient_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('is_banned', 0)],
            'cc_user_ids' => ['array', 'max:50'],
            'cc_user_ids.*' => ['integer', Rule::exists('users', 'id')->where('is_banned', 0)],
        ];

        // When editing, the template stays the one the draft was made from.
        if ($this->draftId) {
            $rules['template_id'] = ['required', Rule::in([(string) PdtsDocument::find($this->draftId)?->template_id])];
        }

        foreach (array_keys($template?->customFields() ?? []) as $name) {
            $setting = $template->fieldSetting($name);
            $rule = [$setting['required'] && ! $setting['hidden'] ? 'required' : 'nullable', 'string',
                PdtsDocumentTemplate::isWritingSpace($name) || $setting['type'] === 'long' ? 'max:20000' : 'max:2000'];
            if ($setting['type'] === 'date') {
                $rule[] = 'date';
            } elseif ($setting['type'] === 'number') {
                $rule[] = 'regex:/^-?[\d,\s]*\.?\d*$/';
            } elseif ($setting['type'] === 'choice' && $setting['choices']) {
                $rule[] = Rule::in($setting['choices']);
            } elseif (in_array($setting['type'], ['person', 'people'], true)) {
                $rule[] = 'regex:/^\d+(,\d+)*$/';
                $rule[] = function ($attribute, $value, $fail) {
                    $ids = self::ids((string) $value);
                    if ($ids && User::query()->whereIn('id', $ids)->where('is_banned', false)->count() !== count($ids)) {
                        $fail('Choose people who are active in PDTS.');
                    }
                };
            } elseif ($setting['type'] === 'rich') {
                $rule[2] = 'max:60000';
                if ($setting['required'] && ! $setting['hidden']) {
                    $rule[0] = 'nullable';
                    $rule[] = fn ($attribute, $value, $fail) => trim(RichText::plain((string) $value)) === '' ? $fail('Write the '.mb_strtolower($template->customFields()[$name] ?? 'text').'.') : null;
                }
            } elseif ($setting['type'] === 'table') {
                // Required: at least one filled row (the rows are stored as JSON before checking).
                $rule = [$setting['required'] && ! $setting['hidden'] ? 'required' : 'nullable', 'string', 'max:60000'];
                $rules['tableRows.'.$name] = ['array', 'max:100'];
                $rules['tableRows.'.$name.'.*.*'] = ['nullable', 'string', 'max:500'];
            } elseif ($setting['type'] === 'attachment') {
                // The answer holds the file already kept; a new one comes in customFiles.
                $rule = ['nullable', 'string', 'max:20'];
                $rules['customFiles.'.$name] = [
                    $setting['required'] && ! $setting['hidden'] && trim((string) ($this->custom[$name] ?? '')) === '' ? 'required' : 'nullable',
                    'file', 'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,png,jpg,jpeg', 'max:20480',
                ];
            }
            $rules['custom.'.$name] = $rule;
        }

        // The administrator can make a heading line required or optional.
        foreach (['to' => 'to', 'from' => 'from', 'cc' => 'cc', 'office_reference' => 'office_reference', 'date' => 'memo_date'] as $field => $property) {
            $setting = $template?->usesField($field) ? $template->fieldSetting($field) : null;
            if ($setting && ($setting['required'] !== null || $setting['hidden'])) {
                $rules[$property][0] = $setting['required'] && ! $setting['hidden'] ? 'required' : 'nullable';
            }
        }

        return $rules;
    }

    protected function messages(): array
    {
        return parent::messages() + [
            'template_id.required' => 'Choose a template.',
            'template_id.exists' => 'Choose an active template.',
            'to.required' => 'Say who the document is addressed to.',
            'from.required' => 'Say who the document is from.',
            'memo_date.required' => 'Enter the date of the document.',
            'signatory_id.in' => 'You are not authorised to prepare documents for that officer.',
            'recipient_user_id.required' => 'Choose the person who must decide.',
        ];
    }

    protected function validationAttributes(): array
    {
        $attributes = [];
        foreach ($this->selectedTemplate()?->customFields() ?? [] as $name => $label) {
            $attributes['custom.'.$name] = $label;
            $attributes['customFiles.'.$name] = $label;
        }

        return $attributes;
    }

    /**
     * Preview: runs the form's own checks on the template's fields (required
     * answers, choices, numbers, dates, people, files) without saving anything.
     */
    public function checkPreview(): void
    {
        $this->previewPassed = false;
        $this->storeTables();
        $this->nameDocument();
        $fields = fn (string $key) => in_array($key, ['to', 'from', 'cc', 'office_reference', 'memo_date', 'subject', 'title'], true)
            || str_starts_with($key, 'custom.') || str_starts_with($key, 'customFiles.') || str_starts_with($key, 'tableRows.');
        $this->validate(array_filter($this->rules(), $fields, ARRAY_FILTER_USE_KEY));
        $this->previewPassed = true;
    }

    /**
     * The name PDTS gives the document when its template has no subject line
     * (null when the author types the subject): the title typed on the form,
     * or "Template - Signatory - Date".
     */
    private function automaticName(?PdtsDocumentTemplate $template): ?string
    {
        if (! $template || $template->asksForSubject()) {
            return null;
        }

        $date = $template->usesField('date') && $this->memo_date && strtotime($this->memo_date) !== false
            ? Carbon::parse($this->memo_date) : now();
        $signatory = $this->signatory_id ? User::find((int) $this->signatory_id)?->name : Auth::user()?->name;

        return $template->documentName($this->custom, $signatory, $date->format('j F Y'));
    }

    /** No subject line on the template: the document is named for the author. */
    private function nameDocument(): void
    {
        if (($name = $this->automaticName($this->selectedTemplate())) !== null) {
            $this->subject = $name;
            $this->title = '';
        }
    }

    /** Preview: the document as it would be with these answers (a PDF shown under the form). */
    public ?string $previewPdfUrl = null;

    /** Preview: done looking; the PDF goes away (press Preview again for a new one). */
    public function closePreview(): void
    {
        $this->previewPdfUrl = null;
    }

    /**
     * Preview: checks the answers, then shows the template filled with them,
     * as the PDF a document would be. Nothing is saved.
     */
    public function previewDocument(InternalDocuments $documents): void
    {
        $this->previewPdfUrl = null;
        $this->checkPreview();
        $template = $this->selectedTemplate();
        if (! $template) {
            return;
        }

        $custom = [];
        foreach (array_keys($template->customFields()) as $name) {
            $value = trim((string) ($this->custom[$name] ?? ''));
            $custom[$name] = match ($template->fieldSetting($name)['type']) {
                'rich' => RichText::clean($value),
                'people', 'person' => implode(',', self::ids($value)),
                // A file chosen here is not kept: the preview shows where its name goes.
                'attachment' => '',
                default => $value,
            };
        }

        try {
            $pdf = $documents->previewFromAnswers($template, [
                'to' => $this->to, 'from' => $this->from, 'cc' => $this->cc, 'office_reference' => $this->office_reference,
                'date' => $this->memo_date, 'subject' => $this->subject, 'title' => $this->title, 'custom' => $custom,
                'signatory_id' => (int) $this->signatory_id ?: null, 'office_id' => (int) $this->office_id ?: null,
            ], Auth::user());
        } catch (\Throwable $e) {
            report($e);
            $this->addError('preview', 'The document could not be made. Try again.');

            return;
        }
        if ($pdf === null) {
            $this->addError('preview', $template->isPdf()
                ? 'A PDF template is printed as it is and filled in by hand, so there is nothing to fill here.'
                : 'This server cannot make PDFs yet (LibreOffice is not installed), so there is no preview.');

            return;
        }

        // Kept briefly for this administrator, as the builder's preview is.
        $token = Str::random(40);
        Cache::put('pdts.builder-preview.'.$token, ['user' => Auth::id(), 'pdf' => base64_encode($pdf)], now()->addMinutes(10));
        $this->previewPdfUrl = route('pdts.organization.document-templates.builder-preview', $token);
    }

    /** Preview: any change makes the earlier check out of date. */
    public function updated($property): void
    {
        if ($this->previewTemplateId && $property !== 'previewPassed') {
            $this->previewPassed = false;
        }
    }

    public function save(?RegisterPdtsDocument $registration = null)
    {
        // A preview never saves (no draft, no reference number).
        if ($this->previewTemplateId) {
            $this->checkPreview();

            return null;
        }

        $this->storeTables();
        $this->nameDocument();
        $validated = $this->validate();

        $office = PdtsOffice::with(['department.directorate', 'directorate'])->findOrFail((int) $validated['office_id']);
        $template = PdtsDocumentTemplate::findOrFail((int) $validated['template_id']);
        $uses = fn (string $field) => $template->usesField($field);

        $custom = [];
        $files = [];
        foreach (array_keys($template->customFields()) as $name) {
            $value = trim((string) ($validated['custom'][$name] ?? ''));
            $custom[$name] = match ($template->fieldSetting($name)['type']) {
                'rich' => RichText::clean($value),
                'people', 'person' => implode(',', self::ids($value)),
                default => $value,
            };
            if (($validated['customFiles'][$name] ?? null) instanceof UploadedFile) {
                $files[$name] = $validated['customFiles'][$name];
            }
        }

        $data = [
            'title' => $validated['title'] ?: $validated['subject'],
            'subject' => $validated['subject'],
            'description' => $validated['description'] ?: null,
            'document_type_id' => (int) $validated['document_type_id'],
            'to' => $uses('to') ? trim((string) $validated['to']) : '',
            'from' => $uses('from') ? trim((string) $validated['from']) : '',
            'cc' => $uses('cc') ? trim((string) $validated['cc']) : '',
            'office_reference' => $uses('office_reference') ? trim((string) $validated['office_reference']) : '',
            'date' => $uses('date') ? $validated['memo_date'] : now()->toDateString(),
            'custom' => $custom,
            'files' => $files,
            'signatory_id' => (int) $validated['signatory_id'],
            'reply_to_id' => $this->replyToId,
            'recipient_user_id' => $validated['recipient_user_id'] ?: null,
            'cc_user_ids' => $validated['cc_user_ids'] ?? [],
        ];

        try {
            if ($this->draftId) {
                $document = app(InternalDocuments::class)->updateDraft(PdtsDocument::findOrFail($this->draftId), $data, Auth::user(), $office);
                session()->flash('success', "Draft {$document->reference_number} saved as a new version.");

                return redirect()->route('pdts.documents.show', $document);
            }

            $document = app(InternalDocuments::class)->createDraft($data, Auth::user(), $template, $office);
        } catch (\RuntimeException $e) {
            $this->addError('signatory_id', $e->getMessage());

            return null;
        }

        session()->flash('success', match (true) {
            $document->isPreparedOnBehalf() => "Draft {$document->reference_number} prepared for {$document->signatory?->name}. Check it, then send it ".($template->isUnsigned() ? 'to be issued.' : 'for signature.'),
            $template->isUnsigned() => "Draft {$document->reference_number} created. Check it, then issue it here.",
            $template->isPdf() => "Draft {$document->reference_number} created. Download the PDF, print it, fill it in and sign it, then upload the signed scan here.",
            $template->supportsElectronicSignature() => "Draft {$document->reference_number} created. Check it, then sign it here with your signature PIN, or print, sign and upload the scan.",
            default => "Draft {$document->reference_number} created. Print it, sign it and upload the signed scan here.",
        });

        return redirect()->route('pdts.documents.show', $document);
    }

    private function activeTemplates()
    {
        return PdtsDocumentTemplate::query()
            ->with('documentType')
            ->where('is_active', true)
            ->whereNotNull('configured_at')
            ->orderBy('name')
            ->get();
    }

    public function render()
    {
        $template = $this->selectedTemplate();

        // Person and attachment questions: who is chosen, who matches the search, which file is kept.
        $personIds = [];
        $personMatches = [];
        $keptFiles = [];
        foreach (array_keys($template?->customFields() ?? []) as $name) {
            $type = $template->fieldSetting($name)['type'];
            if (in_array($type, ['person', 'people'], true)) {
                $chosen = self::ids((string) ($this->custom[$name] ?? ''));
                array_push($personIds, ...$chosen);
                $personMatches[$name] = $this->peopleMatching((string) ($this->personSearch[$name] ?? ''))->reject(fn ($p) => in_array($p->id, $chosen, true))->values();
            } elseif ($type === 'attachment' && ctype_digit((string) ($this->custom[$name] ?? '')) && $this->draftId) {
                $keptFiles[$name] = PdtsDocumentAttachment::query()->where('pdts_document_id', $this->draftId)->find((int) $this->custom[$name])?->original_filename;
            }
        }

        return view('livewire.pdts.documents.internal-document', [
            // Only the types of the uploaded templates (these keys win over formOptions()).
            'documentTypes' => $this->documentTypes($template),
            'typeFromTemplate' => (bool) ($template?->document_type_id && $template->documentType?->is_active),
        ] + $this->formOptions() + [
            'templates' => $this->previewTemplateId ? collect() : $this->activeTemplates(),
            'preview' => (bool) $this->previewTemplateId,
            'selectedTemplate' => $template,
            'uses' => fn (string $field) => $template?->usesField($field) ?? false,
            // No subject line on the template: the name PDTS gives the document, shown instead of the Subject box.
            'automaticName' => $this->automaticName($template),
            'customFields' => $template?->customFields() ?? [],
            'formFields' => $this->formFields($template),
            'canManageTemplates' => Auth::user()->can('document_types.manage'),
            'signatories' => $this->signatories(),
            'editing' => $this->draftId ? PdtsDocument::find($this->draftId) : null,
            'replyTo' => $this->replyToId ? PdtsDocument::find($this->replyToId) : null,
            'recipient' => $this->recipient_user_id !== '' ? User::find((int) $this->recipient_user_id) : null,
            'copies' => User::query()->whereIn('id', $this->cc_user_ids)->orderBy('name')->get(['id', 'name', 'job_title']),
            'recipientMatches' => $this->peopleMatching($this->recipientSearch),
            // Typing in TO, or on the line being typed in CC, also finds the people in PDTS.
            'toMatches' => $this->recipient_user_id === '' ? $this->peopleMatching($this->to)->take(5) : collect(),
            // A letter's addressee line ("Recipient's full name") does the same, on a template without TO.
            'addresseeField' => $addressee = $this->addresseeField($template),
            'addresseeMatches' => $addressee && $this->recipient_user_id === '' ? $this->peopleMatching((string) ($this->custom[$addressee] ?? ''))->take(5) : collect(),
            'ccLineMatches' => $this->peopleMatching((string) (array_slice(preg_split('/\R/u', $this->cc) ?: [], -1)[0] ?? ''))
                ->reject(fn ($p) => in_array($p->id, $this->cc_user_ids, true) || $p->id === (int) $this->recipient_user_id)->take(5),
            'ccMatches' => $this->peopleMatching($this->ccSearch)->reject(fn ($p) => in_array($p->id, $this->cc_user_ids, true) || $p->id === (int) $this->recipient_user_id),
            'pickedPeople' => $personIds ? User::query()->whereIn('id', array_unique($personIds))->get(['id', 'name', 'job_title'])->keyBy('id') : collect(),
            'personMatches' => $personMatches,
            'keptFiles' => $keptFiles,
        ])->layout('layouts.master', ['title' => $this->draftId ? 'Edit Draft' : 'New Internal Document']);
    }
}
