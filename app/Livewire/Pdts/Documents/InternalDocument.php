<?php

namespace App\Livewire\Pdts\Documents;

use App\Models\PdtsDocumentTemplate;
use App\Models\PdtsDocumentType;
use App\Models\PdtsOffice;
use App\Services\Pdts\InternalDocuments;
use App\Services\Pdts\RegisterPdtsDocument;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

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

    public function mount(): void
    {
        parent::mount();

        $user = Auth::user();
        $this->from = (string) ($user->job_title ?: $user->name);
        $this->memo_date = now()->toDateString();

        $templates = $this->activeTemplates();
        if ($templates->count() === 1) {
            $this->template_id = (string) $templates->first()->id;
            $this->updatedTemplateId();
        }
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
            if (isset($labels[$name])) {
                $fields[$name] = ['name' => $name, 'custom' => false, 'label' => $labels[$name]];
            } elseif (isset($custom[$name])) {
                $fields[$name] = ['name' => $name, 'custom' => true, 'label' => $custom[$name]];
            }
        }

        return array_values($fields);
    }

    public function selectedTemplate(): ?PdtsDocumentTemplate
    {
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
        ];

        foreach (array_keys($template?->customFields() ?? []) as $name) {
            $rules['custom.'.$name] = ['nullable', 'string', 'max:2000'];
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
        ];
    }

    protected function validationAttributes(): array
    {
        $attributes = [];
        foreach ($this->selectedTemplate()?->customFields() ?? [] as $name => $label) {
            $attributes['custom.'.$name] = $label;
        }

        return $attributes;
    }

    public function save(?RegisterPdtsDocument $registration = null)
    {
        $validated = $this->validate();

        $office = PdtsOffice::with(['department.directorate', 'directorate'])->findOrFail((int) $validated['office_id']);
        $template = PdtsDocumentTemplate::findOrFail((int) $validated['template_id']);
        $uses = fn (string $field) => $template->usesField($field);

        $custom = [];
        foreach (array_keys($template->customFields()) as $name) {
            $custom[$name] = trim((string) ($validated['custom'][$name] ?? ''));
        }

        $document = app(InternalDocuments::class)->createDraft([
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
        ], Auth::user(), $template, $office);

        session()->flash('success', $template->isPdf()
            ? "Draft {$document->reference_number} created. Download the PDF, print it, fill it in and sign it, then upload the signed scan here."
            : "Draft {$document->reference_number} created. Download the Word file, type and print it, sign it, then upload the signed scan here.");

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

        return view('livewire.pdts.documents.internal-document', [
            // Only the types of the uploaded templates (these keys win over formOptions()).
            'documentTypes' => $this->documentTypes($template),
            'typeFromTemplate' => (bool) ($template?->document_type_id && $template->documentType?->is_active),
        ] + $this->formOptions() + [
            'templates' => $this->activeTemplates(),
            'selectedTemplate' => $template,
            'uses' => fn (string $field) => $template?->usesField($field) ?? false,
            'customFields' => $template?->customFields() ?? [],
            'formFields' => $this->formFields($template),
            'canManageTemplates' => Auth::user()->can('document_types.manage'),
        ])->layout('layouts.master', ['title' => 'New Internal Document']);
    }
}
