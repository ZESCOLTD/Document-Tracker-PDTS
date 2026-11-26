<?php

namespace App\Livewire\Pdts\Documents;

use App\Models\PdtsDepartment;
use App\Models\PdtsDirectorate;
use App\Models\PdtsDocumentType;
use App\Models\PdtsOffice;
use App\Services\Ai\AiTimeLimit;
use App\Services\Ai\DocumentMetadataExtractor;
use App\Services\Ai\DocumentOcrClient;
use App\Services\Pdts\RegisterPdtsDocument;
use App\Services\Pdts\RegistrationApplicant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use RuntimeException;

/**
 * Document type, directorate, department and office come from Configurations.
 * The organisation is Directorate → Department → Office. The department is
 * optional: without one, only the directorate's own offices (such as the
 * Director's) are offered; with one, only that department's offices are.
 * The chosen names are saved on the document, so it keeps the names it was
 * registered under if they are renamed later.
 */
class Registration extends Component
{
    use WithFileUploads;

    public string $title = '';

    public string $subject = '';

    public string $document_reference = '';

    public string $document_date = '';

    public string $description = '';

    public string $directorate_id = '';

    public string $department_id = '';

    public string $office_id = '';

    public string $document_type_id = '';

    public $attachment;

    #[Locked]
    public string $ocrText = '';

    #[Locked]
    public int $ocrPageCount = 0;

    #[Locked]
    public ?string $ocrError = null;

    #[Locked]
    public ?string $autoFillError = null;

    #[Locked]
    public bool $autoFillComplete = false;

    #[Locked]
    public array $autoFilledValues = [];

    #[Locked]
    public array $applicantDetails = [];

    #[Locked]
    public string $applicantUnitSource = '';

    public function updatedAttachment(): void
    {
        foreach (['title', 'subject', 'description', 'document_reference', 'document_date', 'document_type_id'] as $field) {
            if (isset($this->autoFilledValues[$field]) && $this->{$field} === $this->autoFilledValues[$field]) {
                $this->{$field} = '';
            }
        }
        $this->reset(['ocrText', 'ocrPageCount', 'ocrError', 'autoFillError', 'autoFillComplete', 'autoFilledValues']);
        if ($this->attachment && Auth::user()?->can('documents.register') && Auth::user()?->can('ai.ocr.use')) {
            $this->dispatch('registration-uploaded');
        }
    }

    public function fillFromDocument(DocumentOcrClient $ocr, DocumentMetadataExtractor $extractor): void
    {
        abort_unless(Auth::user()?->can('documents.register') && Auth::user()?->can('ai.ocr.use'), 403);
        if ($this->autoFillComplete) {
            return;
        }
        $this->autoFillError = null;
        $this->analyseDocument($ocr);
        if ($this->ocrError !== null) {
            return;
        }
        AiTimeLimit::allow(max(30, (int) config('services.azure_openai.timeout', 90) + 15));
        try {
            $metadata = $extractor->extract($this->ocrText, $this->documentTypeOptions());
            foreach (['title', 'subject', 'description', 'document_reference', 'document_date', 'document_type_id'] as $field) {
                if ($metadata[$field] !== null && trim($this->{$field}) === '') {
                    $this->{$field} = (string) $metadata[$field];
                    $this->autoFilledValues[$field] = $this->{$field};
                    $this->resetValidation($field);
                }
            }
            $this->autoFillComplete = true;
        } catch (RuntimeException $exception) {
            $this->autoFillError = $exception->getMessage();
        }
    }

    protected function documentTypeOptions(): array
    {
        return PdtsDocumentType::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all();
    }

    public function analyseDocument(DocumentOcrClient $ocr): void
    {
        abort_unless(Auth::user()?->can('documents.register') && Auth::user()?->can('ai.ocr.use'), 403);

        $this->reset(['ocrText', 'ocrPageCount', 'ocrError']);
        $this->validateOnly('attachment');
        AiTimeLimit::allow(max(30, (int) config('services.pdts_ai.timeout', 180) + 15));

        try {
            $result = $ocr->extract($this->attachment);
            $this->ocrText = $result['text'];
            $this->ocrPageCount = $result['page_count'];
        } catch (RuntimeException $exception) {
            $this->ocrError = $exception->getMessage();
        }
    }

    public function mount(): void
    {
        // Start from the registrar's own office, when they have an active one.
        $user = Auth::user();
        $office = $user->currentPdtsOfficeAssignments()
            ->with(['office.department.directorate', 'office.directorate'])
            ->orderByDesc('is_primary')
            ->latest('starts_at')
            ->get()
            ->pluck('office')
            ->first(fn (?PdtsOffice $office) => $office?->isUsable());

        $directorates = $office ? collect() : PdtsDirectorate::query()->where('is_active', true)->with('departments')->get();
        $details = app(RegistrationApplicant::class)->resolve($user, $office, $directorates);
        $this->applicantDetails = $details['applicant'];
        $this->applicantUnitSource = $details['unit_source'];
        $this->directorate_id = $details['directorate_id'];
        $this->department_id = $details['department_id'];
        $this->office_id = $details['office_id'];
    }

