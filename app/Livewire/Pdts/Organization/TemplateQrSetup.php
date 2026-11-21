<?php

namespace App\Livewire\Pdts\Organization;

use App\Models\PdtsDocumentTemplate;
use App\Services\Pdts\Templates\QrCodePng;
use App\Services\Pdts\Templates\TemplateSetup;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;
use Throwable;

/**
 * Step 2 of adding a template: the uploaded file is shown as it is, the
 * administrator drags the QR code to a free spot, chooses its page and size,
 * checks a sample and saves. The preview itself is drawn in the browser
 * (docx-preview for Word, pdf.js for PDF).
 */
class TemplateQrSetup extends Component
{
    #[Locked]
    public int $templateId;

    /** @var list<array{width: float, height: float}> page sizes in mm */
    #[Locked]
    public array $pages = [];

    /** @var list<string> heading lines PDTS found (Word) */
    #[Locked]
    public array $fields = [];

    #[Locked]
    public bool $qrTyped = false;

    /** @var array{page: string, x: float, y: float, size: float} */
    public array $placement = [];

    public ?string $problem = null;

    public function mount(PdtsDocumentTemplate $template, TemplateSetup $setup): void
    {
        $this->templateId = $template->id;

        try {
            $info = $setup->inspect($template);
            $this->pages = $info['pages'];
            $this->fields = $info['fields'];
            $this->qrTyped = $info['qrTyped'];
            $this->placement = $template->placement() ?? $setup->defaultPlacement($this->pages[0]);
            $this->placement['page'] = (string) $this->placement['page'];
        } catch (Throwable $e) {
            report($e);
            $this->problem = $e instanceof RuntimeException ? $e->getMessage() : 'This template could not be read.';
        }
    }

    public function template(): PdtsDocumentTemplate
    {
        return PdtsDocumentTemplate::withCount('documents')->findOrFail($this->templateId);
    }

    public function save(array $placement, TemplateSetup $setup)
    {
        $template = $this->template();
        abort_if($template->documents_count > 0, 403, 'Documents were made from this template.');

        try {
            $setup->save($template, $placement);
        } catch (RuntimeException $e) {
            $this->addError('placement', $e->getMessage());

            return null;
        }

        session()->flash('success', "Template \"{$template->name}\" successfully registered. Authors can now choose it on New Internal Document.");

        return $this->redirectRoute('pdts.organization.document-templates');
    }

    /** The template with a sample QR code and details, to check before saving. */
    public function downloadSample(array $placement, TemplateSetup $setup)
    {
        try {
            $file = $setup->sample($this->template(), $placement);
        } catch (RuntimeException $e) {
            $this->addError('placement', $e->getMessage());

            return null;
        }

        return response()->streamDownload(fn () => print ($file['contents']), $file['filename'], ['Content-Type' => $file['mime']]);
    }

    public function render(QrCodePng $qr)
    {
        $template = $this->template();

        return view('livewire.pdts.organization.template-qr-setup', [
            'template' => $template,
            'locked' => $template->documents_count > 0,
            'sampleQr' => 'data:image/png;base64,'.base64_encode($qr->make(rtrim((string) config('app.url'), '/').'/s/SAMPLE', 4, 2)),
            'originalUrl' => route('pdts.organization.document-templates.original', $template),
            'limits' => ['min' => TemplateSetup::MIN_SIZE_MM, 'max' => TemplateSetup::MAX_SIZE_MM],
        ])->layout('layouts.master', ['title' => 'Place the QR code · '.$template->name]);
    }
}
