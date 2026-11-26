<?php

namespace App\Services\Pdts\Templates;

use App\Models\PdtsDocumentTemplate;
use App\Services\Pdts\Signatures\PdfConverter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Templates built in PDTS (the Template Builder), like a form: the
 * administrator adds standard lines, questions, headings and a signature
 * block, and chooses a layout style. From that design PDTS writes a Word
 * template with ${placeholders}, which then goes through the same steps as
 * an uploaded one (QR code, form, filling, signing, PDF, versions).
 *
 * A design is ['layout' => 'memo'|'form', 'title' => string, 'items' => list<item>]
 * and each item is one of:
 *  - standard: a line PDTS knows (to, from, cc, office_reference, date, subject);
 *  - question: text, long (a space to write paragraphs), number, date or choice;
 *  - section: a heading printed on the document;
 *  - note: fixed text printed on the document;
 *  - signature: where the signatory signs, with their name under it.
 */
class TemplateDesigner
{
    public const LAYOUTS = [
        'memo' => 'Memo style: lines such as TO and FROM, then the text',
        'letter' => 'Letter style: OUR REF, the date, the addressee, "Dear …", the subject, the text, then "Yours faithfully, ZESCO LIMITED"',
        'form' => 'Form style: a table of questions and answers',
        'report' => 'Report style: details at the top, then numbered sections',
        'custom' => 'Blank: start from an empty page, with your own rows of 1, 2 or 3 columns and your own styles',
    ];

    /**
     * The layouts offered in the builder. Form and Report style are no longer
     * offered (Custom does both); templates already made in them still print.
     */
    public const OFFERED_LAYOUTS = ['memo', 'letter', 'custom'];

    /** How an item looks in the Custom layout. */
    public const STYLE = ['align' => 'left', 'bold' => false, 'italic' => false, 'caps' => false, 'size' => 'normal', 'label' => 'inline', 'box' => 'none', 'tight' => false];

    public const ALIGNMENTS = ['left' => 'Left', 'center' => 'Centre', 'right' => 'Right', 'both' => 'Justified'];

    public const SIZES = ['small' => 'Small', 'normal' => 'Normal', 'large' => 'Large'];

    // 'plain': the label then the answer with no colon, e.g. "Dear Mr. Banda".
    public const LABEL_MODES = ['inline' => 'Before the answer', 'plain' => 'Before, no colon (e.g. Dear …)', 'above' => 'Above the answer', 'hidden' => 'Not printed'];

    public const BOXES = ['none' => 'None', 'border' => 'Border', 'shaded' => 'Shaded'];

    /** How thick a Line item prints: [name, Word border size in eighths of a point, pixels in the builder]. */
    public const LINE_WEIGHTS = ['thin' => ['Thin', 8, 1], 'medium' => ['Medium', 12, 2], 'thick' => ['Thick', 24, 4]];

    /** Pieces that are printed as they are (no answer). */
    public const FIXED_KINDS = ['section', 'note', 'text', 'line', 'space', 'pagebreak', 'signline'];

    /** Standard lines, with the label printed by default. */
    public const STANDARD = [
        'to' => 'TO',
        'from' => 'FROM',
        'cc' => 'CC',
        'office_reference' => 'REF',
        'date' => 'DATE',
        'subject' => 'SUBJECT',
    ];

    public const QUESTION_TYPES = [
        'text' => 'Short answer',
        'long' => 'Paragraph',
        'number' => 'Number',
        'date' => 'Date',
        'choice' => 'Choice list',
        'person' => 'Person in PDTS',
        'people' => 'Several people',
        'attachment' => 'Attachment',
        'rich' => 'Formatted text',
        'table' => 'Table (rows the author fills in)',
    ];

    /** Question types that take the full width and a space of their own: paragraphs, and tables. */
    public const PARAGRAPH_TYPES = ['long', 'rich', 'table'];

    /** A table has 1 to this many columns. */
    public const MAX_TABLE_COLUMNS = 6;

    public static function isParagraph(array $item): bool
    {
        return $item['kind'] === 'question' && in_array($item['type'], self::PARAGRAPH_TYPES, true);
    }

    public const MAX_ITEMS = 60;

    /** A4, 2 cm margins: the width text can use, in twentieths of a point. */
    private const WIDTH = 9638;

    /** The width text can use on the page being written (a letterhead's own margins may differ). */
    private int $width = self::WIDTH;

    /** A new item of a kind, as the builder adds it. */
    public static function newItem(string $kind, ?string $type = null): array
    {
        $item = ['id' => Str::random(8), 'kind' => $kind, 'name' => null, 'type' => null, 'label' => '', 'required' => false,
            'help' => '', 'choices' => '', 'columns' => '', 'auto' => 'none', 'show_label' => true, 'text' => '', 'job_title' => false, 'weight' => 'thin', 'style' => self::STYLE];
        $styled = fn (array $style) => ['style' => array_merge(self::STYLE, $style)];

        return match ($kind) {
            'standard' => ['name' => $type, 'label' => self::STANDARD[$type] ?? '', 'required' => in_array($type, ['to', 'subject'], true)]
                + ($type === 'subject' ? $styled(['bold' => true, 'caps' => true, 'label' => 'hidden']) : []) + $item,
            'question' => ['type' => $type, 'label' => $type === 'table' ? 'Items' : (self::QUESTION_TYPES[$type] ?? 'Question')]
                + ($type === 'table' ? ['columns' => 'Item, Quantity, Amount'] : [])
                + (in_array($type, self::PARAGRAPH_TYPES, true) ? $styled(['label' => 'above', 'align' => 'both']) : []) + $item,
            'section' => ['label' => 'New section'] + $styled(['bold' => true, 'caps' => true]) + $item,
            'note' => ['text' => 'Text printed on every document.'] + $styled(['italic' => true, 'size' => 'small']) + $item,
            'text' => ['text' => 'Text printed on every document, e.g. CONFIDENTIAL.'] + $item,
            'line', 'space', 'pagebreak' => $item,
            'signline' => ['label' => 'Approved by'] + $item,
            'signature' => ['label' => ''] + $item,
            default => throw new \InvalidArgumentException("Unknown item kind {$kind}"),
        };
    }

    /** What a new template starts with: a memo with the usual lines, the text and a signature. */
    public static function starter(): array
    {
        $body = self::newItem('question', 'long');
        $body['label'] = 'Body';
        $body['show_label'] = false;
        $body['required'] = true;

        return [
            'layout' => 'memo',
            'title' => '',
            'items' => [
                self::newItem('standard', 'to'),
                self::newItem('standard', 'from'),
                self::newItem('standard', 'date'),
                self::newItem('standard', 'subject'),
                $body,
                self::newItem('signature'),
            ],
        ];
    }

    /**
     * What a letter starts with, as on the ZESCO letter: OUR REF, the date, the
     * addressee (name, position, address, town, one per line), "Dear …", the
     * subject, the text, the signature ("Yours faithfully, ZESCO LIMITED", the
     * name and job title) and cc.
     *
     * @return list<array>
     */
    public static function letterItems(): array
    {
        $ref = self::newItem('standard', 'office_reference');
        $ref['label'] = 'OUR REF';
        $addressee = self::newItem('question', 'long');
        $addressee['label'] = 'Addressee';
        $addressee['show_label'] = false;
        $addressee['required'] = true;
        $addressee['help'] = 'Name, position or organisation, address and TOWN, one per line.';
        $dear = self::newItem('question', 'text');
        $dear['label'] = 'Dear';
        $dear['required'] = true;
        $dear['help'] = 'e.g. Mr. Banda';
        $body = self::newItem('question', 'long');
        $body['label'] = 'Body';
        $body['show_label'] = false;
        $body['required'] = true;
        $signature = self::newItem('signature');
        $signature['job_title'] = true;
        $cc = self::newItem('standard', 'cc');
        $cc['label'] = 'cc';

        return [$ref, self::newItem('standard', 'date'), $addressee, $dear, self::newItem('standard', 'subject'), $body, $signature, $cc];
    }

    /** A short question labelled "Dear" is the letter's greeting: printed "Dear Mr. Banda", with no colon. */
    public static function isGreeting(array $item): bool
    {
        return $item['kind'] === 'question' && ! self::isParagraph($item) && mb_strtolower(trim($item['label'], ' :,')) === 'dear';
    }

