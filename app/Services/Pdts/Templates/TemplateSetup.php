<?php

namespace App\Services\Pdts\Templates;

use App\Models\PdtsDocumentTemplate;
use App\Services\Pdts\Signatures\PdfConverter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Preparing an uploaded template: the original stays as uploaded; the
 * administrator places the QR code on a preview; saving keeps that placement
 * and makes the prepared file documents are generated from.
 */
class TemplateSetup
{
    public const MIN_SIZE_MM = 15.0;

    public const MAX_SIZE_MM = 45.0;

    public const DEFAULT_SIZE_MM = 22.0;

    public function __construct(
        private readonly TemplatePreparer $preparer,
        private readonly DocxTemplateFiller $filler,
        private readonly PdfQrStamper $stamper,
        private readonly QrCodePng $qr,
    ) {}

    /**
     * Stores a newly uploaded file as it is.
     *
     * @return array{kind: string, path: string, page_count: ?int}
     */
    public function storeOriginal(string $uploadedPath, string $extension): array
    {
        $kind = strtolower($extension) === 'pdf' ? 'pdf' : 'docx';
        $bytes = (string) file_get_contents($uploadedPath);

        // Make sure it can be read before keeping it.
        $pageCount = null;
        if ($kind === 'pdf') {
            $pageCount = count($this->stamper->pages($bytes));
        } else {
            DocxPackage::read($uploadedPath);
        }

        $path = 'pdts/templates/originals/'.Str::random(40).'.'.$kind;
        Storage::disk('private')->put($path, $bytes);

        return ['kind' => $kind, 'path' => $path, 'page_count' => $pageCount];
    }

    /**
     * Page sizes (mm) for the preview and, for Word, the fields PDTS found.
     *
     * @return array{pages: list<array{width: float, height: float}>, fields: list<string>, qrTyped: bool}
     */
    public function inspect(PdtsDocumentTemplate $template): array
    {
        $path = $this->originalPath($template);

        if ($template->isPdf()) {
            return ['pages' => $this->stamper->pages((string) file_get_contents($path)), 'fields' => [], 'qrTyped' => false];
        }

        $prepared = $this->preparer->prepare($path, $this->defaultPlacement($this->preparer->pageSize($path)), (array) ($template->manual_marks ?? []), built: $template->isBuilt());

        return [
            'pages' => [$this->preparer->pageSize($path)],
            'fields' => $prepared['fields'],
            'qrTyped' => $prepared['qr'] === 'replaced',
        ];
    }

    /** Top right of the page, 10 mm in from the edges. */
    public function defaultPlacement(array $page): array
    {
        return [
            'page' => 'first',
            'x' => round($page['width'] - 10 - self::DEFAULT_SIZE_MM, 1),
            'y' => 10.0,
            'size' => self::DEFAULT_SIZE_MM,
        ];
    }

    /**
     * Checks a placement from the preview and keeps it inside the page.
     *
     * @return array{page: string, x: float, y: float, size: float}
     */
    public function normalise(PdtsDocumentTemplate $template, array $placement, array $pages): array
    {
        $page = (string) ($placement['page'] ?? 'all');
        $allowed = $template->isPdf()
            ? ['all', 'first', ...array_map('strval', range(1, max(1, count($pages))))]
            : ['all', 'first'];
        if (! in_array($page, $allowed, true)) {
            throw new RuntimeException('Choose a page for the QR code.');
        }

        $size = (float) ($placement['size'] ?? self::DEFAULT_SIZE_MM);
        if ($size < self::MIN_SIZE_MM || $size > self::MAX_SIZE_MM) {
            throw new RuntimeException('The QR code must be between '.(int) self::MIN_SIZE_MM.' and '.(int) self::MAX_SIZE_MM.' mm.');
        }

        $sheet = $pages[ctype_digit($page) ? (int) $page - 1 : 0] ?? $pages[0];
        $x = min(max(0.0, (float) ($placement['x'] ?? 0)), max(0.0, $sheet['width'] - $size));
        $y = min(max(0.0, (float) ($placement['y'] ?? 0)), max(0.0, $sheet['height'] - $size));

        return ['page' => $page, 'x' => round($x, 1), 'y' => round($y, 1), 'size' => round($size, 1)];
    }

