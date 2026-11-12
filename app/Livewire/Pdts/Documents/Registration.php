<?php

namespace App\Livewire\Pdts\Documents;

use App\Models\PdtsDepartment;
use App\Models\PdtsDirectorate;
use App\Models\PdtsDocumentType;
use App\Models\PdtsOffice;
use App\Services\Pdts\RegisterPdtsDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithFileUploads;

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
    public string $description = '';
    public string $directorate_id = '';
    public string $department_id = '';
    public string $office_id = '';
    public string $document_type_id = '';
    public $attachment;

    public function mount(): void
    {
        // Start from the registrar's own office, when they have an active one.
        $office = Auth::user()->currentPdtsOfficeAssignments()
            ->with(['office.department.directorate', 'office.directorate'])
            ->orderByDesc('is_primary')
            ->latest('starts_at')
            ->get()
            ->pluck('office')
            ->first(fn (?PdtsOffice $office) => $office?->isUsable());

        if ($office) {
            $this->directorate_id = (string) $office->owningDirectorate()->id;
            $this->department_id = (string) ($office->department_id ?? '');
            $this->office_id = (string) $office->id;
        }
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
            'description' => $validated['description'] ?: null,
            'document_type_id' => (int) $validated['document_type_id'],
            'originating_directorate' => $office->owningDirectorate()->name,
            'originating_department' => $office->department?->name,
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
        ])->layout('layouts.master', ['title' => 'Register Document']);
    }
}