    /**
     * Checks a design from the builder and gives every question a stable
     * placeholder name (kept from earlier saves, so documents and form
     * settings still match).
     *
     * @throws ValidationException
     */
    public function clean(array $design): array
    {
        $errors = [];
        $layout = array_key_exists($design['layout'] ?? '', self::LAYOUTS) ? $design['layout'] : 'memo';
        $items = array_values((array) ($design['items'] ?? []));

        if ($items === []) {
            $errors['items'] = 'Add at least one item to the template.';
        }
        if (count($items) > self::MAX_ITEMS) {
            $errors['items'] = 'A template can have at most '.self::MAX_ITEMS.' items.';
        }

        $used = array_merge(array_keys(PdtsDocumentTemplate::PLACEHOLDERS), ['text']);
        $seenStandard = [];
        $signatures = 0;
        $clean = [];

        foreach ($items as $i => $raw) {
            $raw = (array) $raw;
            $kind = (string) ($raw['kind'] ?? '');
            $item = self::newItem(in_array($kind, ['standard', 'question', 'section', 'note', 'text', 'line', 'space', 'pagebreak', 'signline', 'signature'], true) ? $kind : 'note',
                $kind === 'standard' ? (string) ($raw['name'] ?? '') : (string) ($raw['type'] ?? 'text'));
            $item['id'] = (string) ($raw['id'] ?? $item['id']);
            $item['label'] = mb_substr(trim((string) ($raw['label'] ?? '')), 0, 80);
            $item['help'] = mb_substr(trim((string) ($raw['help'] ?? '')), 0, 200);
            $item['required'] = (bool) ($raw['required'] ?? false);
            $item['show_label'] = (bool) ($raw['show_label'] ?? true);
            $item['text'] = mb_substr(trim((string) ($raw['text'] ?? '')), 0, 2000);
            $item['auto'] = array_key_exists($raw['auto'] ?? '', PdtsDocumentTemplate::AUTO_FILL) ? $raw['auto'] : 'none';
            $item['style'] = self::cleanStyle((array) ($raw['style'] ?? []), $item['style']);
            $at = 'items.'.$i;

            switch ($kind) {
                case 'standard':
                    $name = (string) ($raw['name'] ?? '');
                    if (! array_key_exists($name, self::STANDARD)) {
                        $errors[$at] = 'Unknown standard line.';
                        break;
                    }
                    if (isset($seenStandard[$name])) {
                        $errors[$at] = '"'.self::STANDARD[$name].'" is on the template twice.';
                    }
                    $seenStandard[$name] = true;
                    $item['name'] = $name;
                    $item['label'] = $item['label'] !== '' ? $item['label'] : self::STANDARD[$name];
                    break;

                case 'question':
                    $type = array_key_exists($raw['type'] ?? '', self::QUESTION_TYPES) ? $raw['type'] : 'text';
                    $item['type'] = $type;
                    if (in_array($type, ['person', 'people', 'attachment', 'rich', 'table'], true)) {
                        $item['auto'] = 'none';
                    }
                    $columns = array_values(array_filter(array_map('trim', explode(',', (string) ($raw['columns'] ?? ''))), fn ($c) => $c !== ''));
                    if ($type === 'table' && ($columns === [] || count($columns) > self::MAX_TABLE_COLUMNS)) {
                        $errors[$at.'.columns'] = 'Give the table 1 to '.self::MAX_TABLE_COLUMNS.' column headings, separated by commas.';
                    }
                    $item['columns'] = $type === 'table' ? implode(', ', array_map(fn ($c) => mb_substr($c, 0, 40), $columns)) : '';
                    if ($item['label'] === '') {
                        $errors[$at.'.label'] = 'Give this question a label.';
                    }
                    $choices = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) ($raw['choices'] ?? ''))), fn ($c) => $c !== '')));
                    if ($type === 'choice' && count($choices) < 2) {
                        $errors[$at.'.choices'] = 'Give at least two choices, separated by commas.';
                    }
                    $item['choices'] = $type === 'choice' ? implode(', ', $choices) : '';
                    $item['name'] = $this->placeholderName((string) ($raw['name'] ?? ''), $item['label'], in_array($type, self::PARAGRAPH_TYPES, true), $used);
                    $used[] = $item['name'];
                    break;

                case 'section':
                    if ($item['label'] === '') {
                        $errors[$at.'.label'] = 'Give this section a heading.';
                    }
                    break;

                case 'note':
                case 'text':
                    if ($item['text'] === '') {
                        $errors[$at.'.text'] = 'Write the text, or remove it.';
                    }
                    break;

                case 'line':
                    $item['weight'] = array_key_exists($raw['weight'] ?? '', self::LINE_WEIGHTS) ? $raw['weight'] : 'thin';
                    break;

                case 'space':
                case 'pagebreak':
                    break;

                case 'signline':
                    $item['label'] = rtrim($item['label'], ': ');
                    break;

                case 'signature':
                    // The signatory's job title printed under the name (from their PDTS account).
                    $item['job_title'] = (bool) ($raw['job_title'] ?? false);
                    $signatures++;
                    if ($signatures > 1) {
                        $errors[$at] = 'A template has one signature block.';
                    }
                    break;

                default:
                    $errors[$at] = 'Unknown item.';
            }

            $clean[] = $item;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(collect($errors)->mapWithKeys(fn ($m, $k) => ['design.'.$k => $m])->all());
        }

        // Custom layout: the rows decide the order; the answers on the form follow it.
        $rows = null;
        if ($layout === 'custom') {
            $rows = self::cleanGrid($design['rows'] ?? null, $clean);
            $order = array_flip(array_merge(...array_map(fn ($row) => array_merge(...$row['cells']), $rows ?: [['cells' => [[]]]])));
            usort($clean, fn ($a, $b) => ($order[$a['id']] ?? PHP_INT_MAX) <=> ($order[$b['id']] ?? PHP_INT_MAX));
        }

        return [
            'layout' => $layout,
            'letterhead' => $this->letterheadTemplate($design['letterhead'] ?? null)?->id,
            'title' => mb_substr(trim((string) ($design['title'] ?? '')), 0, 120),
            // The title under the logo; a letter never has one.
            'show_title' => $layout !== 'letter' && (bool) ($design['show_title'] ?? true),
            'items' => $clean,
            'rows' => $rows,
            'qr' => self::cleanQr($design['qr'] ?? null),
        ];
    }

    /**
     * Where the QR code goes, set in the builder: page, mm from the left and
     * top, size. Null when not set (the letterhead's standard spot is used).
     *
     * @return array{page: string, x: float, y: float, size: float}|null
     */
    public static function cleanQr(mixed $qr): ?array
    {
        if (! is_array($qr) || ! is_numeric($qr['x'] ?? null) || ! is_numeric($qr['y'] ?? null)) {
            return null;
        }
        $size = round(min(TemplateSetup::MAX_SIZE_MM, max(TemplateSetup::MIN_SIZE_MM, (float) ($qr['size'] ?? TemplateSetup::DEFAULT_SIZE_MM))), 1);

        return [
            'page' => ($qr['page'] ?? 'first') === 'all' ? 'all' : 'first',
            'x' => round(min(400, max(0, (float) $qr['x'])), 1),
            'y' => round(min(400, max(0, (float) $qr['y'])), 1),
            'size' => $size,
        ];
    }

    /** The QR code's standard spot for a letterhead: where that letterhead has it, else top left as on the ZESCO memo. */
    public function standardQr(mixed $letterhead): array
    {
        return $this->letterheadTemplate($letterhead)?->placement() ?? self::BUILT_IN_QR;
    }

    /** A corner of the page for the QR code, 10 mm in from the edges (as the QR code page's buttons). */
    public function qrInCorner(string $corner, float $size, mixed $letterhead): array
    {
        $path = $this->letterheadPath($letterhead);
        $page = $path ? app(TemplatePreparer::class)->pageSize($path) : ['width' => 210.0, 'height' => 297.0];
        $margin = 10.0;

        return [
            'x' => str_contains($corner, 'left') ? $margin : round($page['width'] - $margin - $size, 1),
            // The reference printed under the code needs a few mm at the bottom.
            'y' => str_contains($corner, 'top') ? $margin : round($page['height'] - $margin - $size - 4, 1),
        ];
    }

    /** A style from the builder, kept to the known choices. */
    public static function cleanStyle(array $raw, array $defaults = self::STYLE): array
    {
        $pick = fn (string $key, array $allowed) => array_key_exists((string) ($raw[$key] ?? ''), $allowed) ? (string) $raw[$key] : $defaults[$key];

        return [
            'align' => $pick('align', self::ALIGNMENTS),
            'bold' => (bool) ($raw['bold'] ?? $defaults['bold']),
            'italic' => (bool) ($raw['italic'] ?? $defaults['italic']),
            'caps' => (bool) ($raw['caps'] ?? $defaults['caps']),
            'size' => $pick('size', self::SIZES),
            'label' => $pick('label', self::LABEL_MODES),
            'box' => $pick('box', self::BOXES),
            // Lines close together (no space after each), e.g. an address block.
            'tight' => (bool) ($raw['tight'] ?? $defaults['tight'] ?? false),
        ];
    }

    /** A new row of the Custom layout. */
    public static function newRow(int $columns = 1, array $firstCell = []): array
    {
        $columns = max(1, min(3, $columns));
        $cells = array_fill(0, $columns, []);
        $cells[0] = array_values($firstCell);

        return ['id' => Str::random(8), 'columns' => $columns, 'line' => false, 'space' => 'none', 'cells' => $cells];
    }

    /**
     * Rows for a design that has none yet: one item a row, except that
     * neighbouring TO/FROM and REF/DATE lines share a row of 2 columns, as on
     * the ZESCO memo.
     */
    public static function defaultGrid(array $items): array
    {
        $rows = [];
        foreach (self::memoRows($items) as $row) {
            if (count($row) === 2) {
                $new = self::newRow(2, [$items[$row[0]]['id']]);
                $new['cells'][1] = [$items[$row[1]]['id']];
                $rows[] = $new;
            } else {
                $rows[] = self::newRow(1, [$items[$row[0]]['id']]);
            }
        }

        return $rows;
    }

    /**
     * Rows kept to what is valid: 1 to 3 columns, each item in one place,
     * items missing from the rows added at the end. Empty rows are dropped
     * when saving and kept while building.
     */
    public static function cleanGrid(mixed $rows, array $items, bool $keepEmpty = false): array
    {
        $ids = array_column($items, 'id');
        $seen = [];
        $clean = [];

        foreach (array_slice(array_values((array) $rows), 0, self::MAX_ITEMS) as $row) {
            $row = (array) $row;
            $columns = max(1, min(3, (int) ($row['columns'] ?? 1)));
            $cells = [];
            for ($c = 0; $c < $columns; $c++) {
                $cells[$c] = [];
                foreach ((array) (($row['cells'] ?? [])[$c] ?? []) as $id) {
                    if (in_array($id, $ids, true) && ! isset($seen[$id])) {
                        $cells[$c][] = $id;
                        $seen[$id] = true;
                    }
                }
            }
            // Items in columns that were taken away go into the last column.
            foreach (array_slice((array) ($row['cells'] ?? []), $columns) as $extra) {
                foreach ((array) $extra as $id) {
                    if (in_array($id, $ids, true) && ! isset($seen[$id])) {
                        $cells[$columns - 1][] = $id;
                        $seen[$id] = true;
                    }
                }
            }
            if (! $keepEmpty && array_merge(...$cells) === []) {
                continue;
            }
            $clean[] = [
                'id' => (string) ($row['id'] ?? Str::random(8)),
                'columns' => $columns,
                'line' => (bool) ($row['line'] ?? false),
                'space' => in_array($row['space'] ?? '', ['none', 'small', 'large'], true) ? $row['space'] : 'none',
                'cells' => $cells,
            ];
        }

        foreach ($items as $item) {
            if (! isset($seen[$item['id']])) {
                $clean[] = self::newRow(1, [$item['id']]);
            }
        }

        return $clean;
    }

    /** Uploaded Word templates whose top (logo, title) and footer can be a built template's letterhead. */
    public static function letterheads()
    {
        return PdtsDocumentTemplate::query()->where('kind', 'docx')->where('source', 'upload')
            ->whereNotNull('original_path')->whereNull('superseded_at')->orderBy('name')->get(['id', 'name', 'original_path', 'is_active']);
    }

    /**
     * The letterhead new templates start with: the ZESCO letterhead that
     * comes with PDTS (null). Another one is chosen only on purpose.
     */
    public static function defaultLetterhead(): ?int
    {
        return null;
    }

    /**
     * Uploaded templates whose letterhead is really different from the ZESCO
     * one that comes with PDTS. A template made from the same file (the
     * memorandum) is not a different letterhead, so it is not offered; with
     * none, the builder does not ask which letterhead to use.
     */
    public static function otherLetterheads()
    {
        $builtIn = self::builtInLetterhead();
        $builtInHash = $builtIn ? sha1_file($builtIn) : null;

        return self::letterheads()->filter(function ($template) use ($builtInHash) {
            $path = Storage::disk('private')->path($template->original_path);

            return is_file($path) && sha1_file($path) !== $builtInHash;
        })->values();
    }

    /** The ZESCO letterhead that comes with PDTS (from the ZESCO memorandum): logo, motto and footer. */
    public static function builtInLetterhead(): ?string
    {
        $path = resource_path('pdts/zesco-letterhead.docx');

        return is_file($path) ? $path : null;
    }

    /** Where the QR code goes on the ZESCO letterhead: top left, as on the memorandum. */
    public const BUILT_IN_QR = ['page' => 'first', 'x' => 10.0, 'y' => 10.0, 'size' => 16.0];

    /**
     * The letterhead file a template is written on: the chosen uploaded
     * template's, or else the ZESCO letterhead that comes with PDTS. Every
     * built template carries the ZESCO logo and footer.
     */
    public function letterheadPath(mixed $id): ?string
    {
        $chosen = $this->letterheadTemplate($id);

        return $chosen ? Storage::disk('private')->path($chosen->original_path) : self::builtInLetterhead();
    }

    public function letterheadTemplate(mixed $id): ?PdtsDocumentTemplate
    {
        if (! $id || ! ctype_digit((string) $id)) {
            return null;
        }
        $template = PdtsDocumentTemplate::query()->where('kind', 'docx')->where('source', 'upload')->find((int) $id);

        return $template && $template->original_path && Storage::disk('private')->exists($template->original_path) ? $template : null;
    }

    /**
     * The top of a letterhead template: the paragraphs before its first
     * fill-in line that hold a picture or are centred (logo, motto, title).
     * The last of them with text is the title, replaced by the template's own.
     *
     * @return array{parts: array<string, string>, dom: \DOMDocument, xpath: \DOMXPath, head: list<\DOMElement>, title: ?\DOMElement, sectPr: ?\DOMElement}
     */
    private function letterheadTop(string $path): array
    {
        $parts = DocxPackage::read($path);
        $dom = new \DOMDocument;
        $dom->loadXML($parts['word/document.xml']);
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $xpath->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
        $xpath->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

        $head = [];
        $title = null;
        foreach ($xpath->query('/w:document/w:body/w:p') as $paragraph) {
            $text = trim(implode('', array_map(fn ($t) => $t->textContent, iterator_to_array($xpath->query('.//w:t', $paragraph)))));
            $picture = $xpath->query('.//w:drawing|.//w:pict', $paragraph)->length > 0;
            $centred = $xpath->query('w:pPr/w:jc[@w:val="center"]', $paragraph)->length > 0;
            if ((! $picture && ! $centred) || str_contains($text, '${') || str_contains($text, '[')) {
                break;
            }
            $head[] = $paragraph;
            if ($text !== '') {
                $title = $paragraph;
            }
        }

        $sectPr = $xpath->query('/w:document/w:body/w:sectPr')->item(0);

        return compact('parts', 'dom', 'xpath', 'head', 'title', 'sectPr');
    }

    /**
     * What the builder shows of a letterhead: its logo and its lines of
     * text, with the title marked. Kept for an hour.
     *
     * @return array{image: ?string, lines: list<array{text: string, title: bool}>}|null
     */
    public function letterheadPreview(mixed $id): ?array
    {
        $path = $this->letterheadPath($id);
        if (! $path) {
            return null;
        }

        return Cache::remember('pdts.letterhead.'.md5($path).'.'.@filemtime($path), 3600, function () use ($path) {
            try {
                $top = $this->letterheadTop($path);
            } catch (\Throwable $e) {
                report($e);

                return ['image' => null, 'lines' => []];
            }

            $image = null;
            $lines = [];
            foreach ($top['head'] as $paragraph) {
                $embed = $top['xpath']->query('.//a:blip/@r:embed', $paragraph)->item(0)?->nodeValue;
                if ($embed && $image === null && preg_match('#Id="'.preg_quote($embed, '#').'"[^>]*Target="([^"]+)"|Target="([^"]+)"[^>]*Id="'.preg_quote($embed, '#').'"#', (string) ($top['parts']['word/_rels/document.xml.rels'] ?? ''), $m)) {
                    $bytes = $top['parts']['word/'.ltrim($m[1] ?: $m[2], '/')] ?? null;
                    $mime = match (strtolower(pathinfo($m[1] ?: $m[2], PATHINFO_EXTENSION))) {
                        'png' => 'image/png', 'jpg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', default => null
                    };
                    if ($bytes && $mime && strlen($bytes) < 400_000) {
                        $image = 'data:'.$mime.';base64,'.base64_encode($bytes);
                    }
                }
                $text = trim(implode('', array_map(fn ($t) => $t->textContent, iterator_to_array($top['xpath']->query('.//w:t', $paragraph)))));
                if ($text !== '') {
                    $lines[] = ['text' => $text, 'title' => $paragraph === $top['title']];
                }
            }

            return ['image' => $image, 'lines' => $lines];
        });
    }

    /** Keeps an existing name; otherwise one made from the label, unique in the template. */
    private function placeholderName(string $existing, string $label, bool $writing, array $used): string
    {
        $valid = fn (string $name) => preg_match('/^[a-z][a-z0-9_]{0,59}$/', $name)
            && $writing === PdtsDocumentTemplate::isWritingSpace($name) && ! in_array($name, $used, true);

        if ($existing !== '' && $valid($existing)) {
            return $existing;
        }

        $base = Str::of($label)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->limit(40, '')->toString() ?: 'field';
        $base = ($writing ? 'text_' : (ctype_alpha($base[0]) ? '' : 'f_')).$base;
        $name = $base;
        for ($n = 2; ! $valid($name); $n++) {
            $name = $base.'_'.$n;
        }

        return $name;
    }

    /**
     * Saves a design: writes the Word template and prepares it like an
     * upload. A template documents were already made from gets a new version;
     * those documents keep theirs. Signing: a design without a signature
     * block is a "not signed" template.
     *
     * @param  array{name: string, description: ?string, document_type_id: ?int}  $details
     */
    public function save(?PdtsDocumentTemplate $template, array $details, array $design, ?int $userId): PdtsDocumentTemplate
    {
        $design = $this->clean($design);
        $setup = app(TemplateSetup::class);

        $file = tempnam(sys_get_temp_dir(), 'pdts-built');
        file_put_contents($file, $this->docx($design, $details['name']));
        try {
            $stored = $setup->storeOriginal($file, 'docx');
        } finally {
            @unlink($file);
        }

        $previous = $template;
        $inUse = $template?->exists && $template->documents()->exists();
        $oldOriginal = $template && ! $inUse ? $template->original_path : null;

        $target = $inUse ? new PdtsDocumentTemplate([
            'version' => (int) $template->version + 1,
            'previous_version_id' => $template->id,
        ]) : ($template ?? new PdtsDocumentTemplate(['version' => 1]));

        $target->forceFill([
            'name' => $details['name'],
            'description' => $details['description'] ?: null,
            'document_type_id' => $details['document_type_id'] ?: null,
            'kind' => 'docx',
            'source' => 'builder',
            'design' => $design,
            'original_path' => $stored['path'],
            'original_filename' => Str::slug($details['name']).'.docx',
            'page_count' => null,
            'manual_marks' => null,
            'field_settings' => $this->fieldSettings($design),
            'is_unsigned' => ! $this->isSigned($design),
            'is_active' => $target->exists ? $target->is_active : false,
            'uploaded_by' => $userId ?? $target->uploaded_by,
        ])->save();

        $setup->save($target, $this->placementFor($design, $previous, $setup->originalPath($target)));

        if ($oldOriginal && $oldOriginal !== $target->original_path) {
            Storage::disk('private')->delete($oldOriginal);
        }

        return $target->fresh();
    }

    /** Where the builder put the QR code; else where it was; a new template gets it where its letterhead has it, or top right. */
    private function placementFor(array $design, ?PdtsDocumentTemplate $previous, string $file): array
    {
        return $design['qr']
            ?? $previous?->placement()
            ?? ($this->letterheadTemplate($design['letterhead']) ? $this->letterheadTemplate($design['letterhead'])->placement() : (self::builtInLetterhead() ? self::BUILT_IN_QR : null))
            ?? app(TemplateSetup::class)->defaultPlacement(app(TemplatePreparer::class)->pageSize($file));
    }

    /**
     * The design as the exact PDF a document from it would be, with sample
     * details, before it is saved: "Preview" in the builder. Nothing is
     * kept. Null when this server cannot make PDFs.
     */
    public function previewPdf(array $design, string $name, ?PdtsDocumentTemplate $previous = null): ?string
    {
        if (! app(PdfConverter::class)->available()) {
            return null;
        }

        $design = $this->clean($design);
        $disk = Storage::disk('private');
        $file = tempnam(sys_get_temp_dir(), 'pdts-preview');
        $prepared = 'pdts/templates/preview-'.Str::random(32).'.docx';

        try {
            file_put_contents($file, $this->docx($design, $name));
            $placement = $this->placementFor($design, $previous, $file);
            $result = app(TemplatePreparer::class)->prepare($file, $placement, [], built: true);
            $disk->put($prepared, $result['contents']);

            // Not saved: only what "Try it" reads.
            $template = (new PdtsDocumentTemplate)->forceFill([
                'name' => $name,
                'kind' => 'docx',
                'source' => 'builder',
                'design' => $design,
                'storage_path' => $prepared,
                'placeholders' => app(DocxTemplateFiller::class)->placeholdersIn($disk->path($prepared)),
                'field_labels' => $result['labels'] ?: null,
                'field_settings' => $this->fieldSettings($design),
                'qr_size_mm' => $placement['size'],
            ]);

            return app(TemplateSetup::class)->samplePdf($template);
        } finally {
            @unlink($file);
            $disk->delete($prepared);
        }
    }

    /**
     * A copy of a built template for looking at (the QR code page, View):
     * each ${placeholder} becomes a hint in brackets, in the ZESCO memo's
     * own style, e.g. "[Title of main recipient]". The template itself is
     * not changed.
     */
    public function readableCopy(PdtsDocumentTemplate $template): string
    {
        $parts = DocxPackage::read(Storage::disk('private')->path($template->original_path));
        $hints = $this->hints($template);

        foreach ($parts as $name => $xml) {
            if (preg_match('#^word/(document|header\d*|footer\d*)\.xml$#', $name)) {
                $parts[$name] = preg_replace_callback('/\$\{([a-z0-9_]+)\}/', fn (array $m) => WordXml::escape($hints[$m[1]] ?? '['.str_replace('_', ' ', $m[1]).']'), $xml);
            }
        }

        return DocxPackage::write($parts);
    }

    /** @return array<string, string> placeholder => the hint shown in its place */
    private function hints(PdtsDocumentTemplate $template): array
    {
        $hints = [
            'to' => '[Title of main recipient]',
            'from' => '[Your title and department]',
            'cc' => '[Title of person copied]',
            'office_reference' => '[DEPT/UNIT/000/2026]',
            'date' => '[Day Month Year]',
            'subject' => '[SUBJECT IN CAPITAL LETTERS]',
            'signature' => '[Signature]',
            'author' => '[Full name]',
            'author_upper' => '[FULL NAME IN CAPITALS]',
            'author_job_title' => '[JOB TITLE]',
            'title' => '[Document title]',
            'reference' => '[PDTS reference]',
        ];

        foreach ((array) ($template->design['items'] ?? []) as $item) {
            if (($item['kind'] ?? '') !== 'question' || empty($item['name'])) {
                continue;
            }
            $label = trim((string) $item['label']);
            $hints[$item['name']] = match ($item['type']) {
                'long', 'rich' => '[Write the '.mb_strtolower($label).' here.]',
                'choice' => '['.$label.': '.implode(' / ', array_map('trim', explode(',', (string) $item['choices']))).']',
                'date' => '['.$label.': day month year]',
                'number' => '['.$label.': number]',
                'person' => '['.$label.': name, job title]',
                'people' => '['.$label.': names, one per line]',
                'attachment' => '[Attached: '.mb_strtolower($label).']',
                'table' => '[Table: '.implode(' | ', array_map('trim', explode(',', (string) ($item['columns'] ?? '')))).']',
                default => '['.$label.']',
            };
        }

        return $hints;
    }

    public function isSigned(array $design): bool
    {
        return collect($design['items'])->contains('kind', 'signature');
    }

    /**
     * How each field appears on the New Internal Document form.
     *
     * @return array<string, array>
     */
    public function fieldSettings(array $design): array
    {
        $settings = [];

        foreach ($design['items'] as $item) {
            if ($item['kind'] === 'question') {
                $settings[$item['name']] = array_filter([
                    'label' => $item['label'],
                    'type' => $item['type'],
                    'required' => $item['required'],
                    'auto' => $item['auto'] !== 'none' ? $item['auto'] : null,
                    'help' => $item['help'] ?: null,
                    'choices' => $item['type'] === 'choice' ? array_map('trim', explode(',', $item['choices'])) : null,
                    'columns' => $item['type'] === 'table' ? array_map('trim', explode(',', $item['columns'])) : null,
                ], fn ($v) => $v !== null && $v !== '');
            } elseif ($item['kind'] === 'standard' && $item['name'] !== 'subject') {
                $settings[$item['name']] = array_filter([
                    'label' => self::plainLabel($item['label']),
                    'required' => $item['required'],
                    'help' => $item['help'] ?: null,
                ], fn ($v) => $v !== null && $v !== '');
            }
        }

        return $settings;
    }

    /** "OUR REF" → "Our ref", "TO" → "To", for the form; an abbreviation such as "CC" stays as it is. */
    private static function plainLabel(string $label): string
    {
        if (in_array(mb_strtoupper(trim($label)), ['CC', 'C.C.'], true)) {
            return $label;
        }

        return mb_strtoupper($label) === $label ? Str::ucfirst(mb_strtolower($label)) : $label;
    }

    /** The Word template for a design. */
    public function docx(array $design, string $name): string
    {
        $title = $design['title'] !== '' ? $design['title'] : $name;

        // Always on the ZESCO letterhead (the plain heading is only a fallback if its file is missing).
        $letterhead = $this->letterheadPath($design['letterhead'] ?? null);
        if ($letterhead) {
            return $this->onLetterhead($letterhead, $design, $title);
        }

        $this->width = self::WIDTH;
        $organisation = (string) config('pdts.builder.organisation', 'ZESCO Limited');
        // ZESCO's house font.
        $font = htmlspecialchars((string) config('pdts.builder.font', 'Tahoma'), ENT_XML1 | ENT_QUOTES);

        // The header leaves the top right free for the QR code.
        $body = WordXml::table([[
            WordXml::paragraph(WordXml::run(mb_strtoupper($organisation), ['bold' => true, 'size' => 24, 'color' => '075B35']), ['after' => 40])
            .($design['layout'] === 'letter' || ! ($design['show_title'] ?? true) ? '' : WordXml::paragraph(WordXml::run(mb_strtoupper($title), ['bold' => true, 'size' => 30]), ['after' => 0])),
            '',
        ]], [$this->width - 1900, 1900]);
        $body .= WordXml::paragraph('', ['after' => 200, 'borderBottom' => true]);

        $body .= match ($design['layout']) {
            'form' => $this->formBody($design['items']),
            'report' => $this->reportBody($design['items']),
            'custom' => $this->customBody($design['items'], $design['rows'] ?? self::defaultGrid($design['items'])),
            'letter' => $this->letterBody($design['items']),
            default => $this->memoBody($design['items']),
        };
        $body .= str_ends_with($body, '</w:tbl>') ? self::spacer(0) : '';

        return DocxPackage::write([
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
                .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
                .'<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>',
            'word/_rels/document.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
            'word/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
                .'<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="'.$font.'" w:hAnsi="'.$font.'" w:cs="'.$font.'" w:eastAsia="'.$font.'"/><w:sz w:val="22"/><w:szCs w:val="22"/><w:lang w:val="en-GB"/></w:rPr></w:rPrDefault>'
                .'<w:pPrDefault><w:pPr><w:spacing w:after="120" w:line="264" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults>'
                .'<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style></w:styles>',
            'word/document.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document '.WordXml::NS.'><w:body>'.$body
                .'<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134" w:header="567" w:footer="567" w:gutter="0"/></w:sectPr></w:body></w:document>',
        ]);
    }

    /**
     * The template written on a letterhead: the letterhead file as it is
     * (logo, fonts, margins, footer), with its title replaced by this
     * template's and its body replaced by this template's items.
     */
    private function onLetterhead(string $letterhead, array $design, string $title): string
    {
        $top = $this->letterheadTop($letterhead);
        $parts = $top['parts'];
        // No title under the logo (a letter, or a template with the title turned off): the letterhead's title line is emptied.
        $noTitle = $design['layout'] === 'letter' || ! ($design['show_title'] ?? true);

        if ($top['title']) {
            $texts = iterator_to_array($top['xpath']->query('.//w:t', $top['title']));
            foreach ($texts as $i => $t) {
                $t->textContent = $i === 0 && ! $noTitle ? mb_strtoupper($title) : '';
            }
        }

        // The width text can use, from the letterhead's own page and margins.
        $section = $top['sectPr'];
        $page = $section ? $top['xpath']->query('w:pgSz/@w:w', $section)->item(0)?->nodeValue : null;
        $left = $section ? $top['xpath']->query('w:pgMar/@w:left', $section)->item(0)?->nodeValue : null;
        $right = $section ? $top['xpath']->query('w:pgMar/@w:right', $section)->item(0)?->nodeValue : null;
        $this->width = $page && $left !== null && $right !== null ? max(4000, (int) $page - (int) $left - (int) $right) : self::WIDTH;

        $headXml = implode('', array_map(fn ($node) => $top['dom']->saveXML($node), $top['head']));
        if (! $top['title'] && ! $noTitle) {
            $headXml .= WordXml::paragraph(WordXml::run(mb_strtoupper($title), ['bold' => true, 'size' => 32]), ['align' => 'center', 'before' => 80, 'after' => 240]);
        }

        $body = match ($design['layout']) {
            'form' => $this->formBody($design['items']),
            'report' => $this->reportBody($design['items']),
            'custom' => $this->customBody($design['items'], $design['rows'] ?? self::defaultGrid($design['items'])),
            'letter' => $this->letterBody($design['items']),
            default => $this->letterheadMemoBody($design['items']),
        };
        // Word expects a paragraph, not a table, at the end of the body.
        $body .= str_ends_with($body, '</w:tbl>') ? self::spacer(0) : '';
        $body = $this->inLetterheadFont($body, $top);

        preg_match('#^.*?<w:body(?:\s[^>]*)?>#s', $parts['word/document.xml'], $opening);
        $parts['word/document.xml'] = ($opening[0] ?? '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document '.WordXml::NS.'><w:body>')
            .$headXml.$body.($section ? $top['dom']->saveXML($section) : '').'</w:body></w:document>';

        return DocxPackage::write($parts);
    }

    /**
     * The letterhead's own text font and size (from its first line of text
     * after the top, e.g. "TO:"), put on every run of the new body: letterheads
     * often set the font on each run rather than as the file's default.
     */
    private function inLetterheadFont(string $body, array $top): string
    {
        $fonts = '';
        $size = '';
        $after = false;
        foreach ($top['xpath']->query('/w:document/w:body/w:p') as $paragraph) {
            if (! $after) {
                $after = $top['head'] === [] || $paragraph === end($top['head']);

                continue;
            }
            foreach ($top['xpath']->query('.//w:r[w:t[normalize-space()]]', $paragraph) as $run) {
                $rFonts = $top['xpath']->query('w:rPr/w:rFonts', $run)->item(0);
                $sz = $top['xpath']->query('w:rPr/w:sz', $run)->item(0);
                $fonts = $rFonts ? $top['dom']->saveXML($rFonts) : '';
                $size = $sz ? '<w:sz w:val="'.$sz->getAttribute('w:val').'"/><w:szCs w:val="'.$sz->getAttribute('w:val').'"/>' : '';
                break 2;
            }
        }
        if ($fonts === '' && $size === '') {
            return $body;
        }
        // The saved element may carry its namespace declaration; it is already on the document.
        $fonts = preg_replace('/\s+xmlns:w="[^"]*"/', '', $fonts);

        $body = preg_replace_callback('#<w:r>(<w:rPr>(.*?)</w:rPr>)?#s', function (array $m) use ($fonts, $size) {
            $props = $m[2] ?? '';
            if (! str_contains($props, '<w:rFonts')) {
                $props = $fonts.$props;
            }
            if (! str_contains($props, '<w:sz ')) {
                $props .= $size;
            }

            return '<w:r><w:rPr>'.$props.'</w:rPr>';
        }, $body);

        return $body;
    }

    /**
     * Pairs of standard lines printed side by side, as in the ZESCO memo
     * (TO beside FROM, REF beside DATE); everything else on its own.
     *
     * @return list<list<int>> item indexes, one list per printed row
     */
    public static function memoRows(array $items): array
    {
        $pairable = fn ($item) => $item['kind'] === 'standard' && in_array($item['name'], ['to', 'from', 'office_reference', 'date'], true);
        $rows = [];
        $count = count($items);
        for ($i = 0; $i < $count; $i++) {
            if ($pairable($items[$i]) && $i + 1 < $count && $pairable($items[$i + 1])) {
                $rows[] = [$i, $i + 1];
                $i++;
            } else {
                $rows[] = [$i];
            }
        }

        return $rows;
    }

    /** Memo style on a letterhead, in the ZESCO memo's own way. */
    private function letterheadMemoBody(array $items): string
    {
        $xml = '';
        $half = (int) round($this->width / 2);
        $labelled = fn (array $item) => WordXml::run(rtrim($item['label'], ':').': ', ['bold' => true]).WordXml::run('${'.$item['name'].'}');

        foreach (self::memoRows($items) as $row) {
            $item = $items[$row[0]];
            if (count($row) === 2) {
                $xml .= '<w:p><w:pPr><w:tabs><w:tab w:val="left" w:pos="'.$half.'"/></w:tabs><w:spacing w:before="80" w:after="80"/></w:pPr>'
                    .$labelled($item).'<w:r><w:tab/></w:r>'.$labelled($items[$row[1]]).'</w:p>';

                continue;
            }

            $xml .= match (true) {
                $item['kind'] === 'standard' && $item['name'] === 'subject' => '<w:p><w:pPr><w:spacing w:before="240" w:after="240"/><w:jc w:val="both"/></w:pPr>'
                    .'<w:r><w:rPr><w:b/><w:caps/></w:rPr><w:t>${subject}</w:t></w:r></w:p>',
                // The names after the first lined up under it, as in the ZESCO memo. No line under it:
                // a divider is a Line item of its own, placed (and as thick) as the designer wants.
                $item['kind'] === 'standard' && $item['name'] === 'cc' => '<w:p><w:pPr>'
                    .'<w:spacing w:before="80" w:after="80"/><w:ind w:left="560" w:hanging="560"/></w:pPr>'.$labelled($item).'</w:p>',
                $item['kind'] === 'standard' || ($item['kind'] === 'question' && ! self::isParagraph($item)) => '<w:p><w:pPr><w:spacing w:before="80" w:after="80"/></w:pPr>'.$labelled($item).'</w:p>',
                $item['kind'] === 'question' => ($item['show_label'] ? WordXml::paragraph(WordXml::run($item['label'], ['bold' => true]), ['before' => 280, 'after' => 160, 'keepNext' => true]) : '')
                    .'<w:p><w:pPr><w:spacing w:after="160" w:line="276" w:lineRule="auto"/><w:jc w:val="both"/></w:pPr>'.WordXml::run('${'.$item['name'].'}').'</w:p>',
                $item['kind'] === 'section' => WordXml::paragraph(WordXml::run(mb_strtoupper($item['label']), ['bold' => true]), ['before' => 300, 'after' => 160, 'keepNext' => true]),
                $item['kind'] === 'note' => WordXml::paragraph(WordXml::run($item['text'], ['italic' => true])),
                $item['kind'] === 'signature' => ($item['label'] !== '' ? WordXml::paragraph(WordXml::run(rtrim($item['label'], ': '), ['bold' => true]), ['before' => 360, 'after' => 0, 'keepNext' => true]) : '')
                    .WordXml::paragraph(WordXml::run('${signature}'), ['before' => $item['label'] !== '' ? 120 : 360, 'after' => 120, 'keepNext' => true])
                    .WordXml::paragraph(WordXml::run('${author_upper}', ['bold' => true]), ['after' => 0]).self::jobTitleLine($item),
                default => $this->fixedPiece($item),
            };
        }

        return $xml;
    }

    /**
     * Letter style, as on the ZESCO letter: OUR REF (bold), the date, the
     * addressee's lines close together, "Dear …", the subject in bold capitals,
     * the text, then "Yours faithfully," / ZESCO LIMITED / signature / NAME /
     * job title, and cc at the end.
     */
    private function letterBody(array $items): string
    {
        $xml = '';
        $organisation = mb_strtoupper((string) config('pdts.builder.organisation', 'ZESCO Limited'));
        $afterSubject = false;

        foreach ($items as $item) {
            $standard = $item['kind'] === 'standard' ? $item['name'] : null;
            $xml .= match (true) {
                $standard === 'office_reference' => WordXml::paragraph(WordXml::run(rtrim($item['label'], ': ').': ', ['bold' => true]).WordXml::run('${office_reference}', ['bold' => true]), ['after' => 240]),
                $standard === 'date' => WordXml::paragraph(WordXml::run('${date}'), ['after' => 240]),
                $standard === 'subject' => WordXml::paragraph(WordXml::run('${subject}', ['bold' => true, 'caps' => true]), ['before' => 120, 'after' => 240]),
                $standard === 'cc' => WordXml::paragraph(WordXml::run(rtrim($item['label'], ': ').': ').WordXml::run('${cc}'), ['before' => 360, 'after' => 0]),
                $standard !== null => WordXml::paragraph(WordXml::run(rtrim($item['label'], ': ').': ', ['bold' => true]).WordXml::run('${'.$standard.'}'), ['after' => 120]),
                self::isGreeting($item) => WordXml::paragraph(WordXml::run(rtrim($item['label'], ' :,').' ').WordXml::run('${'.$item['name'].'}'), ['before' => 240, 'after' => 240]),
                $item['kind'] === 'question' && ! self::isParagraph($item) => WordXml::paragraph(WordXml::run(rtrim($item['label'], ': ').': ', ['bold' => true]).WordXml::run('${'.$item['name'].'}'), ['after' => 120]),
                // Before the subject (the addressee): its lines close together. After it: the letter's paragraphs.
                $item['kind'] === 'question' => ($item['show_label'] ? WordXml::paragraph(WordXml::run($item['label'], ['bold' => true]), ['before' => 280, 'after' => 160, 'keepNext' => true]) : '')
                    .($afterSubject
                        ? '<w:p><w:pPr><w:spacing w:after="200" w:line="276" w:lineRule="auto"/><w:jc w:val="both"/></w:pPr>'.WordXml::run('${'.$item['name'].'}').'</w:p>'
                        : '<w:p><w:pPr><w:spacing w:after="0"/></w:pPr>'.WordXml::run('${'.$item['name'].'}').'</w:p>'),
                $item['kind'] === 'section' => WordXml::paragraph(WordXml::run(mb_strtoupper($item['label']), ['bold' => true]), ['before' => 300, 'after' => 160, 'keepNext' => true]),
                $item['kind'] === 'note' => WordXml::paragraph(WordXml::run($item['text'], ['italic' => true])),
                $item['kind'] === 'signature' => WordXml::paragraph(WordXml::run($item['label'] !== '' ? $item['label'] : 'Yours faithfully,'), ['before' => 240, 'after' => 60, 'keepNext' => true])
                    .WordXml::paragraph(WordXml::run($organisation, ['bold' => true]), ['after' => 120, 'keepNext' => true])
                    .WordXml::paragraph(WordXml::run('${signature}'), ['before' => 120, 'after' => 120, 'keepNext' => true])
                    .WordXml::paragraph(WordXml::run('${author_upper}', ['bold' => true]), ['after' => 0, 'keepNext' => true])
                    .self::jobTitleLine($item),
                default => $this->fixedPiece($item),
            };
            $afterSubject = $afterSubject || $standard === 'subject';
        }

        return $xml;
    }

    /** Memo style: short lines as label and answer, paragraphs under their heading. */
    private function memoBody(array $items): string
    {
        $xml = '';
        // The answers line up after the longest label (about 135 twips a bold character at 11 pt),
        // between 1.5 and 3 inches.
        $longest = collect($items)
            ->filter(fn ($item) => ($item['kind'] === 'standard' && $item['name'] !== 'subject') || ($item['kind'] === 'question' && ! self::isParagraph($item)))
            ->map(fn ($item) => mb_strlen(rtrim($item['label'], ':')) + 1)
            ->max() ?? 0;
        $column = (int) min(4320, max(2160, ceil(($longest * 135 + 300) / 360) * 360));
        // "TO:<tab>${to}" on one line, as in the ZESCO memo; a long answer wraps under itself.
        $line = fn (string $label, string $name) => '<w:p><w:pPr><w:tabs><w:tab w:val="left" w:pos="'.$column.'"/></w:tabs>'
            .'<w:spacing w:before="0" w:after="80"/><w:ind w:left="'.$column.'" w:hanging="'.$column.'"/></w:pPr>'
            .WordXml::run(rtrim($label, ':').':', ['bold' => true]).'<w:r><w:tab/></w:r>'.WordXml::run('${'.$name.'}').'</w:p>';

        foreach ($items as $item) {
            $short = ($item['kind'] === 'standard' && $item['name'] !== 'subject') || ($item['kind'] === 'question' && ! self::isParagraph($item));
            if ($short) {
                $xml .= $line($item['label'], $item['name']);

                continue;
            }

            $xml .= match ($item['kind']) {
                'standard' => WordXml::paragraph(WordXml::run(rtrim($item['label'], ':').': ', ['bold' => true]).WordXml::run('${subject}', ['bold' => true]), ['before' => 160, 'after' => 160, 'borderBottom' => true]),
                'question' => ($item['show_label'] ? WordXml::paragraph(WordXml::run($item['label'], ['bold' => true]), ['before' => 280, 'after' => 160, 'keepNext' => true]) : '')
                    .WordXml::paragraph(WordXml::run('${'.$item['name'].'}')),
                'section' => WordXml::paragraph(WordXml::run(mb_strtoupper($item['label']), ['bold' => true, 'color' => '075B35']), ['before' => 240, 'after' => 80, 'keepNext' => true]),
                'note' => WordXml::paragraph(WordXml::run($item['text'], ['italic' => true, 'size' => 20])),
                'signature' => $this->signatureBlock($item),
                default => $this->fixedPiece($item),
            };
        }

        return $xml;
    }

    /**
     * Report numbering: each section heading is 1, 2, 3; a paragraph's
     * heading inside a section is 1.1, 1.2; before any section, a paragraph's
     * heading is numbered like a section.
     *
     * @return array<int, string> item index => number
     */
    public static function reportNumbers(array $items): array
    {
        $numbers = [];
        $section = 0;
        $sub = 0;
        $inSection = false;
        foreach (array_values($items) as $i => $item) {
            if ($item['kind'] === 'section') {
                $numbers[$i] = (string) ++$section;
                $sub = 0;
                $inSection = true;
            } elseif (self::isParagraph($item) && $item['show_label']) {
                $numbers[$i] = $inSection ? $section.'.'.(++$sub) : (string) ++$section;
            }
        }

        return $numbers;
    }

    /**
     * Report style: the short details in a bordered table, the subject in
     * capitals, numbered sections and paragraphs, and a "Prepared by"
     * signature block.
     */
    private function reportBody(array $items): string
    {
        $numbers = self::reportNumbers($items);
        $xml = '';
        $rows = '';
        $labelWidth = 3200;
        $cell = fn (string $content, int $width, ?string $fill = null) => '<w:tc><w:tcPr><w:tcW w:w="'.$width.'" w:type="dxa"/>'
            .($fill ? '<w:shd w:val="clear" w:color="auto" w:fill="'.$fill.'"/>' : '').'</w:tcPr>'.$content.'</w:tc>';
        $p = fn (string $runs) => WordXml::paragraph($runs, ['before' => 40, 'after' => 40]);
        // No empty line after the table: PDTS reads an empty line after the subject as the body space.
        $flush = function () use (&$rows, &$xml, $labelWidth) {
            if ($rows !== '') {
                $border = '<w:top w:val="single" w:sz="4" w:color="9AA5A5"/><w:left w:val="single" w:sz="4" w:color="9AA5A5"/><w:bottom w:val="single" w:sz="4" w:color="9AA5A5"/>'
                    .'<w:right w:val="single" w:sz="4" w:color="9AA5A5"/><w:insideH w:val="single" w:sz="4" w:color="9AA5A5"/><w:insideV w:val="single" w:sz="4" w:color="9AA5A5"/>';
                $xml .= '<w:tbl><w:tblPr><w:tblW w:w="'.$this->width.'" w:type="dxa"/><w:tblLayout w:type="fixed"/><w:tblBorders>'.$border.'</w:tblBorders>'
                    .'<w:tblCellMar><w:left w:w="100" w:type="dxa"/><w:right w:w="100" w:type="dxa"/></w:tblCellMar></w:tblPr>'
                    .'<w:tblGrid><w:gridCol w:w="'.$labelWidth.'"/><w:gridCol w:w="'.($this->width - $labelWidth).'"/></w:tblGrid>'.$rows.'</w:tbl>';
                $rows = '';
            }
        };

        foreach (array_values($items) as $i => $item) {
            $short = ($item['kind'] === 'standard' && $item['name'] !== 'subject') || ($item['kind'] === 'question' && ! self::isParagraph($item));
            if ($short) {
                $rows .= '<w:tr>'.$cell($p(WordXml::run(rtrim($item['label'], ':'), ['bold' => true])), $labelWidth, 'EEF3F3')
                    .$cell($p(WordXml::run('${'.$item['name'].'}')), $this->width - $labelWidth).'</w:tr>';

                continue;
            }
            $flush();

            $xml .= match (true) {
                $item['kind'] === 'standard' => '<w:p><w:pPr><w:spacing w:before="280" w:after="200"/><w:jc w:val="both"/></w:pPr>'
                    .'<w:r><w:rPr><w:b/><w:caps/></w:rPr><w:t>${subject}</w:t></w:r></w:p>',
                $item['kind'] === 'section' => WordXml::paragraph(WordXml::run(($numbers[$i] ?? '').'.  '.mb_strtoupper($item['label']), ['bold' => true]), ['before' => 280, 'after' => 160, 'keepNext' => true]),
                $item['kind'] === 'question' => ($item['show_label'] ? WordXml::paragraph(WordXml::run(($numbers[$i] ?? '').(str_contains($numbers[$i] ?? '', '.') ? '  ' : '.  ').$item['label'], ['bold' => true]), ['before' => 280, 'after' => 160, 'keepNext' => true]) : '')
                    .'<w:p><w:pPr><w:spacing w:after="160" w:line="276" w:lineRule="auto"/><w:jc w:val="both"/></w:pPr>'.WordXml::run('${'.$item['name'].'}').'</w:p>',
                $item['kind'] === 'note' => WordXml::paragraph(WordXml::run($item['text'], ['italic' => true])),
                // No colon: "Label:" lines are read as fields to fill in.
                $item['kind'] === 'signature' => WordXml::paragraph(WordXml::run(rtrim($item['label'] !== '' ? $item['label'] : 'Prepared by', ': '), ['bold' => true]), ['before' => 400, 'after' => 0, 'keepNext' => true])
                    .WordXml::paragraph(WordXml::run('${signature}'), ['before' => 120, 'after' => 120, 'keepNext' => true])
                    .WordXml::paragraph(WordXml::run('${author_upper}', ['bold' => true]), ['after' => 0]).self::jobTitleLine($item),
                default => $this->fixedPiece($item),
            };
        }
        $flush();

        return $xml;
    }

    /**
     * A paragraph holding only a no-break space: it keeps its height, and is
     * not taken for an empty line to write in (PDTS reads an empty line after
     * the subject as the body space).
     */
    private static function spacer(int $after = 240, int $size = 4): string
    {
        return '<w:p><w:pPr><w:spacing w:before="0" w:after="'.$after.'"/></w:pPr><w:r><w:rPr><w:sz w:val="'.$size.'"/><w:szCs w:val="'.$size.'"/></w:rPr><w:t xml:space="preserve">'."\u{00A0}".'</w:t></w:r></w:p>';
    }

    /** The paragraph stays on the same page as the one after it. */
    private static function keepWithNext(string $paragraph): string
    {
        return str_contains($paragraph, '<w:pPr>')
            ? preg_replace('/<w:pPr>/', '<w:pPr><w:keepNext/>', $paragraph, 1)
            : preg_replace('/<w:p>/', '<w:p><w:pPr><w:keepNext/></w:pPr>', $paragraph, 1);
    }

    /** A full-width horizontal line; $size in eighths of a point (8 = 1 pt). */
    private static function rule(int $size = 8): string
    {
        return '<w:p><w:pPr><w:pBdr><w:bottom w:val="single" w:sz="'.$size.'" w:space="1" w:color="000000"/></w:pBdr><w:spacing w:before="60" w:after="160"/></w:pPr>'
            .'<w:r><w:rPr><w:sz w:val="4"/><w:szCs w:val="4"/></w:rPr><w:t xml:space="preserve">'."\u{00A0}".'</w:t></w:r></w:p>';
    }

    /**
     * Pieces printed as they are, in any layout: fixed text, a line, a space,
     * and a block signed by hand on paper ("Approved by", a line to sign on).
     */
    private function fixedPiece(array $item, ?callable $paragraph = null): string
    {
        $paragraph ??= fn (string $runs, int $before = 40, int $after = 80) => WordXml::paragraph($runs, ['before' => $before, 'after' => $after]);
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) $item['text'])), fn ($l) => $l !== ''));

        return match ($item['kind']) {
            'text' => implode('', array_map(fn ($line) => $paragraph(WordXml::run($line)), $lines)),
            'line' => self::rule(self::LINE_WEIGHTS[$item['weight'] ?? 'thin'][1] ?? 8),
            'space' => self::spacer(240, 22),
            // What follows starts on a new page.
            'pagebreak' => '<w:p><w:r><w:br w:type="page"/></w:r></w:p>',
            // No colons: "Label:" lines are read as fields to fill in.
            // The label, the line and "Name, signature and date" stay on one page.
            'signline' => ($item['label'] !== '' ? self::keepWithNext($paragraph(WordXml::run(rtrim($item['label'], ': '), ['bold' => true]), 240, 0)) : '')
                .self::keepWithNext($paragraph(WordXml::run('______________________________'), 560, 20))
                .$paragraph(WordXml::run('Name, signature and date', ['size' => 18, 'color' => '586A6B']), 0, 80),
            default => '',
        };
    }

    /**
     * Custom layout: rows of 1, 2 or 3 columns, each item in its own style.
     * A row of several columns is a borderless table, so text flows and
     * long answers push the rest down, onto further pages if needed.
     */
    private function customBody(array $items, array $rows): string
    {
        $byId = array_column($items, null, 'id');
        $xml = '';
        foreach ($rows as $row) {
            $cells = array_slice(array_values($row['cells']), 0, $row['columns']);
            if ($row['space'] !== 'none') {
                $xml .= self::spacer($row['space'] === 'large' ? 360 : 120);
            }

            if ($row['columns'] === 1) {
                foreach ($cells[0] ?? [] as $id) {
                    $xml .= isset($byId[$id]) ? $this->customItem($byId[$id]) : '';
                }
            } else {
                // Two tables side by side would join into one: keep them apart.
                if (str_ends_with($xml, '</w:tbl>')) {
                    $xml .= self::spacer(0);
                }
                $width = intdiv($this->width, $row['columns']);
                $nil = '<w:top w:val="nil"/><w:left w:val="nil"/><w:bottom w:val="nil"/><w:right w:val="nil"/><w:insideH w:val="nil"/><w:insideV w:val="nil"/>';
                $xml .= '<w:tbl><w:tblPr><w:tblW w:w="'.($width * $row['columns']).'" w:type="dxa"/><w:tblLayout w:type="fixed"/><w:tblBorders>'.$nil.'</w:tblBorders>'
                    .'<w:tblCellMar><w:left w:w="0" w:type="dxa"/><w:right w:w="160" w:type="dxa"/></w:tblCellMar></w:tblPr>'
                    .'<w:tblGrid>'.str_repeat('<w:gridCol w:w="'.$width.'"/>', $row['columns']).'</w:tblGrid><w:tr>';
                foreach ($cells as $cell) {
                    $content = implode('', array_map(fn ($id) => isset($byId[$id]) ? $this->customItem($byId[$id]) : '', $cell));
                    $xml .= '<w:tc><w:tcPr><w:tcW w:w="'.$width.'" w:type="dxa"/></w:tcPr>'.($content !== '' ? $content : '<w:p/>').'</w:tc>';
                }
                $xml .= '</w:tr></w:tbl>';
            }

            if ($row['line']) {
                $xml .= self::rule();
            }
        }

        return $xml;
    }

    /** One item of the Custom layout, in its style: alignment, emphasis, size, label place and box. */
    private function customItem(array $item): string
    {
        $style = $item['style'] ?? self::STYLE;
        $size = ['small' => 18, 'large' => 28][$style['size']] ?? null;
        $answer = fn (string $text) => WordXml::run($text, array_filter(['bold' => $style['bold'], 'italic' => $style['italic'], 'caps' => $style['caps'], 'size' => $size]));
        $labelRun = fn (string $text) => WordXml::run($text, array_filter(['bold' => true, 'caps' => $style['caps'], 'size' => $size]));
        $box = match ($style['box']) {
            'border' => '<w:pBdr><w:top w:val="single" w:sz="4" w:space="2" w:color="9AA5A5"/><w:left w:val="single" w:sz="4" w:space="4" w:color="9AA5A5"/>'
                .'<w:bottom w:val="single" w:sz="4" w:space="2" w:color="9AA5A5"/><w:right w:val="single" w:sz="4" w:space="4" w:color="9AA5A5"/></w:pBdr>',
            'shaded' => '<w:shd w:val="clear" w:color="auto" w:fill="EEF3F3"/>',
            default => '',
        };
        $paragraph = fn (string $runs, int $before = 40, int $after = 80, bool $keepNext = false) => '<w:p><w:pPr>'.($keepNext ? '<w:keepNext/>' : '').$box
            .'<w:spacing w:before="'.$before.'" w:after="'.$after.'"/><w:jc w:val="'.$style['align'].'"/></w:pPr>'.$runs.'</w:p>';
        $label = rtrim((string) $item['label'], ':');
        $placeholder = '${'.$item['name'].'}';
        // Tight: no space after the line, so lines sit close together (an address block, "Yours faithfully," / ZESCO LIMITED).
        $after = ($style['tight'] ?? false) ? 0 : 80;

        if ($item['kind'] === 'standard' || ($item['kind'] === 'question' && ! self::isParagraph($item))) {
            return match ($style['label']) {
                'above' => $paragraph($labelRun($label), 60, 0, true).$paragraph($answer($placeholder), 0, $after),
                'hidden' => $paragraph($answer($placeholder), 40, $after),
                // "Dear Mr. Banda": the label in the answer's own look, no colon.
                'plain' => $paragraph($answer(rtrim($label, ' ,').' ').$answer($placeholder), 40, $after),
                default => $paragraph($labelRun($label.': ').$answer($placeholder), 40, $after),
            };
        }

        return match ($item['kind']) {
            'question' => ($style['label'] !== 'hidden' && $item['show_label'] ? $paragraph($labelRun($label), 280, 160, true) : '')
                .$paragraph($answer($placeholder), 0, ($style['tight'] ?? false) ? 0 : 120),
            'section' => $paragraph($answer($label), 300, 160, true),
            'note', 'text' => implode('', array_map(fn ($line) => $paragraph($answer($line), ($style['tight'] ?? false) ? 0 : 40, $after), array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) $item['text'])), fn ($l) => $l !== '')))),
            'signature' => ($label !== '' ? $paragraph(WordXml::run(rtrim($label, ': '), ['bold' => true]), 240, 0, true) : '')
                .$paragraph(WordXml::run('${signature}'), $label !== '' ? 60 : 240, 60, true)
                .$paragraph(WordXml::run('${author_upper}', ['bold' => true]), 0, ! empty($item['job_title']) ? 0 : 80, ! empty($item['job_title']))
                .(! empty($item['job_title']) ? $paragraph(WordXml::run('${author_job_title}'), 0, 80) : ''),
            default => $this->fixedPiece($item, $paragraph),
        };
    }

    /** Form style: a bordered table; paragraphs and headings take the full width. */
    private function formBody(array $items): string
    {
        $xml = '';
        $rows = '';
        $labelWidth = 3200;
        $cell = fn (string $content, int $width, ?string $fill = null, int $span = 1) => '<w:tc><w:tcPr><w:tcW w:w="'.$width.'" w:type="dxa"/>'
            .($span > 1 ? '<w:gridSpan w:val="'.$span.'"/>' : '')
            .($fill ? '<w:shd w:val="clear" w:color="auto" w:fill="'.$fill.'"/>' : '').'</w:tcPr>'.$content.'</w:tc>';
        $p = fn (string $runs) => WordXml::paragraph($runs, ['before' => 40, 'after' => 40]);
        $flush = function () use (&$rows, &$xml, $labelWidth) {
            if ($rows !== '') {
                $border = '<w:top w:val="single" w:sz="4" w:color="9AA5A5"/><w:left w:val="single" w:sz="4" w:color="9AA5A5"/><w:bottom w:val="single" w:sz="4" w:color="9AA5A5"/>'
                    .'<w:right w:val="single" w:sz="4" w:color="9AA5A5"/><w:insideH w:val="single" w:sz="4" w:color="9AA5A5"/><w:insideV w:val="single" w:sz="4" w:color="9AA5A5"/>';
                $xml .= '<w:tbl><w:tblPr><w:tblW w:w="'.$this->width.'" w:type="dxa"/><w:tblLayout w:type="fixed"/><w:tblBorders>'.$border.'</w:tblBorders>'
                    .'<w:tblCellMar><w:left w:w="100" w:type="dxa"/><w:right w:w="100" w:type="dxa"/></w:tblCellMar></w:tblPr>'
                    .'<w:tblGrid><w:gridCol w:w="'.$labelWidth.'"/><w:gridCol w:w="'.($this->width - $labelWidth).'"/></w:tblGrid>'.$rows.'</w:tbl>';
                $xml .= WordXml::paragraph('', ['after' => 0]);
                $rows = '';
            }
        };

        foreach ($items as $item) {
            $long = self::isParagraph($item);
            switch (true) {
                case $item['kind'] === 'standard' || ($item['kind'] === 'question' && ! $long):
                    $rows .= '<w:tr>'.$cell($p(WordXml::run(rtrim($item['label'], ':'), ['bold' => true, 'size' => 20])), $labelWidth, 'EEF3F3')
                        .$cell($p(WordXml::run('${'.$item['name'].'}')), $this->width - $labelWidth).'</w:tr>';
                    break;
                case $long:
                    if ($item['show_label']) {
                        $rows .= '<w:tr>'.$cell($p(WordXml::run($item['label'], ['bold' => true, 'size' => 20])), $this->width, 'EEF3F3', 2).'</w:tr>';
                    }
                    $rows .= '<w:tr>'.$cell($p(WordXml::run('${'.$item['name'].'}')), $this->width, null, 2).'</w:tr>';
                    break;
                case $item['kind'] === 'section':
                    $rows .= '<w:tr>'.$cell($p(WordXml::run(mb_strtoupper($item['label']), ['bold' => true, 'color' => 'FFFFFF'])), $this->width, '075B35', 2).'</w:tr>';
                    break;
                case $item['kind'] === 'note':
                    $rows .= '<w:tr>'.$cell($p(WordXml::run($item['text'], ['italic' => true, 'size' => 20])), $this->width, null, 2).'</w:tr>';
                    break;
                case $item['kind'] === 'signature':
                    $flush();
                    $xml .= $this->signatureBlock($item);
                    break;
                default:
                    $flush();
                    $xml .= $this->fixedPiece($item);
            }
        }
        $flush();

        return $xml;
    }

    /** The signatory's job title under the name, when the signature block asks for it. */
    private static function jobTitleLine(array $item): string
    {
        return ! empty($item['job_title']) ? WordXml::paragraph(WordXml::run('${author_job_title}'), ['after' => 0]) : '';
    }

    private function signatureBlock(array $item): string
    {
        // No empty lines: PDTS reads an empty line after the subject as the body space.
        return ($item['label'] !== '' ? WordXml::paragraph(WordXml::run(rtrim($item['label'], ': '), ['bold' => true]), ['before' => 360, 'after' => 0, 'keepNext' => true]) : '')
            .WordXml::paragraph(WordXml::run('${signature}'), ['before' => $item['label'] !== '' ? 0 : 360, 'after' => 0, 'keepNext' => true])
            .WordXml::paragraph(WordXml::run('${author}', ['bold' => true]), ['after' => 0]).self::jobTitleLine($item);
    }
}
