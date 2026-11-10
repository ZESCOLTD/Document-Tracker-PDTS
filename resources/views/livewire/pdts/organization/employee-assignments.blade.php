<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start mb-4">
        <div>
            <h1 class="h3 mb-1">Employee Office Assignments</h1>
            <p class="text-muted mb-0">Track current assignments and retain previous office history.</p>
        </div>
        <div class="btn-group" role="group" aria-label="Organizational management sections">
            <a href="{{ route('pdts.organization.departments') }}" class="btn btn-outline-secondary">Departments</a>
            <a href="{{ route('pdts.organization.offices') }}" class="btn btn-outline-secondary">Offices</a>
            <a href="{{ route('pdts.organization.assignments') }}" class="btn btn-primary">Employee Assignments</a>
        </div>
    </div>

    @if (session('success')) <div class="alert alert-success">{{ session('success') }}</div> @endif

    <section class="bg-white border rounded p-4 mb-4">
        <h2 class="h5 mb-3">Assign employee to office</h2>
        <form wire:submit.prevent="assign">
            <div class="form-row">
                <div class="form-group col-md-4">
                    <label for="assignment-user">Employee</label>
                    <select id="assignment-user" class="form-control" wire:model="userId" required>
                        <option value="">Select employee</option>
                        @foreach($users as $user)
                            <option value="{{ $user->id }}">{{ $user->name }}{{ $user->man_no ? ' · '.$user->man_no : '' }}</option>
                        @endforeach
                    </select>
                    @error('userId') <small class="text-danger">{{ $message }}</small> @enderror
                </div>
                <div class="form-group col-md-4">
                    <label for="assignment-office">Office</label>
                    <select id="assignment-office" class="form-control" wire:model="officeId" required>
                        <option value="">Select office</option>
                        @foreach($offices as $office)
                            <option value="{{ $office->id }}">{{ $office->name }} · {{ $office->department->name }}</option>
                        @endforeach
                    </select>
                    @error('officeId') <small class="text-danger">{{ $message }}</small> @enderror
                </div>
                <div class="form-group col-md-2">
                    <label for="assignment-position">Position <span class="text-muted">(optional)</span></label>
                    <input id="assignment-position" class="form-control" wire:model="position" maxlength="191">
                    @error('position') <small class="text-danger">{{ $message }}</small> @enderror
                </div>
                <div class="form-group col-md-2">
                    <label for="assignment-starts">Starts at</label>
                    <input id="assignment-starts" type="datetime-local" class="form-control" wire:model="startsAt" required>
                    @error('startsAt') <small class="text-danger">{{ $message }}</small> @enderror
                </div>
            </div>
            <div class="d-flex justify-content-between align-items-center">
                <div class="custom-control custom-switch">
                    <input id="assignment-primary" type="checkbox" class="custom-control-input" wire:model="isPrimary">
                    <label class="custom-control-label" for="assignment-primary">Primary office</label>
                </div>
                <button type="submit" class="btn btn-primary">Save assignment</button>
            </div>
        </form>
    </section>

    <section class="bg-white border rounded p-3">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
            <h2 class="h5 mb-2 mb-md-0">Assignments</h2>
            <div class="d-flex align-items-center">
                <input type="search" class="form-control mr-3" style="max-width: 280px" placeholder="Search employees or offices" wire:model.live.debounce.300ms="search">
                <div class="custom-control custom-switch text-nowrap">
                    <input id="show-history" type="checkbox" class="custom-control-input" wire:model.live="showHistory">
                    <label class="custom-control-label" for="show-history">Include history</label>
                </div>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr><th>Employee</th><th>Office</th><th>Department</th><th>Position</th><th>Primary</th><th>Period</th><th class="text-right">Actions</th></tr>
                </thead>
                <tbody>
                    @forelse($assignments as $assignment)
                        <tr>
                            <td>{{ $assignment->user->name }}<br><small class="text-muted">{{ $assignment->user->man_no }}</small></td>
                            <td>{{ $assignment->office->name }}</td>
                            <td>{{ $assignment->office->department->name }}</td>
                            <td>{{ $assignment->position ?: '—' }}</td>
                            <td>{{ $assignment->is_primary ? 'Yes' : 'No' }}</td>
                            <td>
                                {{ $assignment->starts_at->format('d M Y H:i') }}
                                <span class="text-muted">to</span>
                                {{ $assignment->ends_at?->format('d M Y H:i') ?? 'Current' }}
                            </td>
                            <td class="text-right">
                                @if(!$assignment->ends_at)
                                    <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="endAssignment({{ $assignment->id }})">End assignment</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-4">No assignments found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $assignments->links() }}</div>
    </section>
</div>
