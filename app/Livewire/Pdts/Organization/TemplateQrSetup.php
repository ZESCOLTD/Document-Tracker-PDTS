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

    /** What PDTS found in the Word file (TemplateSetup::check): shown beside the preview. */
    #[Locked]
    public ?array $found = null;

    /** @var array{page: string, x: float, y: float, size: float} */
    public array $placement = [];

    public ?string $problem = null;

    /** The QR code page is for uploaded files: a built template's QR code is placed in the builder. */
    protected bool $forUploadsOnly = true;

    public function mount(PdtsDocumentTemplate $template, TemplateSetup $setup)
    {
        $this->templateId = $template->id;

        if ($this->forUploadsOnly && $template->isBuilt()) {
            if ($template->isSuperseded()) {
                return $this->redirectRoute('pdts.organization.document-templates.review', $template);
            }
            session()->flash('info', 'This template was built in PDTS: place its QR code here, in the builder: drag it on the page, or click it for its settings.');

            return $this->redirectRoute('pdts.organization.document-templates.edit-design', $template);
        }

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

            return;
        }

        // Not needed to place the QR code: if it cannot be worked out, the page still works.
        if (! $template->isPdf()) {
            try {
                $this->found = $setup->check($template);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    public function template(): PdtsDocumentTemplate
    {
        return PdtsDocumentTemplate::withCount('documents')->findOrFail($this->templateId);
    }

    public function save(array $placement, TemplateSetup $setup, array $marks = [])
    {
        $template = $this->template();
        abort_if($template->documents_count > 0, 403, 'Documents were made from this template.');
        abort_if($template->isBuilt(), 403, 'A built template\'s QR code is placed in the builder.');

        try {
            $setup->save($template, $placement, $marks);
        } catch (RuntimeException $e) {
            $this->addError('placement', $e->getMessage());

            return null;
        }

        session()->flash('success', 'Saved. Check how the form will look and try it, then the template is ready for authors.');

        return $this->redirectRoute('pdts.organization.document-templates.review', $template);
    }

    /** The template with a sample QR code and details, to check before saving. */
    public function downloadSample(array $placement, TemplateSetup $setup, array $marks = [])
    {
        try {
            $file = $setup->sample($this->template(), $placement, $marks);
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
            'marks' => (array) ($template->manual_marks ?? []),
        ])->layout('layouts.master', ['title' => 'Place the QR code · '.$template->name]);
    }
}
