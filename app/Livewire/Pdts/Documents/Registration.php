<?php

namespace App\Livewire\Pdts\Documents;

use App\Models\PdtsDepartment;
use App\Models\PdtsDocumentType;
use App\Models\PdtsOffice;
use App\Services\Pdts\RegisterPdtsDocument;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Document type, department and office come from Configurations. The chosen
 * department and office names are saved on the document, so it keeps the
 * names it was registered under if they are renamed later.
 */
class Registration extends Component
{
    use WithFileUploads;

    public string $title = '';
    public string $subject = '';
    public string $description = '';
    public string $department_id = '';
    public string $office_id = '';
    public string $document_type_id = '';
    public $attachment;

    public function mount(): void
    {
        // Start from the registrar's own office, when they have an active one.
        $office = Auth::user()->currentPdtsOfficeAssignments()
            ->orderByDesc('is_primary')
            ->latest('starts_at')
            ->get()
            ->pluck('office')
            ->first(fn (?PdtsOffice $office) => $office?->is_active && $office->department?->is_active);

        if ($office) {
            $this->department_id = (string) $office->department_id;
            $this->office_id = (string) $office->id;
        }
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
            'description' => ['nullable', 'string', 'max:10000'],
            'department_id' => [
                'required',
                'integer',
                Rule::exists('pdts_departments', 'id')->where('is_active', true),
            ],
            'office_id' => [
                'required',
                'integer',
                Rule::exists('pdts_offices', 'id')
                    ->where('is_active', true)
                    ->where('department_id', (int) $this->department_id),
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
            'department_id.required' => 'Choose the department the document comes from.',
            'department_id.exists' => 'Choose an active department.',
            'office_id.required' => 'Choose the office the document comes from.',
            'office_id.exists' => 'Choose an active office in the selected department.',
        ];
    }

    public function save(RegisterPdtsDocument $registration)
    {
        $validated = $this->validate();

        $office = PdtsOffice::with('department')->findOrFail((int) $validated['office_id']);

        $document = $registration->handle([
            'title' => $validated['title'],
            'subject' => $validated['subject'],
            'description' => $validated['description'] ?: null,
            'document_type_id' => (int) $validated['document_type_id'],
            'originating_department' => $office->department->name,
            'originating_office' => $office->name,
        ], auth()->user(), $this->attachment);

        session()->flash('success', 'Document registered successfully. Write the tracking reference on the physical document.');

        return redirect()->route('pdts.documents.show', $document);
    }

    public function render()
    {
        $user = Auth::user();

        return view('livewire.pdts.documents.registration', [
            'documentTypes' => PdtsDocumentType::query()->where('is_active', true)->orderBy('name')->get(),
            'departments' => PdtsDepartment::query()->where('is_active', true)->orderBy('name')->get(),
            'offices' => $this->department_id === ''
                ? collect()
                : PdtsOffice::query()
                    ->where('is_active', true)
                    ->where('department_id', (int) $this->department_id)
                    ->orderBy('name')
                    ->get(),
            'canManage' => [
                'types' => $user->can('document_types.manage'),
                'departments' => $user->can('departments.manage'),
                'offices' => $user->can('offices.manage'),
            ],
        ])->layout('layouts.master', ['title' => 'Register Document']);
    }
}
