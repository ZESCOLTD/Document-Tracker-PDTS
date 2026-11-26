<?php

namespace App\Livewire\Pdts\Documents;

use App\Models\PdtsDocument;
use App\Models\PdtsDocumentType;
use App\Services\Documents\DocumentAccess;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Closed documents: completed (with where they are filed and how long they
 * are kept) and cancelled ones. "Due for review" lists completed documents
 * whose retention period has passed, for Registry to review. Nothing is
 * deleted automatically.
 */
class Archive extends Component
{
    use WithPagination;

    protected string $paginationTheme = 'bootstrap';

    #[Url]
    public string $view = 'completed';

    #[Url]
    public string $search = '';

    #[Url]
    public string $typeId = '';

    public function updating($name): void
    {
        if (in_array($name, ['view', 'search', 'typeId'], true)) {
            $this->resetPage();
        }
    }

    private function base(): Builder
    {
        $search = trim($this->search);

        // Only the documents the user is involved in (everything, for Management / Auditor and administrators).
        return app(DocumentAccess::class)->visibleTo(PdtsDocument::query(), auth()->user())
            ->with(['documentType', 'registrar', 'canceller'])
            ->when($this->typeId !== '', fn ($q) => $q->where('document_type_id', (int) $this->typeId))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('reference_number', 'like', "%{$search}%")
                ->orWhere('subject', 'like', "%{$search}%")
                ->orWhere('file_reference', 'like', "%{$search}%")));
    }

    /** Completed documents whose type's retention period has passed. */
    public static function dueForReview(Builder $query): Builder
    {
        return $query->where('status', 'completed')->whereNotNull('completed_at')
            ->whereHas('documentType', fn ($t) => $t->whereNotNull('retention_years'))
            ->get(['id', 'completed_at', 'document_type_id'])
            ->filter(fn (PdtsDocument $d) => $d->retainUntil()?->isPast())
            ->pipe(fn ($due) => PdtsDocument::query()->whereKey($due->pluck('id')->all()));
    }

    public function render()
    {
        $query = match ($this->view) {
            'cancelled' => $this->base()->where('status', 'cancelled')->latest('cancelled_at'),
            'review' => self::dueForReview($this->base())->with(['documentType', 'registrar'])->orderBy('completed_at'),
            default => $this->base()->where('status', 'completed')->latest('completed_at'),
        };

        $visible = fn () => app(DocumentAccess::class)->visibleTo(PdtsDocument::query(), auth()->user());

        return view('livewire.pdts.documents.archive', [
            'documents' => $query->paginate(20),
            'counts' => [
                'completed' => $visible()->where('status', 'completed')->count(),
                'review' => self::dueForReview($visible())->count(),
                'cancelled' => $visible()->where('status', 'cancelled')->count(),
            ],
            'types' => PdtsDocumentType::query()->orderBy('name')->get(['id', 'name', 'retention_years']),
        ])->layout('layouts.master', ['title' => 'Archive']);
    }
}
