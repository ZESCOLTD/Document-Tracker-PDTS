<?php

namespace App\Livewire\Pdts\Organization;

use App\Services\Pdts\Templates\QrCodePng;
use App\Services\Pdts\Templates\TemplateSetup;

/**
 * Shows a template as it is used: the uploaded file with the QR code and
 * reference where they print, and what the New Internal Document form asks
 * for. Nothing can be changed here; "Move QR code" opens the setup page.
 */
class TemplateView extends TemplateQrSetup
{
    public function save(array $placement, $setup = null)
    {
        abort(403);
    }

    /** The template with a sample QR code and details, where the saved placement puts them. */
    public function downloadSavedSample(TemplateSetup $setup)
    {
        $placement = $this->template()->placement();
        abort_if($placement === null, 404);

        return $this->downloadSample($placement, $setup);
    }

    public function render(QrCodePng $qr)
    {
        $template = $this->template();
        $labels = ['to' => 'To', 'from' => 'From', 'cc' => 'CC', 'office_reference' => 'Office file reference', 'date' => 'Date', 'subject' => 'Subject'];
        $custom = $template->customFields();

        $formFields = [];
        foreach ($template->placeholderNames() as $name) {
            if (isset($labels[$name])) {
                $formFields[] = $labels[$name];
            } elseif (isset($custom[$name])) {
                $formFields[] = $custom[$name];
            }
        }

        return view('livewire.pdts.organization.template-qr-setup', [
            'template' => $template,
            'viewOnly' => true,
            'locked' => true,
            'showPin' => $template->placement() !== null,
            'formFields' => array_values(array_unique($formFields)),
            'sampleQr' => 'data:image/png;base64,'.base64_encode($qr->make(rtrim((string) config('app.url'), '/').'/s/SAMPLE', 4, 2)),
            'originalUrl' => route('pdts.organization.document-templates.original', $template),
            'limits' => ['min' => 0, 'max' => 0],
        ])->layout('layouts.master', ['title' => $template->name.' · Template']);
    }
}
