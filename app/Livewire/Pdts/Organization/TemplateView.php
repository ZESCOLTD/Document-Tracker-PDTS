<?php

namespace App\Livewire\Pdts\Organization;

use App\Services\Pdts\Templates\QrCodePng;

/**
 * Shows a template as it is used: the uploaded file with the QR code and
 * reference where they print, and a few facts: a quick look. Nothing can be
 * changed here; "Move QR code" opens the setup page.
 */
class TemplateView extends TemplateQrSetup
{
    /** Any template can be looked at, built ones too. */
    protected bool $forUploadsOnly = false;

    public function save(array $placement, $setup = null, array $marks = [])
    {
        abort(403);
    }

    public function render(QrCodePng $qr)
    {
        $template = $this->template();

        return view('livewire.pdts.organization.template-qr-setup', [
            'template' => $template,
            'viewOnly' => true,
            'locked' => true,
            'showPin' => $template->placement() !== null,
            'sampleQr' => 'data:image/png;base64,'.base64_encode($qr->make(rtrim((string) config('app.url'), '/').'/s/SAMPLE', 4, 2)),
            'originalUrl' => route('pdts.organization.document-templates.original', $template),
            'limits' => ['min' => 0, 'max' => 0],
        ])->layout('layouts.master', ['title' => $template->name.' · Template']);
    }
}
