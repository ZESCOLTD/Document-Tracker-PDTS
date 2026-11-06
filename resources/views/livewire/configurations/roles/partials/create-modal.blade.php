<div class="modal fade"
     id="createModal"
     tabindex="-1"
     wire:ignore.self>

    <div class="modal-dialog modal-xl modal-dialog-scrollable">

        <div class="modal-content border-0 shadow">

            <div class="modal-header">

                <div>

                    <h5 class="modal-title fw-bold">

                        <i class="bi bi-shield-plus text-primary me-2"></i>

                        New Role

                    </h5>

                    <small class="text-muted">

                        Create a new system role and assign permissions.

                    </small>

                </div>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal">
                </button>

            </div>

            <div class="modal-body">

                @include('livewire.configurations.roles.partials.form')

            </div>

            <div class="modal-footer">

                <button
                    class="btn btn-light"
                    data-bs-dismiss="modal">

                    Cancel

                </button>

                <button
                    class="btn btn-primary"
                    wire:click="save"
                    wire:loading.attr="disabled">

                    <span
                        wire:loading.remove
                        wire:target="save">

                        <i class="bi bi-check-circle me-2"></i>

                        Save Role

                    </span>

                    <span
                        wire:loading
                        wire:target="save">

                        <span class="spinner-border spinner-border-sm me-2"></span>

                        Saving...

                    </span>

                </button>

            </div>

        </div>

    </div>

</div>
