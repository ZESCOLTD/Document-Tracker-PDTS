<div>

    {{-- FLOATING BUTTON --}}
    <div style="position: fixed; bottom: 20px; right: 20px; z-index: 9999;">
        <button class="btn btn-primary rounded-circle shadow"
                style="width: 60px; height: 60px;"
                wire:click="toggleChat">

            <i class="fas fa-comments"></i>
        </button>
    </div>

    {{-- CHAT WINDOW --}}
    @if($isOpen)
        <div class="card shadow-lg"
             style="position: fixed; bottom: 90px; right: 20px; width: 350px; z-index: 9999;">

            {{-- HEADER --}}
            <div class="card-header bg-primary text-white d-flex justify-content-between">
                <span>Support Chat</span>
                <button class="btn btn-sm btn-light" wire:click="toggleChat">X</button>
            </div>

            {{-- MESSAGES --}}
            <div class="card-body"
                 style="height: 300px; overflow-y: auto; background: #f8f9fa;">

                @foreach($this->messages as $msg)

                    <div class="mb-2">

                        <div class="small text-muted">
                            {{ $msg->user->name ?? 'User' }}
                        </div>

                        <div class="p-2 bg-white rounded shadow-sm">
                            {!! nl2br(e($msg->message)) !!}
                        </div>

                        @foreach($msg->attachments as $file)
                            <a href="{{ Storage::url($file->file_path) }}"
                               target="_blank"
                               class="d-block small text-primary mt-1">
                                📎 {{ $file->file_name }}
                            </a>
                        @endforeach

                    </div>

                @endforeach

            </div>

            {{-- INPUT --}}
            <div class="card-footer">

                <textarea class="form-control mb-2"
                          wire:model="message"
                          placeholder="Describe your issue..."></textarea>

                <input type="file"
                       class="form-control mb-2"
                       wire:model="files"
                       multiple>

                <button class="btn btn-primary btn-block"
                        wire:click="sendMessage"
                        wire:loading.attr="disabled">

                    Send
                </button>

            </div>

        </div>
    @endif

</div>
