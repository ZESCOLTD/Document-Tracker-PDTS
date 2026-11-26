<?php

namespace App\Services\Pdts\Templates;

use RuntimeException;

/**
 * Draws a QR code onto pages of an existing PDF, without changing anything
 * already in it.
 *
 * The change is appended as an "incremental update": the original bytes stay
 * as they are, and new content streams plus a new cross-reference section are
 * added at the end, which every PDF reader understands. The QR code is drawn
 * as filled squares, so no image, font or other resource is needed and the
 * page's own resources are left alone.
 *
 * Both classic cross-reference tables and the compressed cross-reference
 * streams most current PDF writers use are read. Encrypted PDFs are refused.
 */
class PdfQrStamper
{
    /** Font resource name for the reference text, unlikely to clash with the page's own. */
    private const FONT_NAME = 'PdtsHelv';

    /** Reference text size in points, as in the Word templates. */
    private const LABEL_POINTS = 6.5;

    /** Helvetica advance widths (per 1000 units) for the characters references use. */
    private const HELVETICA_WIDTHS = [
        'A' => 667, 'B' => 667, 'C' => 722, 'D' => 722, 'E' => 667, 'F' => 611, 'G' => 778, 'H' => 722, 'I' => 278,
        'J' => 500, 'K' => 667, 'L' => 556, 'M' => 833, 'N' => 722, 'O' => 778, 'P' => 667, 'Q' => 778, 'R' => 722,
        'S' => 667, 'T' => 611, 'U' => 722, 'V' => 667, 'W' => 944, 'X' => 667, 'Y' => 667, 'Z' => 611,
        '-' => 333, '/' => 278, '.' => 278, ' ' => 278, '_' => 556, 'f' => 278, 'i' => 222, 'j' => 222, 'l' => 222,
        'm' => 833, 'r' => 333, 't' => 278, 'w' => 722,
    ];

    private string $pdf = '';

    /** @var array<int, array{0: int, 1: int, 2?: int}> object number => [1, offset, gen] or [2, stream object, index] */
    private array $xref = [];

    /** @var array<string, mixed> the newest trailer */
    private array $trailer = [];

    private bool $usesXrefStream = false;

    private int $lastXrefOffset = 0;

    /** @var array<int, array<int, string>> decoded object streams: number => [index => source] */
    private array $objectStreams = [];

    /**
     * Number of pages, and each page's displayed size in millimetres.
     *
     * @return list<array{width: float, height: float}>
     */
    public function pages(string $pdf): array
    {
        $this->open($pdf);

        return array_map(function (array $page) {
            [$width, $height] = $this->displaySize($page);

            return ['width' => round($width * 25.4 / 72, 1), 'height' => round($height * 25.4 / 72, 1)];
        }, $this->pageList());
    }

