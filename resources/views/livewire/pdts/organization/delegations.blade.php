<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start mb-4">
        <div>
            <h1 class="h3 mb-1">Acknowledgement Delegations</h1>
            <p class="text-muted mb-0">Authorize an employee to acknowledge receipt on another employee’s behalf.</p>
        </div>
        <div class="btn-group" role="group" aria-label="Organizational management sections">
            <a href="{{ route('pdts.organization.directorates') }}" class="btn btn-outline-secondary">Directorates</a>
            <a href="{{ route('pdts.organization.departments') }}" class="btn btn-outline-secondary">Departments</a>
            <a href="{{ route('pdts.organization.offices') }}" class="btn btn-outline-secondary">Offices</a>
            <a href="{{ route('pdts.organization.assignments') }}" class="btn btn-outline-secondary">Employee Assignments</a>
            <a href="{{ route('pdts.organization.delegations') }}" class="btn btn-primary">Delegations</a>
        </div>
    </div>

    @if (session('success')) <div class="alert alert-success">{{ session('success') }}</div> @endif

    <section class="bg-white border rounded p-4 mb-4">
        <h2 class="h5 mb-3">Grant acknowledgement delegation</h2>
        <form wire:submit.prevent="grant">
            <div class="form-row">
                <div class="form-group col-md-4">
                    <label for="delegation-principal">Intended recipient</label>
                    <select id="delegation-principal" class="form-control" wire:model="principalUserId" required>
                        <option value="">Select employee</option>
                        @foreach($users as $user)
                            <option value="{{ $user->id }}">{{ $user->name }}{{ $user->man_no ? ' · '.$user->man_no : '' }}</option>
                        @endforeach
                    </select>
                    @error('principalUserId') <small class="text-danger">{{ $message }}</small> @enderror
                </div>
                <div class="form-group col-md-4">
                    <label for="delegation-delegate">Authorized delegate</label>
                    <select id="delegation-delegate" class="form-control" wire:model="delegateUserId" required>
                        <option value="">Select delegate</option>
                        @foreach($users as $user)
                            <option value="{{ $user->id }}">{{ $user->name }}{{ $user->man_no ? ' · '.$user->man_no : '' }}</option>
                        @endforeach
                    </select>
                    @error('delegateUserId') <small class="text-danger">{{ $message }}</small> @enderror
                </div>
                <div class="form-group col-md-2">
                    <label for="delegation-starts">Starts at</label>
                    <input id="delegation-starts" type="datetime-local" class="form-control" wire:model="startsAt" required>
                    @error('startsAt') <small class="text-danger">{{ $message }}</small> @enderror
                </div>
                <div class="form-group col-md-2">
                    <label for="delegation-expires">Expires at <span class="text-muted">(optional)</span></label>
                    <input id="delegation-expires" type="datetime-local" class="form-control" wire:model="expiresAt">
                    @error('expiresAt') <small class="text-danger">{{ $message }}</small> @enderror
                </div>
            </div>
            <div class="form-row align-items-end">
                <div class="form-group col-md-10">
                    <label for="delegation-reason">Reason <span class="text-muted">(optional)</span></label>
                    <input id="delegation-reason" type="text" class="form-control" maxlength="500" wire:model="reason">
                    @error('reason') <small class="text-danger">{{ $message }}</small> @enderror
                </div>
                <div class="form-group col-md-2 text-right mb-3">
                    <button type="submit" class="btn btn-primary">Grant delegation</button>
                </div>
            </div>
        </form>
    </section>

    <section class="bg-white border rounded p-3">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
            <h2 class="h5 mb-2 mb-md-0">Delegations</h2>
            <div class="d-flex align-items-center">
                <input type="search" class="form-control mr-3" style="max-width: 280px" placeholder="Search people" wire:model.live.debounce.300ms="search">
                <div class="custom-control custom-switch text-nowrap">
                    <input id="delegation-history" type="checkbox" class="custom-control-input" wire:model.live="showHistory">
                    <label class="custom-control-label" for="delegation-history">Include revoked</label>
                </div>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr><th>Recipient</th><th>Delegate</th><th>Valid period</th><th>Granted by</th><th>Reason</th><th>Status</th><th class="text-right">Actions</th></tr>
                </thead>
                <tbody>
                    @forelse($delegations as $delegation)
                        @php($active = !$delegation->revoked_at && $delegation->starts_at->lte(now()) && (!$delegation->expires_at || $delegation->expires_at->gt(now())))
                        <tr>
                            <td>{{ $delegation->principal->name }}</td>
                            <td>{{ $delegation->delegate->name }}</td>
                            <td>{{ $delegation->starts_at->format('d M Y H:i') }}<span class="text-muted"> to </span>{{ $delegation->expires_at?->format('d M Y H:i') ?? 'No expiry' }}</td>
                            <td>{{ $delegation->granter->name }}</td>
                            <td>{{ $delegation->reason ?: '—' }}</td>
                            <td><span class="badge {{ $delegation->revoked_at ? 'badge-secondary' : ($active ? 'badge-success' : 'badge-light') }}">{{ $delegation->revoked_at ? 'Revoked' : ($active ? 'Active' : 'Scheduled / Expired') }}</span></td>
                            <td class="text-right">
                                @if(!$delegation->revoked_at)
                                    <button type="button" class="btn btn-sm btn-outline-danger" wire:click="revoke({{ $delegation->id }})">Revoke</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-4">No delegations found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $delegations->links() }}</div>
    </section>
</div>
