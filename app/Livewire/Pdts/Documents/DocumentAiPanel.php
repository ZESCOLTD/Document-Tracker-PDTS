<?php

namespace App\Livewire\Pdts\Documents;

use App\Models\PdtsDocument;
use App\Models\PdtsDocumentAttachment;
use App\Services\Ai\DocumentConversation;
use App\Services\Ai\DocumentOcrClient;
use App\Services\Ai\DocumentTrackingContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;

class DocumentAiPanel extends Component
{
    #[Locked]
    public int $documentId;

    #[Locked]
    public array $source = [];

    #[Locked]
    public array $summary = [];

    #[Locked]
    public array $messages = [];

    #[Locked]
    public ?string $fingerprint = null;

    #[Locked]
    public string $scanName = '';

    #[Locked]
    public ?string $analysisError = null;

    public string $question = '';

    public function mount(PdtsDocument $document): void
    {
        $this->authorizeAccess();
        $this->documentId = (int) $document->id;
    }

    public function analyse(DocumentOcrClient $ocr, DocumentConversation $conversation): void
    {
        $this->authorizeAccess();
        $this->analysisError = null;
        try {
            $file = $this->currentFile();
            if ($this->summary !== []) {
                return;
            }
            set_time_limit(max(30, (int) config('services.pdts_ai.timeout', 180) + (int) config('services.azure_openai.timeout', 90) + 15));
            if ($this->source === []) {
                $this->source = $conversation->source($ocr->review($file));
            }
            $this->summary = $conversation->summarize($this->source);
        } catch (RuntimeException $exception) {
            $this->analysisError = $exception->getMessage();
        }
    }

    public function ask(DocumentConversation $conversation): void
    {
        $this->authorizeAccess();
        $this->validate(['question' => ['required', 'string', 'max:1000']]);
        $this->analysisError = null;
        try {
            $this->currentFile();
            if ($this->summary === [] || $this->source === []) {
                throw new RuntimeException('Analyse the current scan before asking a question. The scan may have changed.');
            }
            if (count($this->messages) >= 10) {
                throw new RuntimeException('This conversation has reached five questions. Clear the chat to continue with the same scan.');
            }
            set_time_limit(max(30, (int) config('services.azure_openai.timeout', 90) + 15));
            $tracking = $this->trackingContext();
            $history = ($tracking['available'] ?? false)
                ? array_map(fn ($message) => ['role' => $message['role'], 'content' => $message['text']], $this->messages)
                : [];
            $answer = $conversation->answer($this->source, $history, trim($this->question), $tracking);
            $this->messages[] = ['role' => 'user', 'text' => trim($this->question), 'pages' => []];
            $this->messages[] = ['role' => 'assistant', ...$answer, 'tracking_as_of' => $tracking['as_of'] ?? null];
            $this->question = '';
        } catch (RuntimeException $exception) {
            $this->analysisError = $exception->getMessage();
        }
    }

    public function clearChat(): void
    {
        $this->authorizeAccess();
        $this->reset(['messages', 'question', 'analysisError']);
        $this->resetValidation('question');
    }

    private function authorizeAccess(): void
    {
        abort_unless(Auth::user()?->can('documents.view') && Auth::user()?->can('ai.ocr.use'), 403);
    }

    protected function findScan(): ?PdtsDocumentAttachment
    {
        return PdtsDocument::findOrFail($this->documentId)->primaryAttachment;
    }

    protected function trackingContext(): array
    {
        return app(DocumentTrackingContext::class)->forDocument(PdtsDocument::findOrFail($this->documentId), Auth::user());
    }

    protected function readScan(PdtsDocumentAttachment $scan): UploadedFile
    {
        $disk = Storage::disk('private');
        if (! $disk->exists($scan->storage_path)) {
            throw new RuntimeException('The primary scanned copy is unavailable.');
        }
        $path = $disk->path($scan->storage_path);
        if (! is_file($path) || ! is_readable($path) || filesize($path) > 20 * 1024 * 1024) {
            throw new RuntimeException('The primary scan must be readable and no larger than 20 MB.');
        }

        return new UploadedFile($path, $scan->original_filename, $scan->mime_type, null, true);
    }

    private function currentFile(): UploadedFile
    {
        $scan = $this->findScan();
        if (! $scan) {
            $this->reset(['source', 'summary', 'messages', 'fingerprint', 'scanName']);
            throw new RuntimeException('This document has no primary scanned copy to analyse.');
        }
        $file = $this->readScan($scan);
        $digest = hash_file('sha256', $file->getPathname());
        if ($digest === false) {
            throw new RuntimeException('The primary scanned copy could not be read.');
        }
        $fingerprint = hash('sha256', $this->documentId.'|'.$scan->id.'|'.$scan->storage_path.'|'.$digest);
        if ($this->fingerprint !== $fingerprint) {
            $this->reset(['source', 'summary', 'messages']);
        }
        $this->fingerprint = $fingerprint;
        $this->scanName = $scan->original_filename;

        return $file;
    }

    public function render()
    {
        $this->authorizeAccess();

        $document = PdtsDocument::with('primaryAttachment')->findOrFail($this->documentId);

        return view('livewire.pdts.documents.document-analysis', [
            'document' => $document,
            'scan' => $document->primaryAttachment,
            'tracking' => app(DocumentTrackingContext::class)->forDocument($document, Auth::user()),
        ])->layout('layouts.master', ['title' => 'AI Analysis - '.$document->reference_number]);
    }
}
