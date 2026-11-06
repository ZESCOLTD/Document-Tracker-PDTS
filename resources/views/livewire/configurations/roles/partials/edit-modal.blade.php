<div class="modal fade"
     id="editModal"
     tabindex="-1"
     wire:ignore.self>

    <div class="modal-dialog modal-xl modal-dialog-scrollable">

        <div class="modal-content border-0 shadow">

            <div class="modal-header">

                <div>

                    <h5 class="modal-title fw-bold">

                        <i class="bi bi-pencil-square text-warning me-2"></i>

                        Edit Role

                    </h5>

                    <small class="text-muted">

                        Update role details and permissions.

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
                    class="btn btn-warning"
                    wire:click="update"
                    wire:loading.attr="disabled">

                    <span
                        wire:loading.remove
                        wire:target="update">

                        <i class="bi bi-check-circle me-2"></i>

                        Update Role

                    </span>

                    <span
                        wire:loading
                        wire:target="update">

                        <span class="spinner-border spinner-border-sm me-2"></span>

                        Updating...

                    </span>

                </button>

            </div>

        </div>

    </div>

</div>