    /**
     * @param  list<int>|'all'  $pages  1-based page numbers
     * @param  list<list<bool>>  $modules  QR modules, row by row from the top
     * @param  float  $xMm  left edge of the QR, from the page's left edge
     * @param  float  $yMm  top edge of the QR, from the page's top edge
     * @param  float  $sizeMm  width and height of the QR, quiet zone included
     * @param  string|null  $label  text printed centred under the QR (the tracking reference)
     */
    public function stamp(string $pdf, array|string $pages, array $modules, float $xMm, float $yMm, float $sizeMm, ?string $label = null): string
    {
        $this->open($pdf);
        $all = $this->pageList();

        $numbers = $pages === 'all' ? range(1, count($all)) : array_values(array_unique($pages));
        $numbers = array_values(array_filter($numbers, fn (int $n) => $n >= 1 && $n <= count($all)));
        if ($numbers === []) {
            throw new RuntimeException('That page is not in the PDF.');
        }

        $nextObject = (int) $this->trailerValue('Size');
        $objects = []; // object number => [generation, body]

        $label = $label === null ? '' : trim((string) preg_replace('/[^\x20-\x7E]+/', '', $label));
        $font = null;
        if ($label !== '') {
            // Helvetica is one of the standard fonts every PDF reader has, so nothing is embedded.
            $font = $nextObject++;
            $objects[$font] = [0, '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>'];
        }

        foreach ($numbers as $number) {
            $page = $all[$number - 1];

            $before = $nextObject++;
            $objects[$before] = [0, $this->streamObject("q\n")];
            $after = $nextObject++;
            $objects[$after] = [0, $this->streamObject("Q\n".$this->qrDrawing($page, $modules, $xMm, $yMm, $sizeMm, $label))];

            $dict = $page['dict'];
            if ($font !== null) {
                // The page's own resources, plus the font under a name of PDTS's own.
                $resources = $this->resolve($page['resources'] ?? null);
                $resources = is_array($resources) && ! isset($resources['__arr']) ? $resources : [];
                $fonts = $this->resolve($resources['Font'] ?? null);
                $fonts = is_array($fonts) && ! isset($fonts['__arr']) ? $fonts : [];
                $fonts[self::FONT_NAME] = ['__ref' => [$font, 0]];
                $resources['Font'] = $fonts;
                $dict['Resources'] = $resources;
            }
            $contents = $this->contentRefs($dict['Contents'] ?? null);
            $dict['Contents'] = ['__arr' => [
                ['__ref' => [$before, 0]],
                ...$contents,
                ['__ref' => [$after, 0]],
            ]];
            $objects[$page['ref'][0]] = [$page['ref'][1], $this->serialize($dict)];
        }

        return $this->appendUpdate($objects, $nextObject);
    }

    // ----------------------------------------------------------------------
    // Reading
    // ----------------------------------------------------------------------

    private function open(string $pdf): void
    {
        if (! str_contains(substr($pdf, 0, 1024), '%PDF-')) {
            throw new RuntimeException('This file is not a PDF.');
        }

        $this->pdf = $pdf;
        $this->xref = [];
        $this->trailer = [];
        $this->objectStreams = [];

        // The last startxref in the file points to the newest cross-reference section.
        if (! preg_match_all('/startxref\s+(\d+)\s+%%EOF/', substr($pdf, -4096), $m) || $m[1] === []) {
            throw new RuntimeException('This PDF could not be read.');
        }

        $this->lastXrefOffset = (int) end($m[1]);
        $this->readXrefChain($this->lastXrefOffset);

        if (isset($this->trailer['Encrypt'])) {
            throw new RuntimeException('This PDF is password-protected or encrypted. Save an unprotected copy and upload that.');
        }
    }

    private function readXrefChain(int $offset): void
    {
        $seen = [];
        $first = true;

        while ($offset > 0 && ! isset($seen[$offset])) {
            $seen[$offset] = true;
            $offset = $this->findXrefNear($offset);

            if (substr($this->pdf, $offset, 4) === 'xref') {
                $trailer = $this->readXrefTable($offset);
                if ($first) {
                    $this->usesXrefStream = false;
                }
                // A "hybrid" file also points to an xref stream for newer objects.
                if (isset($trailer['XRefStm'])) {
                    $this->readXrefStream((int) $trailer['XRefStm']);
                }
            } else {
                $trailer = $this->readXrefStream($offset);
                if ($first) {
                    $this->usesXrefStream = true;
                }
            }

            if ($first) {
                $this->trailer = $trailer;
                $first = false;
            }

            $offset = isset($trailer['Prev']) ? (int) $trailer['Prev'] : 0;
        }

        if (! isset($this->trailer['Root'])) {
            throw new RuntimeException('This PDF could not be read.');
        }
    }

    /** Some writers' offsets are slightly off; look a little either side. */
    private function findXrefNear(int $offset): int
    {
        // Right where it says (most writers, Word included).
        if (substr($this->pdf, $offset, 4) === 'xref' || preg_match('/\G\d+\s+\d+\s+obj/', $this->pdf, $exact, 0, $offset)) {
            return $offset;
        }

        // Otherwise the nearest one. Not the "xref" at the end of "startxref": Word writes
        // the "startxref" of the previous section just before the next table.
        $from = max(0, $offset - 20);
        if (preg_match_all('/(?<!start)xref|\d+\s+\d+\s+obj/', substr($this->pdf, $from, 60), $m, PREG_OFFSET_CAPTURE)) {
            $best = null;
            foreach ($m[0] as [, $at]) {
                if ($best === null || abs($from + $at - $offset) < abs($best - $offset)) {
                    $best = $from + $at;
                }
            }

            return $best;
        }

        return $offset;
    }