    /**
     * Saves the placement and the prepared file, and makes the template
     * available to authors.
     */
    /**
     * Offers a saved template to authors when nothing about signing stops it,
     * and retires the version before it; otherwise it stays off, and the
     * version before stays offered. Returns whether it is now offered.
     */
    public function activateIfReady(PdtsDocumentTemplate $template): bool
    {
        $ready = $template->isConfigured() && $template->signingBlocker() === null;
        $template->forceFill(['is_active' => $ready])->save();

        // A new version takes over from the one before; documents made from that one keep it.
        if ($ready && $template->previous_version_id) {
            PdtsDocumentTemplate::query()->whereKey($template->previous_version_id)->whereNull('superseded_at')
                ->update(['is_active' => false, 'superseded_at' => now()]);
        }

        return $ready;
    }

    /** Marks a Word template without a signature spot as not signed, or undoes it. */
    public function markUnsigned(PdtsDocumentTemplate $template, bool $unsigned): bool
    {
        if ($unsigned && ($template->isPdf() || $template->supportsElectronicSignature())) {
            throw new RuntimeException($template->isPdf()
                ? 'PDF templates are filled in and signed by hand.'
                : 'This template has a signature spot, so documents from it are signed in PDTS.');
        }

        $template->forceFill(['is_unsigned' => $unsigned])->save();

        return DB::transaction(fn () => $this->activateIfReady($template));
    }

    public function save(PdtsDocumentTemplate $template, array $placement, ?array $marks = null): PdtsDocumentTemplate
    {
        if ($marks !== null) {
            $template->manual_marks = $this->cleanMarks($marks) ?: null;
        }
        $placement = $this->normalise($template, $placement, $this->inspect($template)['pages']);
        $original = $this->originalPath($template);
        $oldPrepared = $template->storage_path !== $template->original_path ? $template->storage_path : null;

        if ($template->isPdf()) {
            // PDFs are stamped for each document; the original is the template.
            $preparedPath = $template->original_path;
            $placeholders = [];
            $labels = [];
        } else {
            $prepared = $this->preparer->prepare($original, $placement, (array) ($template->manual_marks ?? []), built: $template->isBuilt());
            $preparedPath = 'pdts/templates/'.Str::random(40).'.docx';
            Storage::disk('private')->put($preparedPath, $prepared['contents']);
            $placeholders = $this->placeholdersOf($prepared['contents']);
            $labels = $prepared['labels'];
            // Tick boxes beside a label in the file ("☐ Within town ☐ Out of town"): a choice list of those
            // options on the form, unless the administrator has already set the field up.
            $settings = (array) ($template->field_settings ?? []);
            foreach ((array) ($prepared['choices'] ?? []) as $name => $choices) {
                $settings[$name] ??= ['type' => 'choice', 'choices' => $choices];
            }
            // A grid to fill in row by row: a table field with the grid's column headings.
            foreach ((array) ($prepared['tables'] ?? []) as $name => $columns) {
                $settings[$name] = ['type' => 'table', 'columns' => $columns] + array_diff_key((array) ($settings[$name] ?? []), ['type' => 1, 'columns' => 1, 'choices' => 1]);
            }
            $template->field_settings = $settings ?: null;
        }

        DB::transaction(function () use ($template, $preparedPath, $placeholders, $labels, $placement) {
            $template->forceFill([
                'storage_path' => $preparedPath,
                'placeholders' => $placeholders,
                'field_labels' => $labels ?: null,
                'qr_page' => $placement['page'],
                'qr_x_mm' => $placement['x'],
                'qr_y_mm' => $placement['y'],
                'qr_size_mm' => $placement['size'],
                'configured_at' => now(),
            ]);

            // Offered to authors only once it can be signed in PDTS or is marked as not signed.
            $this->activateIfReady($template);
        });

        if ($oldPrepared && $oldPrepared !== $preparedPath) {
            Storage::disk('private')->delete($oldPrepared);
        }

        return $template;
    }

