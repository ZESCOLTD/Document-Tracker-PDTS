<?php

namespace App\Services\Pdts\Templates;

use App\Models\PdtsDocumentTemplate;
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

        $prepared = $this->preparer->prepare($path, $this->defaultPlacement($this->preparer->pageSize($path)));

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
    public function save(PdtsDocumentTemplate $template, array $placement): PdtsDocumentTemplate
    {
        $placement = $this->normalise($template, $placement, $this->inspect($template)['pages']);
        $original = $this->originalPath($template);
        $oldPrepared = $template->storage_path !== $template->original_path ? $template->storage_path : null;

        if ($template->isPdf()) {
            // PDFs are stamped for each document; the original is the template.
            $preparedPath = $template->original_path;
            $placeholders = [];
            $labels = [];
        } else {
            $prepared = $this->preparer->prepare($original, $placement);
            $preparedPath = 'pdts/templates/'.Str::random(40).'.docx';
            Storage::disk('private')->put($preparedPath, $prepared['contents']);
            $placeholders = $this->placeholdersOf($prepared['contents']);
            $labels = $prepared['labels'];
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
                'is_active' => true,
            ])->save();
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
    public function sample(PdtsDocumentTemplate $template, array $placement): array
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

        $prepared = $this->preparer->prepare($original, $placement);
        $work = $this->temporaryFile($prepared['contents']);
        try {
            $contents = $this->filler->fill($work, [
                'reference' => 'OMTS-2026-0000',
                'to' => 'Director - Corporate Affairs',
                'from' => 'Senior Manager - ICT',
                'cc' => 'Managing Director',
                'office_reference' => 'DIF/DDAS/054/2025',
                'date' => now()->format('j F Y'),
                'subject' => 'SAMPLE SUBJECT LINE',
                'title' => 'Sample title',
                'author' => 'Sample Author',
            ], $this->qr->make($url), $placement['size']);
        } finally {
            @unlink($work);
        }

        return [
            'filename' => "Sample - {$name}.docx",
            'contents' => $contents,
            'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ];
    }

    /** Draws a document's QR code onto a PDF template. */
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