    /** @return array<string, mixed> the trailer dictionary */
    private function readXrefTable(int $offset): array
    {
        $pos = $offset + 4;
        $length = strlen($this->pdf);

        while ($pos < $length) {
            $this->skipSpace($pos);
            if (substr($this->pdf, $pos, 7) === 'trailer') {
                $pos += 7;

                return $this->parseValue($pos);
            }

            if (! preg_match('/\G(\d+)\s+(\d+)/', $this->pdf, $m, 0, $pos)) {
                throw new RuntimeException('This PDF could not be read.');
            }
            $pos += strlen($m[0]);
            $start = (int) $m[1];
            $count = (int) $m[2];

            for ($i = 0; $i < $count; $i++) {
                $this->skipSpace($pos);
                if (! preg_match('/\G(\d{10})\s+(\d{5})\s+([nf])/', $this->pdf, $e, 0, $pos)) {
                    throw new RuntimeException('This PDF could not be read.');
                }
                $pos += strlen($e[0]);
                $number = $start + $i;
                if ($e[3] === 'n' && ! isset($this->xref[$number])) {
                    $this->xref[$number] = [1, (int) $e[1], (int) $e[2]];
                } elseif (! isset($this->xref[$number])) {
                    $this->xref[$number] = [0, 0, 0];
                }
            }
        }

        throw new RuntimeException('This PDF could not be read.');
    }

    /** @return array<string, mixed> the stream's dictionary, which is also its trailer */
    private function readXrefStream(int $offset): array
    {
        $pos = $offset;
        [$dict, $data] = $this->parseIndirectAt($pos);

        if (! is_array($dict) || ($dict['Type'] ?? null) !== ['__name' => 'XRef'] || $data === null) {
            throw new RuntimeException('This PDF could not be read.');
        }

        $widths = array_map('intval', $dict['W']['__arr']);
        $index = isset($dict['Index']) ? array_map('intval', $dict['Index']['__arr']) : [0, (int) $dict['Size']];
        $rowLength = array_sum($widths);
        $row = 0;

        for ($i = 0; $i + 1 < count($index); $i += 2) {
            for ($n = 0; $n < $index[$i + 1]; $n++, $row++) {
                $bytes = substr($data, $row * $rowLength, $rowLength);
                if (strlen($bytes) < $rowLength) {
                    break 2;
                }
                $fields = [];
                $p = 0;
                foreach ($widths as $w) {
                    $value = 0;
                    for ($b = 0; $b < $w; $b++) {
                        $value = ($value << 8) | ord($bytes[$p++]);
                    }
                    $fields[] = $value;
                }
                $type = $widths[0] === 0 ? 1 : $fields[0];
                $number = $index[$i] + $n;

                if (isset($this->xref[$number])) {
                    continue;
                }
                $this->xref[$number] = match ($type) {
                    1 => [1, $fields[1], $fields[2] ?? 0],
                    2 => [2, $fields[1], $fields[2] ?? 0],
                    default => [0, 0, 0],
                };
            }
        }

        return $dict;
    }