    /**
     * The template as a document made from it would look, with a sample QR
     * code and sample details, before saving.
     *
     * @return array{filename: string, contents: string, mime: string}
     */
    public function sample(PdtsDocumentTemplate $template, array $placement, ?array $marks = null): array
    {
        $placement = $this->normalise($template, $placement, $this->inspect($template)['pages']);
        $original = $this->originalPath($template);
        $url = rtrim((string) config('app.url'), '/').'/s/SAMPLE'.str_repeat('0', 34);
        $name = Str::of($template->name)->replaceMatches('/[^A-Za-z0-9 ._-]+/', '')->squish()->limit(60, '');

        if ($template->isPdf()) {
            return [
                'filename' => "Sample - {$name}.pdf",
                'contents' => $this->stamp((string) file_get_contents($original), $placement, $url, 'OMTS-2026-0000'),
                'mime' => 'application/pdf',
            ];
        }

        $prepared = $this->preparer->prepare($original, $placement, $marks !== null ? $this->cleanMarks($marks) : (array) ($template->manual_marks ?? []), built: $template->isBuilt());
        $work = $this->temporaryFile($prepared['contents']);
        try {
            // Every line PDTS found is filled, as in the sample PDF: no ${placeholder} is left showing.
            ['values' => $values, 'tables' => $tables] = $this->sampleAnswers($template, $this->placeholdersOf($prepared['contents']),
                (array) ($prepared['labels'] ?? []), (array) ($prepared['choices'] ?? []), (array) ($prepared['tables'] ?? []));
            $contents = $this->filler->fill($work, $values, $this->qr->make($url), $placement['size'],
                ['image' => self::sampleSignature(), 'extension' => 'png', 'width_mm' => 36.0, 'height_mm' => 14.0], [], $tables);
        } finally {
            @unlink($work);
        }

        return [
            'filename' => "Sample - {$name}.docx",
            'contents' => $contents,
            'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ];
    }

    /**
     * Marks from the preview, checked and tidied.
     *
     * @return list<array{line: int, text: string, kind: string, label: string}>
     */
    public function cleanMarks(array $marks): array
    {
        $clean = [];
        foreach ($marks as $mark) {
            $kind = (string) ($mark['kind'] ?? '');
            $line = $mark['line'] ?? null;
            if (! in_array($kind, ['field', 'writing', 'signature'], true) || ! is_numeric($line) || (int) $line < 0) {
                continue;
            }
            $label = mb_substr(trim((string) ($mark['label'] ?? '')), 0, 80);
            if ($kind !== 'signature' && $label === '') {
                throw new RuntimeException('Give each marked field or writing space a name.');
            }
            $clean[(int) $line] = [
                'line' => (int) $line,
                'text' => mb_substr(trim((string) ($mark['text'] ?? '')), 0, 300),
                'kind' => $kind,
                'label' => $label,
            ];
        }
        ksort($clean);

        return array_values($clean);
    }

    /**
     * What PDTS understood in a template, for the administrator to check:
     * fields, writing spaces, the signature spot, the name line, marks that
     * no longer match their line, and tips for anything missing.
     *
     * @return array{fields: list<array{name: string, label: string, kind: string}>, signature: bool, authorLine: bool, subject: bool, qr: bool, marks: list<array>, warnings: list<string>, tips: list<string>}
     */
    public function check(PdtsDocumentTemplate $template): array
    {
        $placement = $template->placement() ?? $this->defaultPlacement($this->inspect($template)['pages'][0]);

        if ($template->isPdf()) {
            return [
                'fields' => [], 'signature' => false, 'authorLine' => false, 'subject' => false, 'qr' => $template->isConfigured(), 'marks' => [],
                'warnings' => ['PDF templates are printed and filled in by hand: PDTS adds the QR code and reference only.'],
                'tips' => ['To fill and sign it in PDTS, upload the Word version of this form instead.'],
            ];
        }

        $prepared = $this->preparer->prepare($this->originalPath($template), $placement, (array) ($template->manual_marks ?? []), built: $template->isBuilt());
        $names = $this->placeholdersOf($prepared['contents']);
        $labels = ['to' => 'To', 'from' => 'From', 'cc' => 'CC', 'office_reference' => 'Office reference', 'date' => 'Date', 'subject' => 'Subject'];

        $fields = [];
        foreach ($names as $name) {
            if (in_array($name, ['qr_code', 'reference', 'signature', 'author', 'author_upper', 'author_job_title', 'title', 'directorate', 'department', 'office'], true)) {
                continue;
            }
            $fields[] = [
                'name' => $name,
                // The template's own name first (e.g. "Ref" from the builder's REF), then the usual one.
                'label' => $template->fieldSetting($name)['label'] ?? $labels[$name] ?? $prepared['labels'][$name] ?? TemplatePreparer::displayLabel(str_replace('_', ' ', preg_replace('/^text_/', '', $name))),
                'kind' => PdtsDocumentTemplate::isWritingSpace($name) ? 'writing' : (isset($labels[$name]) ? 'heading' : 'field'),
            ];
        }

        $signature = in_array('signature', $names, true);
        $authorLine = in_array('author', $names, true) || in_array('author_upper', $names, true);
        $writing = collect($fields)->where('kind', 'writing')->isNotEmpty();
        $skipped = array_values(array_filter($prepared['marks'] ?? [], fn ($m) => ! $m['applied']));

        $warnings = [];
        $tips = [];
        if (! $signature && ! $template->isUnsigned()) {
            $warnings[] = 'No signature spot found. Authors are not offered this template until the signature line is marked, or the template is marked as not signed.';
            $tips[] = 'Add a line "Signature: ____", or a [FULL NAME] line under where the signature goes, or mark the line on the QR code page.';
        }
        if (! $writing) {
            $tips[] = 'There is no space to write the main text in PDTS. Leave an empty line under the subject or under each heading, or mark one on the QR code page.';
        }
        if (! in_array('subject', $names, true)) {
            $tips[] = 'No subject line was found; the subject is still asked for, for the tracking record. Use "SUBJECT: ____" or "[SUBJECT …]" to print it on the document.';
        }
        foreach ($skipped as $mark) {
            $warnings[] = 'A line marked as "'.($mark['label'] ?: 'signature spot').'" no longer matches the file and was skipped. Mark it again.';
        }

        return [
            'fields' => $fields,
            'signature' => $signature,
            'authorLine' => $authorLine,
            'subject' => in_array('subject', $names, true),
            'qr' => $template->isConfigured(),
            'marks' => $prepared['marks'] ?? [],
            'warnings' => $warnings,
            'tips' => $tips,
        ];
    }

    /**
     * Sample answers for every placeholder a template uses, so a sample shows
     * it filled in: the usual heading lines, the signatory, and each of the
     * template's own fields by its type. Labels, tick-box choices and tables
     * found in a file not saved yet can be passed in.
     *
     * @param  list<string>  $names
     * @param  array<string, string>  $labels
     * @param  array<string, list<string>>  $choices
     * @param  array<string, list<string>>  $gridTables
     * @return array{values: array<string, string>, tables: array<string, array{columns: list<string>, rows: list<list<string>>}>}
     */
    private function sampleAnswers(PdtsDocumentTemplate $template, array $names, array $labels = [], array $choices = [], array $gridTables = []): array
    {
        $values = [
            'reference' => 'OMTS-2026-0000',
            'to' => 'Director - Corporate Affairs',
            'from' => 'Senior Manager - ICT',
            'cc' => "Managing Director\nAll Directors",
            'office_reference' => 'ICT/ADM/001/2026',
            'date' => now()->format('j F Y'),
            'subject' => 'SAMPLE SUBJECT LINE',
            'title' => 'Sample title',
            'author' => 'Sample Officer',
            'author_upper' => 'SAMPLE OFFICER',
            'author_job_title' => 'Senior Manager - Sample Department',
            'directorate' => 'Sample Directorate',
            'department' => 'Sample Department',
            'office' => 'Sample Office',
        ];
        $known = $template->customFields();
        $tables = [];

        foreach ($names as $name) {
            if (array_key_exists($name, $values) || in_array($name, ['qr_code', 'signature'], true)) {
                continue;
            }
            $setting = $template->fieldSetting($name);
            if (isset($choices[$name])) {
                $setting = ['type' => 'choice', 'choices' => $choices[$name]] + $setting;
            }
            if (isset($gridTables[$name])) {
                $setting = ['type' => 'table', 'columns' => $gridTables[$name]] + $setting;
            }
            $label = mb_strtolower($known[$name] ?? $labels[$name] ?? TemplatePreparer::displayLabel(str_replace('_', ' ', preg_replace('/^text_/', '', $name))));

            // Tables: their headings and two sample rows.
            if ($setting['type'] === 'table') {
                if (! $setting['hidden']) {
                    $tables[$name] = ['columns' => $setting['columns'], 'rows' => [
                        array_map(fn ($column) => 'Sample '.mb_strtolower($column), $setting['columns']),
                        array_map(fn ($column) => 'Second row', $setting['columns']),
                    ]];
                }

                continue;
            }
            $values[$name] = match (true) {
                $setting['hidden'] => '',
                PdtsDocumentTemplate::isWritingSpace($name) || $setting['type'] === 'long' => 'This is sample text for the '.$label.".\nA second paragraph shows how the text continues.",
                $setting['type'] === 'date' => now()->format('j F Y'),
                $setting['type'] === 'number' => '1,250.00',
                $setting['type'] === 'choice' => $setting['choices'][0] ?? 'Sample choice',
                default => 'Sample '.$label,
            };
        }

        return ['values' => $values, 'tables' => $tables];
    }

    /**
     * "Try it": the saved template filled with sample details and a sample
     * signature, as the exact PDF a document would be. Null when this server
     * cannot make PDFs.
     */
    public function samplePdf(PdtsDocumentTemplate $template): ?string
    {
        $converter = app(PdfConverter::class);
        if ($template->isPdf() || ! $template->storage_path || ! $converter->available()) {
            return null;
        }

        ['values' => $values, 'tables' => $tables] = $this->sampleAnswers($template, $template->placeholderNames());

        $docx = $this->filler->fill(
            Storage::disk('private')->path($template->storage_path),
            $values,
            $this->qr->make(rtrim((string) config('app.url'), '/').'/s/SAMPLE'),
            (float) ($template->qr_size_mm ?? self::DEFAULT_SIZE_MM),
            ['image' => self::sampleSignature(), 'extension' => 'png', 'width_mm' => 36.0, 'height_mm' => 14.0],
            [],
            $tables,
        );

        return $converter->convert($docx);
    }

    /** A plain wavy line standing in for a signature on samples (made without GD). */
    public static function sampleSignature(): string
    {
        [$width, $height] = [360, 140];
        $raw = '';
        for ($y = 0; $y < $height; $y++) {
            $raw .= "\0";
            for ($x = 0; $x < $width; $x++) {
                $wave = (int) round(70 + 30 * sin($x / 22) * cos($x / 61));
                $raw .= ($x > 20 && $x < 340 && abs($y - $wave) < 3) ? "\x18\x2a\x7a\xff" : "\0\0\0\0";
            }
        }
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0)).$chunk('IDAT', gzcompress($raw)).$chunk('IEND', '');
    }

    /** Stamps the QR code for $url, with the tracking reference printed under it. */
    public function stamp(string $pdf, array $placement, string $url, ?string $reference = null): string
    {
        $pages = match ((string) $placement['page']) {
            'all' => 'all',
            'first' => [1],
            default => [(int) $placement['page']],
        };

        return $this->stamper->stamp($pdf, $pages, $this->qr->modules($url), $placement['x'], $placement['y'], $placement['size'], $reference);
    }

    public function originalPath(PdtsDocumentTemplate $template): string
    {
        $path = $template->original_path ?: $template->storage_path;
        if (! $path || ! Storage::disk('private')->exists($path)) {
            throw new RuntimeException('The template file is missing. Upload it again.');
        }

        return Storage::disk('private')->path($path);
    }

    /** @return list<string> */
    private function placeholdersOf(string $docx): array
    {
        $work = $this->temporaryFile($docx);
        try {
            return $this->filler->placeholdersIn($work);
        } finally {
            @unlink($work);
        }
    }

    private function temporaryFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pdts-tpl');
        file_put_contents($path, $contents);

        return $path;
    }
}
