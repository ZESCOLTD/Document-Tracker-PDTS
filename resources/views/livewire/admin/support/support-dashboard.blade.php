<div>

    <x-slot name="title">Support Center</x-slot>
    <x-slot name="pageHeader">Support Conversations</x-slot>

    <div class="container-fluid">

        <div class="row">

            {{-- LEFT PANEL : TICKETS --}}
            <div class="col-md-4">

                <div class="card border-0 shadow-sm h-100">

                    <div class="card-header bg-dark text-white">

                        <div class="d-flex justify-content-between align-items-center">

                            <div>
                                <h5 class="mb-0">
                                    Support Inbox
                                </h5>

                                <small class="text-light">
                                    Active user conversations
                                </small>
                            </div>

                            <span class="badge bg-warning text-dark">
                                {{ $tickets->count() }}
                            </span>

                        </div>

                    </div>

                    <div class="card-body p-0"
                         style="max-height: 80vh; overflow-y:auto;">

                        @forelse($tickets as $ticket)

                            <div class="border-bottom p-3 cursor-pointer
                                        {{ $selectedTicket?->id === $ticket->id ? 'bg-light' : '' }}"
                                 wire:click="selectTicket({{ $ticket->id }})"
                                 style="cursor:pointer;">

                                <div class="d-flex justify-content-between">

                                    <div>

                                        <div class="fw-bold text-dark">
                                            {{ $ticket->user->name ?? 'Unknown User' }}
                                        </div>

                                        <small class="text-muted">
                                            {{ $ticket->page_key }}
                                        </small>

                                    </div>

                                    <div class="text-end">

                                        @if($ticket->status === 'open')
                                            <span class="badge bg-success">
                                                OPEN
                                            </span>
                                        @elseif($ticket->status === 'closed')
                                            <span class="badge bg-secondary">
                                                CLOSED
                                            </span>
                                        @endif

                                        <div class="small text-muted mt-1">
                                            {{ $ticket->updated_at?->diffForHumans() }}
                                        </div>

                                    </div>

                                </div>

                                @if($ticket->latestMessage)

                                    <div class="small text-muted mt-2 text-truncate">
                                        {{ $ticket->latestMessage->message }}
                                    </div>

                                @endif

                            </div>

                        @empty

                            <div class="p-5 text-center text-muted">

                                <i class="fas fa-inbox fa-3x mb-3"></i>

                                <div>
                                    No support tickets
                                </div>

                            </div>

                        @endforelse

                    </div>

                </div>

            </div>

            {{-- RIGHT PANEL : CHAT --}}
            <div class="col-md-8">

                <div class="card border-0 shadow-sm">

                    {{-- HEADER --}}
                    <div class="card-header bg-primary text-white">

                        @if($selectedTicket)

                            <div class="d-flex justify-content-between align-items-center">

                                <div>

                                    <h5 class="mb-0">
                                        {{ $selectedTicket->user->name }}
                                    </h5>

                                    <small>
                                        {{ $selectedTicket->page_key }}
                                    </small>

                                </div>

                                <div>

                                    @if($selectedTicket->status === 'open')

                                        <button class="btn btn-sm btn-light"
                                                wire:click="closeTicket">

                                            Close Ticket

                                        </button>

                                    @else

                                        <button class="btn btn-sm btn-success"
                                                wire:click="reopenTicket">

                                            Reopen Ticket

                                        </button>

                                    @endif

                                </div>

                            </div>

                        @else

                            <h5 class="mb-0">
                                Select Conversation
                            </h5>

                        @endif

                    </div>

                    {{-- CHAT BODY --}}
                    <div class="card-body"
                         style="height:65vh; overflow-y:auto; background:#f4f6f9;">

                        @if($selectedTicket)

                            @forelse($messages as $message)

                                <div class="mb-3
                                            d-flex
                                            {{ $message->is_admin ? 'justify-content-end' : 'justify-content-start' }}">

                                    <div style="max-width:75%;">

                                        <div class="small text-muted mb-1">

                                            {{ $message->user->name ?? 'User' }}
                                            •
                                            {{ $message->created_at->format('d M Y H:i') }}

                                        </div>

                                        <div class="p-3 rounded shadow-sm
                                                    {{ $message->is_admin ? 'bg-primary text-white' : 'bg-white' }}">

                                            {!! nl2br(e($message->message)) !!}

                                        </div>

                                        {{-- ATTACHMENTS --}}
                                        @if($message->attachments->count())

                                            <div class="mt-2">

                                                @foreach($message->attachments as $file)

                                                    <a href="{{ Storage::url($file->file_path) }}"
                                                       target="_blank"
                                                       class="d-block small">

                                                        📎 {{ $file->file_name }}

                                                    </a>

                                                @endforeach

                                            </div>

                                        @endif

                                    </div>

                                </div>

                            @empty

                                <div class="text-center text-muted mt-5">
                                    No messages yet
                                </div>

                            @endforelse

                        @else

                            <div class="h-100 d-flex align-items-center justify-content-center text-muted">

                                <div class="text-center">

                                    <i class="fas fa-comments fa-4x mb-3"></i>

                                    <div>
                                        Select a support ticket to begin
                                    </div>

                                </div>

                            </div>

                        @endif

                    </div>

                    {{-- REPLY BOX --}}
                    @if($selectedTicket)

                        <div class="card-footer bg-white">

                            <div class="mb-2">

                                <textarea class="form-control"
                                          rows="3"
                                          wire:model="replyMessage"
                                          placeholder="Type your response..."></textarea>

                                @error('replyMessage')
                                <small class="text-danger">
                                    {{ $message }}
                                </small>
                                @enderror

                            </div>

                            {{-- FILES --}}
                            <div class="mb-3">

                                <input type="file"
                                       class="form-control"
                                       wire:model="attachments"
                                       multiple>

                                <div wire:loading
                                     wire:target="attachments"
                                     class="small text-primary mt-1">

                                    Uploading attachments...

                                </div>

                            </div>

                            {{-- ACTIONS --}}
                            <div class="d-flex justify-content-between align-items-center">

                                <div class="small text-muted">

                                    Ticket:
                                    #{{ $selectedTicket->id }}

                                </div>

                                <button class="btn btn-primary"
                                        wire:click="sendReply"
                                        wire:loading.attr="disabled">

                                    <span wire:loading.remove wire:target="sendReply">
                                        <i class="fas fa-paper-plane me-1"></i>
                                        Send Reply
                                    </span>

                                    <span wire:loading wire:target="sendReply">

                                        <span class="spinner-border spinner-border-sm"></span>

                                    </span>

                                </button>

                            </div>

                        </div>

                    @endif

                </div>

            </div>

        </div>

    </div>

</div>