    /**
     * @return array{0: mixed, 1: ?string} the object and, for streams, its decoded data
     */
    private function parseIndirectAt(int &$pos): array
    {
        $this->skipSpace($pos);
        if (! preg_match('/\G\d+\s+\d+\s+obj/', $this->pdf, $m, 0, $pos)) {
            throw new RuntimeException('This PDF could not be read.');
        }
        $pos += strlen($m[0]);
        $value = $this->parseValue($pos);

        $this->skipSpace($pos);
        if (is_array($value) && substr($this->pdf, $pos, 6) === 'stream') {
            $pos += 6;
            if (($this->pdf[$pos] ?? '') === "\r") {
                $pos++;
            }
            if (($this->pdf[$pos] ?? '') === "\n") {
                $pos++;
            }
            $length = $this->resolve($value['Length'] ?? 0);
            $raw = substr($this->pdf, $pos, (int) $length);
            // A wrong /Length is common; fall back to the endstream marker.
            if (! preg_match('/^\s*endstream/', substr($this->pdf, $pos + (int) $length, 20))) {
                $end = strpos($this->pdf, 'endstream', $pos);
                $raw = rtrim(substr($this->pdf, $pos, $end - $pos), "\r\n");
            }

            return [$value, $this->decodeStream($value, $raw)];
        }

        return [$value, null];
    }

    private function decodeStream(array $dict, string $raw): string
    {
        $filters = $dict['Filter'] ?? null;
        $filters = $filters === null ? [] : (isset($filters['__arr']) ? $filters['__arr'] : [$filters]);
        $params = $dict['DecodeParms'] ?? null;
        $params = isset($params['__arr']) ? $params['__arr'] : [$params];

        foreach ($filters as $i => $filter) {
            $name = $filter['__name'] ?? '';
            if ($name !== 'FlateDecode') {
                throw new RuntimeException('This PDF uses a compression PDTS cannot read ('.$name.').');
            }
            $decoded = @gzuncompress($raw);
            if ($decoded === false) {
                $decoded = @gzinflate(substr($raw, 2));
            }
            if ($decoded === false) {
                throw new RuntimeException('This PDF could not be read.');
            }
            $raw = $this->unpredict($decoded, $this->resolve($params[$i] ?? null));
        }

        return $raw;
    }

    /** Undoes PNG predictors, which cross-reference streams use. */
    private function unpredict(string $data, mixed $params): string
    {
        $predictor = is_array($params) ? (int) $this->resolve($params['Predictor'] ?? 1) : 1;
        if ($predictor < 10) {
            return $data;
        }

        $columns = (int) $this->resolve($params['Columns'] ?? 1);
        $colors = (int) $this->resolve($params['Colors'] ?? 1);
        $bits = (int) $this->resolve($params['BitsPerComponent'] ?? 8);
        $bpp = max(1, intdiv($colors * $bits, 8));
        $rowLength = intdiv($columns * $colors * $bits + 7, 8);

        $out = '';
        $previous = str_repeat("\0", $rowLength);
        for ($pos = 0; $pos + $rowLength < strlen($data) + 1; $pos += $rowLength + 1) {
            $type = ord($data[$pos] ?? "\0");
            $row = substr($data, $pos + 1, $rowLength);
            if (strlen($row) < $rowLength) {
                break;
            }
            $decoded = '';
            for ($i = 0; $i < $rowLength; $i++) {
                $x = ord($row[$i]);
                $a = $i >= $bpp ? ord($decoded[$i - $bpp]) : 0;
                $b = ord($previous[$i]);
                $c = $i >= $bpp ? ord($previous[$i - $bpp]) : 0;
                $value = match ($type) {
                    1 => $x + $a,
                    2 => $x + $b,
                    3 => $x + intdiv($a + $b, 2),
                    4 => $x + $this->paeth($a, $b, $c),
                    default => $x,
                };
                $decoded .= chr($value & 0xFF);
            }
            $out .= $decoded;
            $previous = $decoded;
        }

        return $out;
    }

    private function paeth(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);

