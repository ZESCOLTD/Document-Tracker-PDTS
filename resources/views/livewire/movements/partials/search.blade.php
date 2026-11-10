<form wire:submit="applySearch" class="mb-2" role="search">
    <div class="input-group">
        <label for="movements-search" class="sr-only">Search by reference or subject</label>
        <input id="movements-search" type="search" class="form-control" style="min-width: 220px;"
               placeholder="Reference or subject" wire:model="searchInput">
        <div class="input-group-append">
            <button type="submit" class="btn btn-outline-secondary">
                <i class="fas fa-search mr-1"></i>Search
            </button>
            @if ($search !== '')
                <button type="button" class="btn btn-outline-secondary" wire:click="clearSearch" title="Clear search">
                    <i class="fas fa-times"></i><span class="sr-only">Clear search</span>
                </button>
            @endif
        </div>
    </div>
</form>
