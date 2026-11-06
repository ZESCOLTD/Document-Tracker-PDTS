<div class="container-fluid py-3">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
        <div>
            <h3 class="font-weight-bold mb-1">Notification Audit Log</h3>
            <p class="text-muted mb-0">Every notification dispatch (mail + database channels) is recorded here.</p>
        </div>
    </div>

    @if (session('success')) <div class="alert alert-success">{{ session('success') }}</div> @endif
    @if (session('error'))   <div class="alert alert-danger">{{ session('error') }}</div>   @endif

    <div class="row mb-3">
        <div class="col-md-3"><div class="small-box bg-info"><div class="inner"><h3>{{ $stats['total'] }}</h3><p>Total</p></div></div></div>
        <div class="col-md-3"><div class="small-box bg-success"><div class="inner"><h3>{{ $stats['sent'] }}</h3><p>Sent</p></div></div></div>
        <div class="col-md-3"><div class="small-box bg-danger"><div class="inner"><h3>{{ $stats['failed'] }}</h3><p>Failed</p></div></div></div>
        <div class="col-md-3"><div class="small-box bg-warning"><div class="inner"><h3>{{ $stats['today'] }}</h3><p>Today</p></div></div></div>
    </div>

    <div class="card">
        <div class="card-header">
            <div class="row g-2 align-items-end">
                <div class="col-md-5">
                    <label class="form-label small mb-1">Search</label>
                    <input type="text" class="form-control form-control-sm" wire:model.live.debounce.400ms="search"
                           placeholder="email, subject, or notification class...">
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-1">Status</label>
                    <select class="form-control form-control-sm" wire:model.live="status">
                        <option value="">All</option>
                        <option value="sent">Sent</option>
                        <option value="failed">Failed</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-1">Channel</label>
                    <select class="form-control form-control-sm" wire:model.live="channel">
                        <option value="">All</option>
                        <option value="mail">Mail</option>
                        <option value="database">Database (bell)</option>
                    </select>
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-striped mb-0">
                    <thead class="thead-light">
                        <tr>
                            <th>#</th>
                            <th>When</th>
                            <th>Recipient</th>
                            <th>Notification</th>
                            <th>Channel</th>
                            <th>Status</th>
                            <th>Subject</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($rows as $r)
                            <tr class="{{ $r->status === 'failed' ? 'table-danger' : '' }}">
                                <td>{{ $r->id }}</td>
                                <td><span title="{{ $r->created_at }}">{{ $r->created_at?->diffForHumans() }}</span></td>
                                <td>{{ $r->email ?? '—' }}</td>
                                <td><code>{{ $r->shortClass() }}</code></td>
                                <td><span class="badge badge-secondary">{{ $r->channel }}</span></td>
                                <td>
                                    @if($r->status === 'sent')
                                        <span class="badge badge-success">sent</span>
                                    @else
                                        <span class="badge badge-danger" title="{{ $r->error }}">failed</span>
                                    @endif
                                </td>
                                <td>{{ \Illuminate\Support\Str::limit($r->subject ?? '', 60) }}</td>
                                <td class="text-right">
                                    @if($r->channel === 'mail' && $r->user_id)
                                        <button class="btn btn-xs btn-outline-primary"
                                                wire:click="resend({{ $r->id }})"
                                                wire:confirm="Resend a courtesy notice to {{ $r->email }}?">
                                            <i class="fas fa-redo"></i> Resend
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="text-center text-muted py-4">No entries.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer">
            {{ $rows->links() }}
        </div>
    </div>
</div>
