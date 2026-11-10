<?php

namespace App\Livewire\Pdts\Documents;

use App\Models\PdtsDocumentType;
use App\Services\Pdts\RegisterPdtsDocument;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithFileUploads;

class Registration extends Component
{
    use WithFileUploads;

    public string $title = '';
    public string $subject = '';
    public string $description = '';
    public string $originating_department = '';
    public string $originating_office = '';
    public string $document_type_id = '';
    public $attachment;

    protected function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'originating_department' => ['required', 'string', 'max:191'],
            'originating_office' => ['required', 'string', 'max:191'],
            'document_type_id' => [
                'required',
                'integer',
                Rule::exists('pdts_document_types', 'id')->where('is_active', true),
            ],
            'attachment' => ['required', 'file', 'mimes:pdf,png,jpg,jpeg,tif,tiff', 'max:20480'],
        ];
    }

    public function save(RegisterPdtsDocument $registration)
    {
        $validated = $this->validate();

        $document = $registration->handle([
            'title' => $validated['title'],
            'subject' => $validated['subject'],
            'description' => $validated['description'] ?: null,
            'document_type_id' => (int) $validated['document_type_id'],
            'originating_department' => $validated['originating_department'],
            'originating_office' => $validated['originating_office'],
        ], auth()->user(), $this->attachment);

        session()->flash('success', 'Document registered successfully. Write the tracking reference on the physical document.');

        return redirect()->route('pdts.documents.show', $document);
    }

    public function render()
    {
        return view('livewire.pdts.documents.registration', [
            'documentTypes' => PdtsDocumentType::query()->where('is_active', true)->orderBy('name')->get(),
        ])->layout('layouts.master', ['title' => 'Register Document']);
    }
}