        return ($pa <= $pb && $pa <= $pc) ? $a : ($pb <= $pc ? $b : $c);
    }

    private function getObject(int $number): mixed
    {
        $entry = $this->xref[$number] ?? null;
        if ($entry === null || $entry[0] === 0) {
            return null;
        }

        if ($entry[0] === 1) {
            $pos = $entry[1];

            return $this->parseIndirectAt($pos)[0];
        }

        // Inside an object stream.
        $streamNumber = $entry[1];
        if (! isset($this->objectStreams[$streamNumber])) {
            $streamEntry = $this->xref[$streamNumber] ?? null;
            if ($streamEntry === null || $streamEntry[0] !== 1) {
                return null;
            }
            $pos = $streamEntry[1];
            [$dict, $data] = $this->parseIndirectAt($pos);
            $count = (int) $this->resolve($dict['N']);
            $first = (int) $this->resolve($dict['First']);
            preg_match_all('/\d+/', substr($data, 0, $first), $nums);
            $sources = [];
            for ($i = 0; $i < $count; $i++) {
                $start = (int) $nums[0][$i * 2 + 1];
                $end = isset($nums[0][($i + 1) * 2 + 1]) ? (int) $nums[0][($i + 1) * 2 + 1] : strlen($data) - $first;
                $sources[(int) $nums[0][$i * 2]] = substr($data, $first + $start, $end - $start);
            }
            $this->objectStreams[$streamNumber] = $sources;
        }

        $source = $this->objectStreams[$streamNumber][$number] ?? null;
        if ($source === null) {
            return null;
        }

        $saved = $this->pdf;
        $this->pdf = $source;
        try {
            $pos = 0;

            return $this->parseValue($pos);
        } finally {
            $this->pdf = $saved;
        }
    }

    private function resolve(mixed $value): mixed
    {
        $depth = 0;
        while (is_array($value) && isset($value['__ref']) && $depth++ < 32) {
            $value = $this->getObject($value['__ref'][0]);
        }

        return $value;
    }

    private function trailerValue(string $key): mixed
    {
        return $this->resolve($this->trailer[$key] ?? null);
    }

    /**
     * Leaf pages in order, with what they inherit from the page tree.
     *
     * @return list<array{ref: array{0: int, 1: int}, dict: array<string, mixed>, box: list<float>, rotate: int}>
     */
    private function pageList(): array
    {
        $root = $this->resolve($this->trailer['Root']);
        $pages = [];
        $this->walkPages($root['Pages'] ?? null, [], $pages, 0);

        if ($pages === []) {
            throw new RuntimeException('This PDF has no pages PDTS can read.');
        }

        return $pages;
    }

    private function walkPages(mixed $node, array $inherited, array &$pages, int $depth): void
    {
        if ($depth > 64 || ! is_array($node) || ! isset($node['__ref'])) {
            return;
        }

        $ref = $node['__ref'];
        $dict = $this->getObject($ref[0]);
        if (! is_array($dict)) {
            return;
        }

        foreach (['MediaBox', 'CropBox', 'Rotate', 'Resources'] as $key) {
            if (isset($dict[$key])) {
                $inherited[$key] = $this->resolve($dict[$key]);
            }
        }

        $type = $dict['Type']['__name'] ?? (isset($dict['Kids']) ? 'Pages' : 'Page');
        if ($type === 'Pages') {
            foreach ($this->resolve($dict['Kids'])['__arr'] ?? [] as $kid) {
                $this->walkPages($kid, $inherited, $pages, $depth + 1);
            }

            return;
        }

        $box = $inherited['CropBox'] ?? $inherited['MediaBox'] ?? ['__arr' => [0, 0, 612, 792]];
        $box = array_map(fn ($v) => (float) $this->resolve($v), $box['__arr']);
        $rotate = ((int) ($inherited['Rotate'] ?? 0) % 360 + 360) % 360;

        $pages[] = ['ref' => $ref, 'dict' => $dict, 'box' => $box, 'rotate' => $rotate, 'resources' => $inherited['Resources'] ?? null];
    }

    /** @return array{0: float, 1: float} width and height as the page is shown, in points */
    private function displaySize(array $page): array
    {
        [$x1, $y1, $x2, $y2] = $page['box'];
        $width = abs($x2 - $x1);
        $height = abs($y2 - $y1);

        return in_array($page['rotate'], [90, 270], true) ? [$height, $width] : [$width, $height];
    }

    /** @return list<array{__ref: array{0: int, 1: int}}> */
    private function contentRefs(mixed $contents): array
    {
        if ($contents === null) {
            return [];
        }
        if (isset($contents['__ref'])) {
            $target = $this->getObject($contents['__ref'][0]);
            // An indirect array of streams, or a single stream.
            if (is_array($target) && isset($target['__arr'])) {
                return array_values(array_filter($target['__arr'], fn ($v) => isset($v['__ref'])));
            }

            return [$contents];
        }
        if (isset($contents['__arr'])) {
            return array_values(array_filter($contents['__arr'], fn ($v) => isset($v['__ref'])));
        }

        return [];
    }

    // ----------------------------------------------------------------------
    // Drawing and writing
    // ----------------------------------------------------------------------

    private function qrDrawing(array $page, array $modules, float $xMm, float $yMm, float $sizeMm, string $label = ''): string
    {
        $pt = 72 / 25.4;
        [$displayWidth, $displayHeight] = $this->displaySize($page);
        [$llx, $lly, $urx, $ury] = $page['box'];
        $boxWidth = abs($urx - $llx);
        $boxHeight = abs($ury - $lly);
        $llx = min($llx, $urx);
        $lly = min($lly, $ury);

        $size = $sizeMm * $pt;
        $left = min(max(0, $xMm * $pt), max(0, $displayWidth - $size));
        $top = min(max(0, $yMm * $pt), max(0, $displayHeight - $size));

        // Display position (from the top-left of the page as shown) to PDF user space.
        $toUser = function (float $px, float $py) use ($page, $boxWidth, $boxHeight, $llx, $lly): array {
            [$u, $v] = match ($page['rotate']) {
                90 => [$py, $px],
                180 => [$boxWidth - $px, $py],
                270 => [$boxWidth - $py, $boxHeight - $px],
                default => [$px, $boxHeight - $py],
            };

            return [$llx + $u, $lly + $v];
        };
        $rect = function (float $px, float $py, float $w, float $h) use ($toUser): string {
            [$ax, $ay] = $toUser($px, $py);
            [$bx, $by] = $toUser($px + $w, $py + $h);

            return sprintf('%.3F %.3F %.3F %.3F re', min($ax, $bx), min($ay, $by), abs($bx - $ax), abs($by - $ay));
        };

        $count = count($modules);
        $quiet = 2; // modules of white border around the code
        $cell = $size / ($count + 2 * $quiet);

        $ops = ['q', '1 g', $rect($left, $top, $size, $size), 'f', '0 g'];
        foreach ($modules as $y => $row) {
            $runStart = null;
            foreach ([...$row, false] as $x => $dark) {
                if ($dark && $runStart === null) {
                    $runStart = $x;
                } elseif (! $dark && $runStart !== null) {
                    $ops[] = $rect($left + ($quiet + $runStart) * $cell, $top + ($quiet + $y) * $cell, ($x - $runStart) * $cell, $cell);
                    $runStart = null;
                }
            }
        }
        $ops[] = 'f';

        if ($label !== '') {
            $points = self::LABEL_POINTS;
            $width = 0;
            foreach (str_split($label) as $char) {
                $width += self::HELVETICA_WIDTHS[$char] ?? 556;
            }
            $width *= $points / 1000;

            // Centred under the QR; above it when the QR sits at the bottom edge.
            $gap = 1.2 * $pt;
            $baseline = $top + $size + $gap + 0.72 * $points;
            if ($baseline + 0.25 * $points > $displayHeight) {
                $baseline = $top - $gap - 0.22 * $points;
            }
            $start = min(max(0, $left + ($size - $width) / 2), max(0, $displayWidth - $width));

            // Text runs left to right as the page is shown, whatever the page rotation.
            [$ox, $oy] = $toUser($start, $baseline);
            [$rx, $ry] = $toUser($start + 1, $baseline);
            [$ux, $uy] = $toUser($start, $baseline - 1);
            $text = strtr($label, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);

            $ops[] = 'BT';
            $ops[] = '/'.self::FONT_NAME.' '.rtrim(rtrim(sprintf('%.2F', $points), '0'), '.').' Tf';
            $ops[] = sprintf('%.4F %.4F %.4F %.4F %.3F %.3F Tm', $rx - $ox, $ry - $oy, $ux - $ox, $uy - $oy, $ox, $oy);
            $ops[] = '('.$text.') Tj';
            $ops[] = 'ET';
        }

        $ops[] = 'Q';

        return implode("\n", $ops)."\n";
    }

    private function streamObject(string $data): string
    {
        return '<< /Length '.strlen($data)." >>\nstream\n".$data."\nendstream";
    }

    /**
     * @param  array<int, array{0: int, 1: string}>  $objects
     */
    private function appendUpdate(array $objects, int $nextObject): string
    {
        $out = $this->pdf;
        if (! str_ends_with($out, "\n")) {
            $out .= "\n";
        }

        $offsets = [];
        ksort($objects);
        foreach ($objects as $number => [$generation, $body]) {
            $offsets[$number] = [strlen($out), $generation];
            $out .= $number.' '.$generation." obj\n".$body."\nendobj\n";
        }

        $trailer = ['Size' => $nextObject, 'Root' => $this->trailer['Root'], 'Prev' => $this->lastXrefOffset];
        foreach (['Info', 'ID'] as $key) {
            if (isset($this->trailer[$key])) {
                $trailer[$key] = $this->trailer[$key];
            }
        }

        if ($this->usesXrefStream) {
            // The update's own cross-reference stream is the last new object.
            $xrefNumber = $nextObject;
            $trailer['Size'] = $nextObject + 1;
            $xrefOffset = strlen($out);
            $offsets[$xrefNumber] = [$xrefOffset, 0];

            $index = [];
            $rows = '';
            foreach ($this->runs(array_keys($offsets)) as [$start, $count]) {
                $index[] = $start;
                $index[] = $count;
                for ($n = $start; $n < $start + $count; $n++) {
                    $rows .= chr(1).pack('N', $offsets[$n][0]).pack('n', $offsets[$n][1]);
                }
            }

            $dict = ['Type' => ['__name' => 'XRef'], 'W' => ['__arr' => [1, 4, 2]], 'Index' => ['__arr' => $index]] + $trailer;
            $dict['Length'] = strlen($rows);
            $out .= $xrefNumber." 0 obj\n".$this->serialize($dict)."\nstream\n".$rows."\nendstream\nendobj\n";
        } else {
            $xrefOffset = strlen($out);
            $out .= "xref\n";
            foreach ($this->runs(array_keys($offsets)) as [$start, $count]) {
                $out .= $start.' '.$count."\n";
                for ($n = $start; $n < $start + $count; $n++) {
                    $out .= sprintf("%010d %05d n \n", $offsets[$n][0], $offsets[$n][1]);
                }
            }
            $out .= 'trailer
'.$this->serialize($trailer)."\n";
        }

        return $out.'startxref
'.$xrefOffset."\n%%EOF\n";
    }

    /**
     * @param  list<int>  $numbers
     * @return list<array{0: int, 1: int}> [first, count] for each run of consecutive numbers
     */
    private function runs(array $numbers): array
    {
        sort($numbers);
        $runs = [];
        foreach ($numbers as $n) {
            if ($runs !== [] && $runs[count($runs) - 1][0] + $runs[count($runs) - 1][1] === $n) {
                $runs[count($runs) - 1][1]++;
            } else {
                $runs[] = [$n, 1];
            }
        }

        return $runs;
    }

    // ----------------------------------------------------------------------
    // PDF syntax
    // ----------------------------------------------------------------------

    private function skipSpace(int &$pos): void
    {
        $length = strlen($this->pdf);
        while ($pos < $length) {
            $char = $this->pdf[$pos];
            if ($char === '%') {
                while ($pos < $length && $this->pdf[$pos] !== "\n" && $this->pdf[$pos] !== "\r") {
                    $pos++;
                }
            } elseif (str_contains(" \t\r\n\f\0", $char)) {
                $pos++;
            } else {
                return;
            }
        }
    }

    /**
     * Values: numbers and booleans as PHP values, null, and arrays tagged
     * __name, __str (raw source kept), __arr, __ref; dictionaries are plain
     * arrays keyed by name.
     */
    private function parseValue(int &$pos): mixed
    {
        $this->skipSpace($pos);
        $char = $this->pdf[$pos] ?? '';

        if ($char === '<' && ($this->pdf[$pos + 1] ?? '') === '<') {
            $pos += 2;
            $dict = [];
            while (true) {
                $this->skipSpace($pos);
                if (substr($this->pdf, $pos, 2) === '>>') {
                    $pos += 2;

                    return $dict;
                }
                if (($this->pdf[$pos] ?? '') !== '/') {
                    throw new RuntimeException('This PDF could not be read.');
                }
                $key = $this->parseValue($pos)['__name'];
                $dict[$key] = $this->parseValue($pos);
            }
        }

        if ($char === '[') {
            $pos++;
            $items = [];
            while (true) {
                $this->skipSpace($pos);
                if (($this->pdf[$pos] ?? ']') === ']') {
                    $pos++;

                    return ['__arr' => $items];
                }
                $items[] = $this->parseValue($pos);
            }
        }

        if ($char === '/') {
            preg_match('/\G\/([^\s\/\[\]<>(){}%]*)/', $this->pdf, $m, 0, $pos);
            $pos += strlen($m[0]);

            return ['__name' => preg_replace_callback('/#([0-9A-Fa-f]{2})/', fn ($h) => chr(hexdec($h[1])), $m[1])];
        }

        if ($char === '(') {
            $start = $pos;
            $depth = 0;
            $length = strlen($this->pdf);
            for (; $pos < $length; $pos++) {
                $c = $this->pdf[$pos];
                if ($c === '\\') {
                    $pos++;

                    continue;
                }
                if ($c === '(') {
                    $depth++;
                } elseif ($c === ')' && --$depth === 0) {
                    $pos++;
                    break;
                }
            }

            return ['__str' => substr($this->pdf, $start, $pos - $start)];
        }

        if ($char === '<') {
            $end = strpos($this->pdf, '>', $pos);
            $raw = substr($this->pdf, $pos, $end - $pos + 1);
            $pos = $end + 1;

            return ['__str' => $raw];
        }

        if (preg_match('/\G[+-]?(\d+\.?\d*|\.\d+)/', $this->pdf, $m, 0, $pos)) {
            // "12 0 R" is a reference.
            if (preg_match('/\G(\d+)\s+(\d+)\s+R(?![A-Za-z])/', $this->pdf, $r, 0, $pos)) {
                $pos += strlen($r[0]);

                return ['__ref' => [(int) $r[1], (int) $r[2]]];
            }
            $pos += strlen($m[0]);

            return str_contains($m[0], '.') ? (float) $m[0] : (int) $m[0];
        }

        foreach (['true' => true, 'false' => false, 'null' => null] as $word => $value) {
            if (substr($this->pdf, $pos, strlen($word)) === $word) {
                $pos += strlen($word);

                return $value;
            }
        }

        throw new RuntimeException('This PDF could not be read.');
    }

    private function serialize(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return rtrim(rtrim(sprintf('%.5F', $value), '0'), '.') ?: '0';
        }
        if (isset($value['__name'])) {
            return '/'.preg_replace_callback('/[^!-~]|[#\/\[\]<>(){}%]/', fn ($c) => sprintf('#%02X', ord($c[0])), $value['__name']);
        }
        if (isset($value['__str'])) {
            return $value['__str'];
        }
        if (isset($value['__ref'])) {
            return $value['__ref'][0].' '.$value['__ref'][1].' R';
        }
        if (array_key_exists('__arr', $value)) {
            return '['.implode(' ', array_map(fn ($v) => $this->serialize($v), $value['__arr'])).']';
        }

        $parts = [];
        foreach ($value as $key => $item) {
            $parts[] = $this->serialize(['__name' => (string) $key]).' '.$this->serialize($item);
        }

        return '<< '.implode(' ', $parts).' >>';
    }
}
