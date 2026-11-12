<?php

namespace App\Livewire\Pdts\Organization;

use App\Models\PdtsEmployeeOfficeAssignment;
use App\Models\PdtsOffice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

class EmployeeAssignments extends Component
{
    use WithPagination;

    protected string $paginationTheme = 'bootstrap';

    public string $search = '';
    public string $userId = '';
    public string $officeId = '';
    public string $position = '';
    public string $startsAt = '';
    public bool $isPrimary = true;
    public bool $showHistory = false;

    // The assign form is shown in a pop-up.
    public bool $showForm = false;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    protected function rules(): array
    {
        return [
            'userId' => ['required', 'integer', Rule::exists('users', 'id')->where(fn ($query) => $query->where('is_banned', false))],
            'officeId' => ['required', 'integer', Rule::exists('pdts_offices', 'id')->where(fn ($query) => $query->where('is_active', true))],
            'position' => ['nullable', 'string', 'max:191'],
            'startsAt' => ['required', 'date'],
            'isPrimary' => ['boolean'],
        ];
    }

    public function openCreate(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function cancelEdit(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    private function resetForm(): void
    {
        $this->reset(['userId', 'officeId', 'position']);
        $this->startsAt = now()->format('Y-m-d\TH:i');
        $this->isPrimary = true;
        $this->resetValidation();
    }

    public function assign(): void
    {
        $validated = $this->validate();
        $startsAt = $validated['startsAt'] ?: now();

        DB::transaction(function () use ($validated, $startsAt): void {
            if ($validated['isPrimary']) {
                PdtsEmployeeOfficeAssignment::query()
                    ->where('user_id', $validated['userId'])
                    ->where('is_primary', true)
                    ->whereNull('ends_at')
                    ->lockForUpdate()
                    ->update([
                        'ends_at' => $startsAt,
                        'is_primary' => false,
                        'updated_at' => now(),
                    ]);
            }

            PdtsEmployeeOfficeAssignment::create([
                'user_id' => $validated['userId'],
                'office_id' => $validated['officeId'],
                'assigned_by' => auth()->id(),
                'position' => $validated['position'] ?: null,
                'starts_at' => $startsAt,
                'is_primary' => $validated['isPrimary'],
            ]);
        });

        session()->flash('success', 'Employee office assignment created.');
        $this->cancelEdit();
    }

    public function endAssignment(int $id): void
    {
        $assignment = PdtsEmployeeOfficeAssignment::query()
            ->whereNull('ends_at')
            ->findOrFail($id);

        $assignment->update([
            'ends_at' => now(),
            'is_primary' => false,
        ]);

        session()->flash('success', 'Employee office assignment ended. Its history has been retained.');
    }

    public function mount(): void
    {
        $this->startsAt = now()->format('Y-m-d\TH:i');
    }

    public function render()
    {
        $assignments = PdtsEmployeeOfficeAssignment::query()
            ->with(['user', 'office.department.directorate', 'office.directorate', 'assignedBy'])
            ->when(! $this->showHistory, fn ($query) => $query->whereNull('ends_at'))
            ->when($this->search !== '', fn ($query) => $query->where(function ($query) {
                $query->whereHas('user', fn ($user) => $user
                    ->where('name', 'like', '%'.$this->search.'%')
                    ->orWhere('man_no', 'like', '%'.$this->search.'%'))
                    ->orWhereHas('office', fn ($office) => $office->where('name', 'like', '%'.$this->search.'%'));
            }))
            ->orderByDesc('starts_at')
            ->paginate(15);

        return view('livewire.pdts.organization.employee-assignments', [
            'assignments' => $assignments,
            'users' => User::query()->where('is_banned', false)->orderBy('name')->get(['id', 'name', 'man_no']),
            'offices' => PdtsOffice::query()->where('is_active', true)->with(['department.directorate', 'directorate'])->orderBy('name')->get(),
        ])->layout('layouts.master', ['title' => 'Employee Office Assignments']);
    }
}
