<div>

    <!-- CHAT WINDOW -->
    <div style="height:400px; overflow-y:auto; background:#f8f9fa; padding:10px;">

        @foreach($messages->reverse() as $msg)

            <div class="mb-2">

                <div class="p-2 rounded"
                     style="background: {{ $msg->user_id == auth()->id() ? '#d1e7ff' : '#e9ecef' }};">

                    <small class="text-muted">
                        {{ $msg->user->name }}
                    </small>

                    <div>{!! nl2br(e($msg->message)) !!}</div>

                    @if($msg->file_path)
                        <a href="{{ asset('storage/'.$msg->file_path) }}"
                           target="_blank">
                            📎 {{ $msg->file_name }}
                        </a>
                    @endif

                </div>

            </div>

        @endforeach

    </div>

    <!-- INPUT -->
    <div class="mt-2">

        <textarea wire:model="message"
                  class="form-control"
                  placeholder="Describe your issue..."></textarea>

        <input type="file" wire:model="file" class="form-control mt-2">

        <button class="btn btn-primary mt-2 w-100"
                wire:click="sendMessage"
                wire:loading.attr="disabled">

            Send
        </button>

    </div>

</div>
