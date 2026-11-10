<?php

namespace App\Livewire\Pdts;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

class OcrWorkbench extends Component
{
    use WithFileUploads;

    public $document;

    public ?array $analysis = null;

    public string $uploadedFilename = '';

    protected function rules(): array
    {
        return [
            'document' => ['required', 'file', 'mimes:pdf,png,jpg,jpeg,tif,tiff', 'max:20480'],
        ];
    }

    public function analyze(): void
    {
        $this->validate();
        set_time_limit((int) config('services.pdts_ai.timeout', 180) + 15);

        $filename = basename($this->document->getClientOriginalName());
        $path = $this->document->store('ai-ocr', 'private');

        try {
            $response = Http::acceptJson()
                ->timeout(config('services.pdts_ai.timeout', 180))
                ->withHeaders([
                    'X-Pdts-Ai-Token' => config('services.pdts_ai.token'),
                ])
                ->attach('file', Storage::disk('private')->get($path), $filename)
                ->post(rtrim(config('services.pdts_ai.url'), '/').'/api/ocr');

            if ($response->failed()) {
                $this->addError('document', 'The OCR service could not process this file. Check that the document is readable and the OCR service is running.');
                return;
            }

            $this->analysis = $response->json();
            $this->uploadedFilename = $filename;
            $this->document = null;
        } catch (Throwable) {
            $this->addError('document', 'The OCR service is unavailable. Start the local AI service and try again.');
        } finally {
            Storage::disk('private')->delete($path);
        }
    }

    public function clearResults(): void
    {
        $this->reset(['document', 'analysis', 'uploadedFilename']);
        $this->resetValidation();
    }

    public function render()
    {
        return view('livewire.pdts.ocr-workbench')
            ->layout('layouts.master', ['title' => 'Document OCR Review']);
    }
}