    public function updatedDirectorateId(): void
    {
        $this->department_id = '';
        $this->office_id = '';
        $this->resetValidation(['department_id', 'office_id']);
    }

    public function updatedDepartmentId(): void
    {
        $this->office_id = '';
        $this->resetValidation('office_id');
    }

    protected function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'document_reference' => ['nullable', 'string', 'max:255'],
            'document_date' => ['nullable', 'date_format:Y-m-d'],
            'description' => ['nullable', 'string', 'max:10000'],
            'directorate_id' => [
                'required',
                'integer',
                Rule::exists('pdts_directorates', 'id')->where('is_active', true),
            ],
            'department_id' => [
                'nullable',
                'integer',
                Rule::exists('pdts_departments', 'id')
                    ->where('is_active', true)
                    ->where('directorate_id', (int) $this->directorate_id),
            ],
            'office_id' => [
                'required',
                'integer',
                Rule::exists('pdts_offices', 'id')->where(fn ($query) => $this->officesInSelectedUnit($query)),
            ],
            'document_type_id' => [
                'required',
                'integer',
                Rule::exists('pdts_document_types', 'id')->where('is_active', true),
            ],
            'attachment' => ['required', 'file', 'mimes:pdf,png,jpg,jpeg,tif,tiff', 'max:20480'],
        ];
    }

    protected function messages(): array
    {
        return [
            'directorate_id.required' => 'Choose the directorate the document comes from.',
            'directorate_id.exists' => 'Choose an active directorate.',
            'department_id.exists' => 'Choose an active department in the selected directorate.',
            'office_id.required' => 'Choose the office the document comes from.',
            'office_id.exists' => $this->department_id === ''
                ? 'Choose one of the directorate’s own offices, or pick a department first.'
                : 'Choose an active office in the selected department.',
        ];
    }

    /**
     * Active offices of the chosen department, or, with no department chosen,
     * the active offices that sit directly under the chosen directorate.
     *
     * @param  Builder|\Illuminate\Database\Query\Builder  $query
     */
    private function officesInSelectedUnit($query)
    {
        $query->where('is_active', true);

        return $this->department_id === ''
            ? $query->whereNull('department_id')->where('directorate_id', (int) $this->directorate_id)
            : $query->where('department_id', (int) $this->department_id);
    }

    public function save(RegisterPdtsDocument $registration)
    {
        $validated = $this->validate();

        $office = PdtsOffice::with(['department.directorate', 'directorate'])->findOrFail((int) $validated['office_id']);

        $document = $registration->handle([
            'title' => $validated['title'],
            'subject' => $validated['subject'],
            'document_reference' => $validated['document_reference'] ?: null,
            'document_date' => $validated['document_date'] ?: null,
            'description' => $validated['description'] ?: null,
            'document_type_id' => (int) $validated['document_type_id'],
            'originating_directorate' => $office->owningDirectorate()->name,
            'originating_department' => $office->department?->name,
            'originating_office' => $office->name,
        ], auth()->user(), $this->attachment);

        session()->flash('success', 'Document registered. Print the cover sheet and staple it to the front of the paper, so it can be scanned and tracked.');

        return redirect()->route('pdts.documents.show', $document);
    }

    public function render()
    {
        return view('livewire.pdts.documents.registration', $this->formOptions())
            ->layout('layouts.master', ['title' => 'Register Document']);
    }

    /**
     * Choices for the form's dropdowns, shared with the internal document form.
     */
    protected function formOptions(): array
    {
        $user = Auth::user();

        return [
            'documentTypes' => PdtsDocumentType::query()->where('is_active', true)->orderBy('name')->get(),
            'directorates' => PdtsDirectorate::query()->where('is_active', true)->orderBy('name')->get(),
            'departments' => $this->directorate_id === ''
                ? collect()
                : PdtsDepartment::query()
                    ->where('is_active', true)
                    ->where('directorate_id', (int) $this->directorate_id)
                    ->orderBy('name')
                    ->get(),
            'offices' => $this->directorate_id === ''
                ? collect()
                : $this->officesInSelectedUnit(PdtsOffice::query())
                    ->orderByDesc('is_head')
                    ->orderBy('name')
                    ->get(),
            'canManage' => [
                'types' => $user->can('document_types.manage'),
                'directorates' => $user->can('directorates.manage'),
                'departments' => $user->can('departments.manage'),
                'offices' => $user->can('offices.manage'),
            ],
        ];
    }
}
