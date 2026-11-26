<?php

namespace App\Livewire\Pdts\Organization;

use App\Models\PdtsDocumentTemplate;
use App\Services\Pdts\Signatures\PdfConverter;
use App\Services\Pdts\Templates\TemplateSetup;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;
use Throwable;

/**
 * After a template is saved: what PDTS understood in it (the check), how its
 * fields appear on the New Internal Document form (label, type, required,
 * auto-fill, help, hidden), and "Try it": the exact PDF a document would be,
 * with sample details.
 */
class TemplateReview extends Component
{
    #[Locked]
    public int $templateId;

    /** name => {label, type, required, auto, help, choices, hidden} as edited on the page */
    public array $settings = [];

    public function mount(PdtsDocumentTemplate $template): void
    {
        $this->templateId = $template->id;
        $this->loadSettings();
    }

    public function template(): PdtsDocumentTemplate
    {
        return PdtsDocumentTemplate::with(['documentType', 'previousVersion'])->withCount('documents')->findOrFail($this->templateId);
    }

    private function loadSettings(): void
    {
        $template = $this->template();
        $this->settings = [];
        foreach ($this->fieldNames($template) as $name) {
            $setting = $template->fieldSetting($name);
            $this->settings[$name] = [
                'label' => (string) ($setting['label'] ?? ''),
                'type' => $setting['type'],
                'required' => $setting['required'] === null ? '' : ($setting['required'] ? '1' : '0'),
                'auto' => $setting['auto'],
                'help' => $setting['help'],
                'choices' => implode(', ', $setting['choices']),
                'columns' => $setting['columns'],
                'hidden' => $setting['hidden'],
            ];
        }
    }

    /** @return list<string> the template's fields shown on the form, in document order */
    private function fieldNames(PdtsDocumentTemplate $template): array
    {
        $headings = PdtsDocumentTemplate::HEADING_FIELDS;
        $custom = array_keys($template->customFields());

        return array_values(array_filter($template->placeholderNames(), fn ($name) => in_array($name, $headings, true) || in_array($name, $custom, true)));
    }

    public function saveSettings(): void
    {
        $this->resetErrorBag();
        $template = $this->template();
        $names = $this->fieldNames($template);
        $clean = [];

        foreach ($names as $name) {
            $row = (array) ($this->settings[$name] ?? []);
            // A table (from the file's grid) stays a table with its columns.
            $table = $template->fieldSetting($name)['type'] === 'table';
            $type = $table ? 'table' : (array_key_exists($row['type'] ?? '', PdtsDocumentTemplate::FIELD_TYPES) ? $row['type'] : 'text');
            $choices = array_values(array_filter(array_map('trim', explode(',', (string) ($row['choices'] ?? ''))), fn ($c) => $c !== ''));
            if ($type === 'choice' && $choices === []) {
                $this->addError('settings.'.$name.'.choices', 'Give the choices, separated by commas.');

                return;
            }

            $clean[$name] = array_filter([
                'label' => mb_substr(trim((string) ($row['label'] ?? '')), 0, 80) ?: null,
                'type' => $type,
                'required' => ($row['required'] ?? '') === '' ? null : ($row['required'] === '1'),
                'auto' => array_key_exists($row['auto'] ?? '', PdtsDocumentTemplate::AUTO_FILL) ? $row['auto'] : 'none',
                'help' => mb_substr(trim((string) ($row['help'] ?? '')), 0, 200) ?: null,
                'choices' => $type === 'choice' ? $choices : null,
                'columns' => $table ? $template->fieldSetting($name)['columns'] : null,
                'hidden' => (bool) ($row['hidden'] ?? false) ?: null,
            ], fn ($v) => $v !== null);
        }

        $template->update(['field_settings' => $clean ?: null]);
        $this->loadSettings();
        session()->flash('success', 'Form settings saved. The New Internal Document form now uses them.');
    }

    /** Documents from this template are not signed (a form, notice or cover sheet), or undo it. */
    public function setUnsigned(TemplateSetup $setup, bool $unsigned): void
    {
        try {
            $offered = $setup->markUnsigned($this->template(), $unsigned);
        } catch (RuntimeException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        session()->flash('success', match (true) {
            $unsigned && $offered => 'Marked as not signed. Authors can now use it; documents from it are issued in PDTS without a signature.',
            $unsigned => 'Marked as not signed. Place the QR code to offer it to authors.',
            default => 'No longer marked as not signed. Authors are not offered it until the signature line is marked.',
        });
    }

    public function render(TemplateSetup $setup, PdfConverter $converter)
    {
        $template = $this->template();

        try {
            $check = $setup->check($template);
            $problem = null;
        } catch (Throwable $e) {
            report($e);
            $check = null;
            $problem = $e instanceof RuntimeException ? $e->getMessage() : 'This template could not be read.';
        }

        $labels = ['to' => 'To', 'from' => 'From', 'cc' => 'CC', 'office_reference' => 'Office reference', 'date' => 'Date'];

        return view('livewire.pdts.organization.template-review', [
            'template' => $template,
            'check' => $check,
            'problem' => $problem,
            'fieldNames' => $this->fieldNames($template),
            // The template's own names for its lines where it has them (e.g. "Ref" from the builder's REF).
            'foundLabels' => collect($labels)->map(fn ($label, $name) => $template->fieldSetting($name)['label'] ?? $label)->all() + $template->customFields(),
            'canTry' => ! $template->isPdf() && $template->isConfigured() && $converter->available(),
            'canMark' => $template->documents_count === 0,
            'signingBlocker' => $template->signingBlocker(),
        ])->layout('layouts.master', ['title' => 'Review · '.$template->name]);
    }
}
