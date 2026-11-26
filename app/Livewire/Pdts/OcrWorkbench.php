<?php

namespace App\Livewire\Pdts;

use App\Services\Ai\DocumentOcrClient;
use App\Services\Ai\OcrTextFormatter;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use RuntimeException;

class OcrWorkbench extends Component
{
    use WithFileUploads;

    public $document;

    #[Locked]
    public ?array $analysis = null;

    #[Locked]
    public string $uploadedFilename = '';

    #[Locked]
    public ?string $analysisError = null;

    #[Locked]
    public array $formattedBlocks = [];

    #[Locked]
    public ?string $formattingError = null;

    public function updatedDocument(): void
    {
        $this->reset(['analysis', 'uploadedFilename', 'analysisError', 'formattedBlocks', 'formattingError']);
        $this->resetValidation('document');
    }

    protected function rules(): array
    {
        return [
            'document' => ['required', 'file', 'mimes:pdf,png,jpg,jpeg,tif,tiff', 'max:20480'],
        ];
    }

    public function analyze(DocumentOcrClient $ocr): void
    {
        abort_unless(Auth::user()?->can('ai.ocr.use'), 403);

        $this->reset(['analysis', 'uploadedFilename', 'analysisError', 'formattedBlocks', 'formattingError']);
        $this->validate();
        set_time_limit(max(30, (int) config('services.pdts_ai.timeout', 180) + 15));

        try {
            $this->analysis = $ocr->review($this->document);
            $this->uploadedFilename = basename($this->document->getClientOriginalName());
        } catch (RuntimeException $exception) {
            $this->analysisError = $exception->getMessage();
        }
    }

    public function formatText(OcrTextFormatter $formatter): void
    {
        abort_unless(Auth::user()?->can('ai.ocr.use'), 403);

        if ($this->analysis === null || $this->formattedBlocks !== []) {
            return;
        }

        $this->formattingError = null;
        set_time_limit(max(30, (int) config('services.azure_openai.timeout', 90) + 15));

        try {
            $this->formattedBlocks = $formatter->format($this->analysis['text']);
        } catch (RuntimeException $exception) {
            $this->formattingError = $exception->getMessage();
        }
    }

    public function clearResults(): void
    {
        $this->reset(['document', 'analysis', 'uploadedFilename', 'analysisError', 'formattedBlocks', 'formattingError']);
        $this->resetValidation();
    }

    public function render()
    {
        return view('livewire.pdts.ocr-workbench')
            ->layout('layouts.master', ['title' => 'Document OCR Review']);
    }
}
