{{--
    Pop-up form used by the Configurations pages.
    <x-pdts.form-modal title="Add office" submit="Add office" close="cancelEdit"> fields </x-pdts.form-modal>
    The page shows it only while its $showForm is true.
--}}
@props(['title', 'submit' => 'Save', 'close' => 'cancelEdit', 'action' => 'save'])

<div class="modal d-block" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="form-modal-title"
     style="background: rgba(0, 0, 0, .5); overflow-y: auto;"
     wire:keydown.escape.window="{{ $close }}">
    <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
        <div class="modal-content border-0 elevation-3">
            <form wire:submit.prevent="{{ $action }}">
                <div class="modal-header">
                    <h5 class="modal-title" id="form-modal-title">{{ $title }}</h5>
                    <button type="button" class="close" aria-label="Close" wire:click="{{ $close }}">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    {{ $slot }}
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" wire:click="{{ $close }}">Cancel</button>
                    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="{{ $action }}">{{ $submit }}</button>
                </div>
            </form>
        </div>
    </div>
</div>
