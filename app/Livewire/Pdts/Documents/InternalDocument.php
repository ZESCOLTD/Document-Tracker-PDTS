<?php

namespace App\Livewire\Pdts\Documents;

use App\Models\PdtsDocumentTemplate;
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
 */
class InternalDocument extends Registration
{
    public string $template_id = '';

    public string $to = '';

    public string $from = '';

    public string $cc = '';

    public string $office_reference = '';

    public string $memo_date = '';

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
        $template = PdtsDocumentTemplate::find((int) $this->template_id);

        if ($template?->document_type_id && $template->documentType?->is_active) {
            $this->document_type_id = (string) $template->document_type_id;
        }
    }

    protected function rules(): array
    {
        $rules = parent::rules();
        unset($rules['attachment']);
        $rules['title'] = ['nullable', 'string', 'max:255'];

        return $rules + [
            'template_id' => [
                'required',
                'integer',
                Rule::exists('pdts_document_templates', 'id')->where('is_active', true),
            ],
            'to' => ['required', 'string', 'max:255'],
            'from' => ['required', 'string', 'max:255'],
            'cc' => ['nullable', 'string', 'max:1000'],
            'office_reference' => ['nullable', 'string', 'max:100'],
            'memo_date' => ['required', 'date'],
        ];
    }

    protected function messages(): array
    {
        return parent::messages() + [
            'template_id.required' => 'Choose a template.',
            'template_id.exists' => 'Choose an active template.',
            'to.required' => 'Say who the document is addressed to.',
            'from.required' => 'Say who the document is from.',
        ];
    }

    public function save(?RegisterPdtsDocument $registration = null)
    {
        $validated = $this->validate();

        $office = PdtsOffice::with(['department.directorate', 'directorate'])->findOrFail((int) $validated['office_id']);
        $template = PdtsDocumentTemplate::findOrFail((int) $validated['template_id']);

        $document = app(InternalDocuments::class)->createDraft([
            'title' => $validated['title'] ?: $validated['subject'],
            'subject' => $validated['subject'],
            'description' => $validated['description'] ?: null,
            'document_type_id' => (int) $validated['document_type_id'],
            'to' => trim($validated['to']),
            'from' => trim($validated['from']),
            'cc' => trim((string) $validated['cc']),
            'office_reference' => trim((string) $validated['office_reference']),
            'date' => $validated['memo_date'],
        ], Auth::user(), $template, $office);

        session()->flash('success', "Draft {$document->reference_number} created. Download the Word file, type and print it, sign it, then upload the signed scan here.");

        return redirect()->route('pdts.documents.show', $document);
    }

    private function activeTemplates()
    {
        return PdtsDocumentTemplate::query()
            ->with('documentType')
            ->where('is_active', true)
            ->orderByDesc('is_builtin')
            ->orderBy('name')
            ->get();
    }

    public function render()
    {
        return view('livewire.pdts.documents.internal-document', $this->formOptions() + [
            'templates' => $this->activeTemplates(),
            'canManageTemplates' => Auth::user()->can('document_types.manage'),
        ])->layout('layouts.master', ['title' => 'New Internal Document']);
    }
}
