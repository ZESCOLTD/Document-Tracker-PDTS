<?php

namespace App\Services\Pdts\Templates;

use RuntimeException;
use ZipArchive;

/**
 * Fills an uploaded Word template: ${placeholders} in the body, headers and
 * footers are replaced with the document's details, and ${qr_code} with the
 * QR picture.
 */
class DocxTemplateFiller
{
    private const W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    private const PART_PATTERN = '#^word/(document|header\d*|footer\d*)\.xml$#';

    /**
     * A memo heading's address labels: their answers line up in one column, right
     * after the widest of them (REF and DATE, under the line, keep their own).
     */
    private const HEADING_LABELS = ['TO', 'FROM', 'CC', 'THROUGH', 'THRU', 'ATTENTION', 'ATTN'];

    /**
     * Names of the placeholders a template uses, so an upload can be checked.
     *
     * @return list<string>
     */
    public function placeholdersIn(string $docxPath): array
    {
        $found = [];

        $this->eachPart($docxPath, function (string $name, string $xml) use (&$found): ?string {
            preg_match_all('/\$\{([a-z][a-z0-9_]*)\}/', $this->joinSplitPlaceholders($xml), $matches);
            $found = [...$found, ...$matches[1]];

            return null;
        });

        return array_values(array_unique($found));
    }

    /**
     * @param  array<string, string>  $values
     * @param  float  $qrSizeMm  printed size of the QR code
     * @param  array{image: string, extension: string, width_mm: float, height_mm: float}|null  $signature
     *                                                                                                      the author's signature for ${signature}; without it the spot is left empty to sign by hand
     */
    public function fill(string $docxPath, array $values, string $qrPng, float $qrSizeMm = 20.0, ?array $signature = null, array $rich = [], array $tables = [], array $omit = []): string
    {
        // Formatted text (name => HTML) fills its space with real bold, italic and list lines;
        // a table (name => [columns, rows]) fills it with a real table.
        foreach (array_keys($rich + $tables) as $key) {
            unset($values[$key]);
        }
        $qrInches = max(10.0, $qrSizeMm) / 25.4;
        try {
            $files = DocxPackage::read($docxPath);
        } catch (RuntimeException) {
            throw new RuntimeException('The template is not a valid Word file.');
        }
        // The document's usual text size (half-points), for lining up answers after their labels.
        $textSize = preg_match('#<w:docDefaults>.*?<w:sz w:val="(\d+)"#s', (string) ($files['word/styles.xml'] ?? ''), $size) ? (int) $size[1] : 24;

        $pictureId = 9000;
        $usedQr = false;
        $usedSignature = false;
        unset($values['signature']);

        foreach (array_keys($files) as $name) {
            if (! preg_match(self::PART_PATTERN, $name)) {
                continue;
            }

            $xml = $this->joinSplitPlaceholders($files[$name]);
            // A little room above the footer: the text never runs right up against it
            // (a tall footer, the IDC one, otherwise starts straight under the last line).
            if (str_starts_with(basename($name), 'footer')) {
                $xml = preg_replace('#(<w:ftr\b[^>]*>)#', '$1<w:p><w:pPr><w:spacing w:before="0" w:after="0" w:line="220" w:lineRule="exact"/></w:pPr></w:p>', $xml, 1);
            }
            // Fields the author left empty: not on the document, nor their label.
            if ($omit !== []) {
                $xml = $this->leaveOut($xml, $omit);
            }

            foreach ($rich as $key => $html) {
                if (str_contains($xml, '${'.$key.'}')) {
                    $xml = $this->fillRich($xml, $key, RichText::blocks((string) $html));
                }
            }
            foreach ($tables as $key => $table) {
                if (str_contains($xml, '${'.$key.'}')) {
                    $xml = $this->fillTable($xml, $key, (array) ($table['columns'] ?? []), (array) ($table['rows'] ?? []));
                }
            }

            // A section's heading stands a little apart from the text written under it.
            $xml = $this->roomUnderHeadings($xml, (string) ($files['word/styles.xml'] ?? ''));

            // Writing spaces (text_...): each line of the text becomes its own
            // paragraph, in the look of the line that held the placeholder.
            foreach ($values as $key => $value) {
                if (str_starts_with($key, 'text_') && str_contains($xml, '${'.$key.'}')) {
                    $xml = $this->fillParagraphs($xml, $key, $value);
                }
            }

            $xml = $this->tidyEmptyLines($xml, $values);
            // "CC:" names, and a long TO or FROM, line up under the first line of the answer.
            $xml = $this->alignLabelledLines($xml, $textSize);

            foreach ($values as $key => $value) {
                $xml = str_replace('${'.$key.'}', $this->inlineText($value), $xml);
            }

            if (str_contains($xml, '${signature}')) {
                // The signature never ends a page on its own: it moves with the name under it.
                $xml = $this->keepSignatureWithName($xml);
                if ($signature === null) {
                    $xml = str_replace('${signature}', '', $xml);
                } else {
                    $usedSignature = true;
                    $xml = $this->ensureDrawingNamespaces($xml);
                    $xml = preg_replace_callback(
                        '#<w:r(?:\s[^>]*)?>(?:(?!</w:r>|<w:r[\s>]).)*?\$\{signature\}(?:(?!</w:r>).)*?</w:r>#s',
                        fn () => WordXml::picture('rIdPdtsSignature', $pictureId++, 'Signature', $signature['width_mm'] / 25.4, $signature['height_mm'] / 25.4),
                        $xml,
                    );
                    $this->addImageRelationship($files, $name, 'rIdPdtsSignature', 'media/pdts-signature.'.$signature['extension']);
                }
            }

            if (str_contains($xml, '${qr_code}')) {
                $usedQr = true;
                $relationshipId = 'rIdPdtsQr';
                $xml = $this->ensureDrawingNamespaces($xml);
                // The innermost run holding ${qr_code}: never one that contains
                // another run, such as the floating box the QR code sits in.
                $xml = preg_replace_callback(
                    '#<w:r(?:\s[^>]*)?>(?:(?!</w:r>|<w:r[\s>]).)*?\$\{qr_code\}(?:(?!</w:r>).)*?</w:r>#s',
                    function () use ($relationshipId, &$pictureId, $qrInches) {
                        return WordXml::picture($relationshipId, $pictureId++, 'PDTS QR code', $qrInches, $qrInches);
                    },
                    $xml,
                );
                $this->addImageRelationship($files, $name, $relationshipId);
            }

            $files[$name] = $xml;
        }

        if ($usedQr) {
            $files['word/media/pdts-qr.png'] = $qrPng;
            $this->ensureImageContentType($files, 'png');
        }

        if ($usedSignature) {
            $files['word/media/pdts-signature.'.$signature['extension']] = $signature['image'];
            $this->ensureImageContentType($files, $signature['extension']);
        }

        return DocxPackage::write($files);
    }

    /**
     * Lines with nothing to show:
     *  - a field left empty that is alone on its line (such as typist's
     *    initials) prints nothing, rather than a line that looks like a
     *    signature line; a field after a label ("Amount: ____") keeps its line
     *    to fill in by hand;
     *  - a "CC:" line is left out when nobody is copied.
     *
     * @param  array<string, string>  $values
     */
    private function tidyEmptyLines(string $xml, array $values): string
    {
        $paragraph = '#<w:p(?:\s[^>]*)?>(?:(?!<w:p[\s>]|</w:p>).)*?%s(?:(?!</w:p>).)*?</w:p>#s';

        foreach ($values as $key => $value) {
            $placeholder = '${'.$key.'}';
            if (! str_contains($xml, $placeholder)) {
                continue;
            }

            if ($key === 'cc' && trim($value) === '') {
                $xml = preg_replace_callback(sprintf($paragraph, preg_quote($placeholder, '#')), function (array $m) use ($placeholder) {
                    $text = trim(html_entity_decode(strip_tags($m[0])));
                    if (! preg_match('/^c\.?\s?c\.?\s*:?\s*'.preg_quote($placeholder, '/').'$/i', $text)) {
                        return $m[0];
                    }
                    // A CC line carrying the block's separator line keeps the line, without its text.
                    if (str_contains($m[0], '<w:pBdr>') && preg_match('#^(<w:p(?:\s[^>]*)?>)(<w:pPr>.*?</w:pPr>)#s', $m[0], $p)) {
                        return $p[1].preg_replace('#<w:ind(?:\s[^>]*)?/>#', '', $p[2]).'</w:p>';
                    }

                    return '';
                }, $xml);

                continue;
            }

            if ($value !== '' && trim($value, '_') === '') {
                $xml = preg_replace_callback(sprintf($paragraph, preg_quote($placeholder, '#')), function (array $m) use ($placeholder) {
                    $text = trim(html_entity_decode(strip_tags($m[0])));

                    return $text === $placeholder ? str_replace($placeholder, '', $m[0]) : $m[0];
                }, $xml);
            }
        }

        return $xml;
    }

    /**
     * Replaces the paragraph holding ${key} with one paragraph per line of
     * the value; an empty value leaves one empty line.
     */
    private function fillParagraphs(string $xml, string $key, string $value): string
    {
        $placeholder = '${'.$key.'}';
        // Blank lines only separate paragraphs; the template's own spacing does the rest.
        $lines = array_values(array_filter(preg_split('/\R/u', $value), fn (string $line) => trim($line) !== ''));
        $lines = $lines === [] ? [''] : $lines;

        return preg_replace_callback(
            '#<w:p(?:\s[^>]*)?>(?:(?!<w:p[\s>]|</w:p>).)*?'.preg_quote($placeholder, '#').'(?:(?!</w:p>).)*?</w:p>#s',
            fn (array $m) => implode('', array_map(
                fn (string $line) => str_replace($placeholder, WordXml::escape($line), $m[0]),
                $lines,
            )),
            $xml,
        );
    }

    /**
     * Replaces the paragraph holding ${key} with the formatted text: one
     * paragraph per block, in the look of the line that held the placeholder,
     * with bold and italic runs, and list items indented with a bullet or
     * number (no Word numbering definitions needed).
     *
     * @param  list<array{list: ?string, number: int, runs: list<array{text: string, bold: bool, italic: bool, break: bool}>}>  $blocks
     */
    private function fillRich(string $xml, string $key, array $blocks): string
    {
        $placeholder = '${'.$key.'}';

        return preg_replace_callback(
            '#<w:p(?:\s[^>]*)?>(?:(?!<w:p[\s>]|</w:p>).)*?'.preg_quote($placeholder, '#').'(?:(?!</w:p>).)*?</w:p>#s',
            function (array $m) use ($placeholder, $blocks) {
                preg_match('#^<w:p(?:\s[^>]*)?>#', $m[0], $open);
                preg_match('#<w:pPr>.*?</w:pPr>#s', $m[0], $pPr);
                preg_match('#<w:r(?:\s[^>]*)?>(?:(?!</w:r>).)*?'.preg_quote($placeholder, '#').'#s', $m[0], $run);
                preg_match('#<w:rPr>(.*?)</w:rPr>#s', $run[0] ?? '', $rPr);
                $open = $open[0] ?? '<w:p>';
                $pPr = $pPr[0] ?? '';
                // The line's own look, without bold or italic: the text decides those.
                $base = preg_replace('#<w:(b|bCs|i|iCs)(?:\s[^>]*)?/>#', '', $rPr[1] ?? '');

                if ($blocks === []) {
                    return $open.$pPr.'</w:p>';
                }

                $out = '';
                foreach ($blocks as $block) {
                    $runs = '';
                    if ($block['list']) {
                        $runs .= '<w:r>'.($base !== '' ? '<w:rPr>'.$base.'</w:rPr>' : '').'<w:t xml:space="preserve">'.($block['list'] === 'ol' ? $block['number'].'.' : '•').'</w:t><w:tab/></w:r>';
                    }
                    foreach ($block['runs'] as $part) {
                        if ($part['break']) {
                            $runs .= '<w:r><w:br/></w:r>';

                            continue;
                        }
                        $props = $this->withEmphasis($base, $part['bold'], $part['italic']);
                        $runs .= '<w:r>'.($props !== '' ? '<w:rPr>'.$props.'</w:rPr>' : '').'<w:t xml:space="preserve">'.WordXml::escape($part['text']).'</w:t></w:r>';
                    }
                    $out .= $open.($block['list'] ? $this->indented($pPr) : $pPr).$runs.'</w:p>';
                }

                return $out;
            },
            $xml,
        );
    }

    /**
     * Replaces the paragraph holding ${key} with a bordered table: a shaded
     * row of the column headings, then the author's rows (one empty row when
     * there are none), in the font of the line that held the placeholder.
     *
     * @param  list<string>  $columns
     * @param  list<list<string>>  $rows
     */
    private function fillTable(string $xml, string $key, array $columns, array $rows): string
    {
        $placeholder = '${'.$key.'}';
        $columns = array_values($columns) ?: [''];
        $count = count($columns);
        $rows = array_values(array_filter($rows, fn ($row) => trim(implode('', array_map('strval', (array) $row))) !== '')) ?: [array_fill(0, $count, '')];

        return preg_replace_callback(
            '#<w:p(?:\s[^>]*)?>(?:(?!<w:p[\s>]|</w:p>).)*?'.preg_quote($placeholder, '#').'(?:(?!</w:p>).)*?</w:p>#s',
            function (array $m) use ($placeholder, $columns, $rows, $count) {
                preg_match('#<w:r(?:\s[^>]*)?>(?:(?!</w:r>).)*?'.preg_quote($placeholder, '#').'#s', $m[0], $run);
                preg_match('#<w:rPr>(.*?)</w:rPr>#s', $run[0] ?? '', $rPr);
                $base = preg_replace('#<w:(b|bCs|i|iCs|caps)(?:\s[^>]*)?/>#', '', $rPr[1] ?? '');
                $width = intdiv(5000, $count);
                $cell = fn (string $text, bool $head) => '<w:tc><w:tcPr><w:tcW w:w="'.$width.'" w:type="pct"/>'
                    .($head ? '<w:shd w:val="clear" w:color="auto" w:fill="EEF3F3"/>' : '').'</w:tcPr>'
                    .'<w:p><w:pPr><w:spacing w:before="40" w:after="40"/></w:pPr><w:r>'
                    .(($props = $this->withEmphasis($base, $head, false)) !== '' ? '<w:rPr>'.$props.'</w:rPr>' : '')
                    .'<w:t xml:space="preserve">'.WordXml::escape($text).'</w:t></w:r></w:p></w:tc>';
                $border = '<w:top w:val="single" w:sz="4" w:color="9AA5A5"/><w:left w:val="single" w:sz="4" w:color="9AA5A5"/><w:bottom w:val="single" w:sz="4" w:color="9AA5A5"/>'
                    .'<w:right w:val="single" w:sz="4" w:color="9AA5A5"/><w:insideH w:val="single" w:sz="4" w:color="9AA5A5"/><w:insideV w:val="single" w:sz="4" w:color="9AA5A5"/>';

                $table = '<w:tbl><w:tblPr><w:tblW w:w="5000" w:type="pct"/><w:tblBorders>'.$border.'</w:tblBorders>'
                    .'<w:tblCellMar><w:left w:w="100" w:type="dxa"/><w:right w:w="100" w:type="dxa"/></w:tblCellMar></w:tblPr>'
                    .'<w:tblGrid>'.str_repeat('<w:gridCol/>', $count).'</w:tblGrid>'
                    .'<w:tr><w:trPr><w:tblHeader/></w:trPr>'.implode('', array_map(fn ($c) => $cell((string) $c, true), $columns)).'</w:tr>';
                foreach ($rows as $row) {
                    $row = array_values((array) $row);
                    $table .= '<w:tr>'.implode('', array_map(fn ($i) => $cell((string) ($row[$i] ?? ''), false), range(0, $count - 1))).'</w:tr>';
                }

                // A paragraph after the table keeps it apart from what follows.
                return $table.'</w:tbl><w:p><w:pPr><w:spacing w:before="0" w:after="80"/></w:pPr></w:p>';
            },
            $xml,
        );
    }

    /** Run properties with bold and italic added in the place Word expects them (after the style and fonts). */
    private function withEmphasis(string $props, bool $bold, bool $italic): string
    {
        $add = ($bold ? '<w:b/><w:bCs/>' : '').($italic ? '<w:i/><w:iCs/>' : '');
        if ($add === '') {
            return $props;
        }
        if (preg_match('#^((?:<w:rStyle[^>]*/>)?(?:<w:rFonts[^>]*/>)?)#', $props, $m)) {
            return $m[1].$add.substr($props, strlen($m[1]));
        }

        return $add.$props;
    }

    /** Paragraph properties with a hanging indent for a list item. */
    private function indented(string $pPr): string
    {
        $ind = '<w:ind w:left="720" w:hanging="360"/>';
        if ($pPr === '') {
            return '<w:pPr>'.$ind.'</w:pPr>';
        }
        $pPr = preg_replace('#<w:ind(?:\s[^>]*)?/>#', '', $pPr);
        // w:ind comes before these in a paragraph's properties.
        if (preg_match('#<w:(contextualSpacing|mirrorIndents|suppressOverlap|jc|textDirection|textAlignment|textboxTightWrap|outlineLvl|divId|cnfStyle|rPr|sectPr|pPrChange)\b#', $pPr, $m, PREG_OFFSET_CAPTURE)) {
            return substr($pPr, 0, $m[0][1]).$ind.substr($pPr, $m[0][1]);
        }

        return str_replace('</w:pPr>', $ind.'</w:pPr>', $pPr);
    }

    /**
     * Word often splits "${name}" across several runs when it is typed or
     * spell-checked. Strip the XML tags inside a placeholder so it reads whole.
     */
    private function joinSplitPlaceholders(string $xml): string
    {
        return preg_replace_callback(
            '/\$(?:\{|[^{$]*\>\{)[^}$]*\}/U',
            fn (array $match) => strip_tags($match[0]),
            $xml,
        );
    }

    /**
     * The line holding ${signature}, and any empty lines after it, are kept
     * with the next line (the name under the signature, its job title), so a
     * signature at the foot of a page moves to the next page with its name
     * instead of leaving the name alone on a page of its own. The closing lines
     * and the last paragraph above it go with it, so it never stands alone.
     */
    private function keepSignatureWithName(string $xml): string
    {
        $dom = new \DOMDocument;
        if (! @$dom->loadXML($xml)) {
            return $xml;
        }
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', self::W_NS);

        $keep = function (\DOMElement $paragraph) use ($dom, $xpath): void {
            $pPr = $xpath->query('w:pPr', $paragraph)->item(0);
            if (! $pPr instanceof \DOMElement) {
                $pPr = $paragraph->insertBefore($dom->createElementNS(self::W_NS, 'w:pPr'), $paragraph->firstChild);
            }
            if ($xpath->query('w:keepNext', $pPr)->length > 0) {
                return;
            }
            // keepNext comes straight after the paragraph style, if there is one.
            $style = $xpath->query('w:pStyle', $pPr)->item(0);
            $pPr->insertBefore($dom->createElementNS(self::W_NS, 'w:keepNext'), $style ? $style->nextSibling : $pPr->firstChild);
        };

        $changed = false;
        foreach (iterator_to_array($xpath->query('//w:p[.//w:t[contains(., "${signature}")]]')) as $paragraph) {
            $keep($paragraph);
            $changed = true;
            // Empty lines between the signature and the name keep together too.
            for ($next = $paragraph->nextSibling; $next !== null; $next = $next->nextSibling) {
                if (! $next instanceof \DOMElement || $next->localName !== 'p') {
                    if ($next instanceof \DOMElement) {
                        break;
                    }

                    continue;
                }
                if (trim($next->textContent) !== '' || $xpath->query('.//w:drawing', $next)->length > 0) {
                    break;
                }
                $keep($next);
            }

            // The signature never stands alone on a page: the lines above it go with it
            // ("Yours faithfully," / "ZESCO LIMITED", a sign-off block's heading, Name and
            // Job title, "Kindly approve."), up to and with the last full paragraph of the
            // text, so the page it moves to always has some of the text; at most six lines.
            $taken = 0;
            for ($before = $paragraph->previousSibling; $before !== null && $taken < 6; $before = $before->previousSibling) {
                if (! $before instanceof \DOMElement) {
                    continue;
                }
                if ($before->localName !== 'p' || $xpath->query('.//w:br[@w:type="page"]|.//w:sectPr', $before)->length > 0) {
                    break; // a table, a page break or a new section: nothing to carry over
                }
                $keep($before);
                $text = trim($before->textContent);
                if ($text === '') {
                    continue; // empty lines between the text and the signature
                }
                $taken++;
                if (mb_strlen($text) > 60) {
                    break; // a full paragraph of the text: enough
                }
            }
        }

        return $changed ? $dom->saveXML() : $xml;
    }

    /**
     * The heading straight above a section's writing space ("1. PURPOSE" over
     * ${text_purpose}) gets at least 8 pt below it, so the text does not sit
     * right under it. Not the memo's or letter's body (${text_body}) under the
     * subject line, and never less than the heading already has.
     */
    private function roomUnderHeadings(string $xml, string $styles): string
    {
        if (! preg_match('/\$\{text_(?!body\})[a-z0-9_]+\}/', $xml)) {
            return $xml;
        }
        // Space after a paragraph that sets none itself: its style's, else the document's default.
        $styleAfter = [];
        preg_match_all('#<w:style\b[^>]*w:styleId="([^"]+)"[^>]*>(.*?)</w:style>#s', $styles, $found, PREG_SET_ORDER);
        foreach ($found as [, $id, $body]) {
            if (preg_match('#<w:pPr>.*?<w:spacing\b[^>]*w:after="(\d+)"#s', $body, $after)) {
                $styleAfter[$id] = (int) $after[1];
            }
        }
        $defaultAfter = preg_match('#<w:pPrDefault>.*?<w:spacing\b[^>]*w:after="(\d+)"#s', $styles, $after) ? (int) $after[1] : 0;
        $dom = new \DOMDocument;
        if (! @$dom->loadXML($xml)) {
            return $xml;
        }
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', self::W_NS);
        $changed = false;

        foreach (iterator_to_array($xpath->query('//w:p[.//w:t[starts-with(normalize-space(.), "${text_")]]')) as $space) {
            $text = trim($space->textContent);
            if (! preg_match('/^\$\{text_[a-z0-9_]+\}$/', $text) || $text === '${text_body}') {
                continue;
            }
            $heading = $space->previousSibling;
            while ($heading !== null && ! $heading instanceof \DOMElement) {
                $heading = $heading->previousSibling;
            }
            $title = $heading instanceof \DOMElement && $heading->localName === 'p' ? trim($heading->textContent) : '';
            if ($title === '' || mb_strlen($title) > 80 || str_contains($title, '${')) {
                continue; // no heading straight above it
            }

            $pPr = $xpath->query('w:pPr', $heading)->item(0);
            if (! $pPr instanceof \DOMElement) {
                $pPr = $heading->insertBefore($dom->createElementNS(self::W_NS, 'w:pPr'), $heading->firstChild);
            }
            $spacing = $xpath->query('w:spacing', $pPr)->item(0);
            $own = $spacing instanceof \DOMElement ? $spacing->getAttributeNS(self::W_NS, 'after') : '';
            $style = (string) $xpath->query('w:pStyle/@w:val', $pPr)->item(0)?->nodeValue;
            $after = $own !== '' ? (int) $own : ($styleAfter[$style] ?? $defaultAfter);
            if ($after >= 160) {
                continue; // already apart enough: as the template has it
            }
            if (! $spacing instanceof \DOMElement) {
                $spacing = $dom->createElementNS(self::W_NS, 'w:spacing');
                // w:spacing goes before the indent, justification and the paragraph mark's font.
                $pPr->insertBefore($spacing, $xpath->query('w:ind|w:contextualSpacing|w:jc|w:textAlignment|w:outlineLvl|w:rPr|w:sectPr|w:pPrChange', $pPr)->item(0));
            }
            $spacing->setAttributeNS(self::W_NS, 'w:after', '160');
            $spacing->removeAttributeNS(self::W_NS, 'afterAutospacing');
            $changed = true;
        }

        return $changed ? $dom->saveXML() : $xml;
    }

    /**
     * Fields the author left empty are left out of the document:
     *  - a line holding only the field, or a label and the field ("Account
     *    No: ${account_no}"), is taken out, and so is the heading just above
     *    it when that is the field's own label ("Background", "1.2 Background");
     *  - in a table cell, the cell is left blank (the table keeps its shape);
     *  - inside a sentence ("Dear ${name}"), only the blank goes.
     *
     * @param  array<string, string>  $omit  name => label on the form
     */
    private function leaveOut(string $xml, array $omit): string
    {
        if (! str_contains($xml, '${')) {
            return $xml;
        }
        $dom = new \DOMDocument;
        if (! @$dom->loadXML($xml)) {
            return $xml;
        }
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', self::W_NS);
        $plain = fn (\DOMElement $p) => implode('', array_map(fn ($t) => $t->textContent, iterator_to_array($xpath->query('.//w:t', $p))));
        // "1.2  Background:" and "Background" are the same heading.
        $normal = fn (string $text) => mb_strtolower(trim(rtrim(preg_replace('/^\s*(\d+(\.\d+)*\.?|[A-Z]\.)\s+/u', '', trim($text)), ': ')));
        $empty = function (\DOMElement $paragraph) use ($xpath): void {
            foreach ($xpath->query('.//w:t', $paragraph) as $t) {
                $t->textContent = '';
            }
        };
        $changed = false;

        foreach ($omit as $name => $label) {
            $placeholder = '${'.$name.'}';
            foreach (iterator_to_array($xpath->query('//w:p[.//w:t[contains(., "'.$placeholder.'")]]')) as $paragraph) {
                $changed = true;
                $rest = trim(str_replace($placeholder, '', $plain($paragraph)));

                // Inside a sentence: only the blank goes.
                if ($rest !== '' && ! preg_match('/^[^:]{1,60}:$/u', $rest)) {
                    foreach ($xpath->query('.//w:t[contains(., "'.$placeholder.'")]', $paragraph) as $t) {
                        $t->textContent = str_replace($placeholder, '', $t->textContent);
                    }

                    continue;
                }

                // The field's own heading just above it goes too.
                $above = $paragraph->previousSibling;
                while ($above !== null && ! $above instanceof \DOMElement) {
                    $above = $above->previousSibling;
                }
                if ($rest === '' && $label !== '' && $above instanceof \DOMElement && $above->localName === 'p'
                    && ! str_contains($plain($above), '${') && $normal($plain($above)) === $normal($label)) {
                    $above->parentNode->removeChild($above);
                }

                // A table cell keeps one (empty) line; a line that also carries a section
                // break, a picture or a page break is only emptied; anywhere else the line goes.
                $cell = $paragraph->parentNode;
                if (($cell instanceof \DOMElement && $cell->localName === 'tc' && $xpath->query('w:p', $cell)->length <= 1)
                    || $xpath->query('.//w:sectPr|.//w:drawing|.//w:pict|.//w:br[@w:type="page"]', $paragraph)->length > 0) {
                    $empty($paragraph);
                } else {
                    $paragraph->parentNode->removeChild($paragraph);
                }
            }
        }

        return $changed ? $dom->saveXML() : $xml;
    }

    /**
     * A line that is a short label and one answer ("CC: ${cc}", "FROM: ${from}")
     * gets a tab after the label, to the line's hanging indent, so every line of
     * the answer starts at the same place: the names copied under "CC:", a long
     * job title under "FROM:". A CC line already has its hanging indent (the
     * ZESCO memo's); a label in a table cell (TO | FROM side by side) gets one
     * a little wider than its label. Other lines are left as they are.
     */
    private function alignLabelledLines(string $xml, int $textSize): string
    {
        if (! str_contains($xml, '${')) {
            return $xml;
        }
        $dom = new \DOMDocument;
        if (! @$dom->loadXML($xml)) {
            return $xml;
        }
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', self::W_NS);
        $changed = false;

        // First, the lines to line up: a short label and one answer, with nowhere a tab yet.
        $lines = [];
        $common = [];   // the memo heading's labels: one indent for each column
        foreach (iterator_to_array($xpath->query('//w:p[not(ancestor::w:txbxContent)]')) as $paragraph) {
            $texts = iterator_to_array($xpath->query('.//w:t', $paragraph));
            $plain = implode('', array_map(fn ($t) => $t->textContent, $texts));
            if (! preg_match('/^\s*([^\s:${}][^:${}]{0,15}:)\s*\$\{[a-z0-9_]+\}\s*$/u', $plain, $m)
                || $xpath->query('.//w:tab', $paragraph)->length > 0) {
                continue;
            }

            // How wide the label prints, a little generously (bold capitals at the text's size).
            $size = (int) ($xpath->query('.//w:r[w:t[contains(., ":")]]/w:rPr/w:sz/@w:val', $paragraph)->item(0)?->nodeValue ?: $textSize);
            $ems = 0.0;
            foreach (mb_str_split($m[1]) as $char) {
                $ems += match (true) {
                    ctype_upper($char) || ctype_digit($char) => 0.78,
                    ctype_lower($char) => 0.6,
                    in_array($char, [':', '.', ',', ' '], true) => 0.36,
                    default => 0.7,
                };
            }
            $labelWidth = (int) ceil($ems * $size * 10);          // twentieths of a point
            $gap = (int) ceil(0.4 * $size * 10);

            $pPr = $xpath->query('w:pPr', $paragraph)->item(0);
            $ind = $pPr instanceof \DOMElement ? $xpath->query('w:ind', $pPr)->item(0) : null;
            $hanging = $ind instanceof \DOMElement ? (int) $ind->getAttributeNS(self::W_NS, 'hanging') : 0;
            $inCell = $xpath->query('ancestor::w:tc', $paragraph)->length > 0;
            if ($hanging > 0 ? $labelWidth > $hanging : ($ind || ! $inCell)) {
                // Its own hanging indent (the CC line) only when the label fits before it;
                // otherwise only a label in a table cell (TO | FROM side by side).
                continue;
            }
            $needed = max($hanging, (int) (ceil(($labelWidth + $gap) / 10) * 10));

            // TO, CC, REF... in the left column and FROM, DATE... in the right each start their
            // answers at one place: the column's widest label decides it.
            $heading = in_array(rtrim(mb_strtoupper(trim($m[1])), ':'), self::HEADING_LABELS, true);
            $column = $inCell && $xpath->query('ancestor::w:tc[1]/preceding-sibling::w:tc', $paragraph)->length > 0 ? 'right' : 'left';
            if ($heading) {
                $common[$column] = max($common[$column] ?? 0, $needed);
            }
            $lines[] = [$paragraph, $texts, $needed, $heading ? $column : null, $inCell];
        }

        // A heading line across the page (CC) wraps in the left column, where TO does, and
        // never runs on under FROM.
        $keepLeft = $this->leftColumnRightIndent($xpath);

        foreach ($lines as [$paragraph, $texts, $needed, $column, $inCell]) {
            $hanging = $column !== null ? $common[$column] : $needed;
            $pPr = $xpath->query('w:pPr', $paragraph)->item(0);
            if (! $pPr instanceof \DOMElement) {
                $pPr = $paragraph->insertBefore($dom->createElementNS(self::W_NS, 'w:pPr'), $paragraph->firstChild);
            }
            if ($keepLeft !== null && $column === 'left' && ! $inCell) {
                $this->keepInLeftColumn($dom, $xpath, $paragraph, $pPr, $keepLeft, $hanging);
            }
            $ind = $xpath->query('w:ind', $pPr)->item(0);
            if (! $ind instanceof \DOMElement) {
                $ind = $dom->createElementNS(self::W_NS, 'w:ind');
                // w:ind goes before the justification and the paragraph mark's font.
                $after = $xpath->query('w:jc|w:textAlignment|w:textDirection|w:outlineLvl|w:rPr|w:sectPr|w:pPrChange', $pPr)->item(0);
                $pPr->insertBefore($ind, $after);
                $ind->setAttributeNS(self::W_NS, 'w:left', (string) $hanging);
                $ind->setAttributeNS(self::W_NS, 'w:hanging', (string) $hanging);
            } else {
                // Its own indent (the CC line): widened by as much as the column needs, nothing else changed.
                $own = (int) $ind->getAttributeNS(self::W_NS, 'hanging');
                if ($hanging > $own) {
                    $left = (int) ($ind->getAttributeNS(self::W_NS, 'left') ?: $ind->getAttributeNS(self::W_NS, 'start'));
                    $ind->setAttributeNS(self::W_NS, 'w:hanging', (string) $hanging);
                    $ind->setAttributeNS(self::W_NS, $ind->hasAttributeNS(self::W_NS, 'start') ? 'w:start' : 'w:left', (string) ($left + $hanging - $own));
                }
            }

            // The space between label and answer becomes a tab to the hanging indent.
            foreach ($texts as $i => $t) {
                $at = strpos($t->textContent, '${');
                if ($at === false) {
                    continue;
                }
                for ($j = $i - 1; $j >= 0 && trim($texts[$j]->textContent) === ''; $j--) {
                    $texts[$j]->textContent = '';
                }
                if ($j >= 0) {
                    $texts[$j]->textContent = rtrim($texts[$j]->textContent);
                }
                $before = rtrim(substr($t->textContent, 0, $at));
                $t->textContent = substr($t->textContent, $at);
                if ($before !== '') {
                    $lead = $dom->createElementNS(self::W_NS, 'w:t');
                    $lead->appendChild($dom->createTextNode($before));
                    $lead->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
                    $t->parentNode->insertBefore($lead, $t);
                }
                $t->parentNode->insertBefore($dom->createElementNS(self::W_NS, 'w:tab'), $t);
                $changed = true;
                break;
            }
        }

        return $changed ? $dom->saveXML() : $xml;
    }

    /**
     * How far from the right margin the memo heading's left column ends (twips):
     * where the TO answer wraps. From the TO | FROM table (its first column, less
     * the cell's right margin) or, in the builder's memo, from the tab before
     * FROM. Null when there is no such heading or the page width is not known.
     */
    private function leftColumnRightIndent(\DOMXPath $xpath): ?int
    {
        $section = $xpath->query('//w:body/w:sectPr')->item(0);
        if (! $section instanceof \DOMElement) {
            return null;
        }
        // Without a size or margins of its own, Word lays the page out as Letter with 1-inch margins.
        $pageWidth = (int) ($xpath->query('w:pgSz/@w:w', $section)->item(0)?->nodeValue ?? 12240);
        $textWidth = $pageWidth - (int) ($xpath->query('w:pgMar/@w:left', $section)->item(0)?->nodeValue ?? 1440)
            - (int) ($xpath->query('w:pgMar/@w:right', $section)->item(0)?->nodeValue ?? 1440);
        if ($pageWidth <= 0 || $textWidth <= 0) {
            return null;
        }

        $labelled = fn (string $label) => 'starts-with(normalize-space(translate(string(.), "abcdefghijklmnopqrstuvwxyz", "ABCDEFGHIJKLMNOPQRSTUVWXYZ")), "'.$label.'")';
        $columnEnd = null;

        // TO | FROM in a table: the first cell's width, less its right margin.
        $toCell = $xpath->query('//w:tc[not(preceding-sibling::w:tc)][following-sibling::w:tc][.//w:p['.$labelled('TO:').']]')->item(0);
        if ($toCell instanceof \DOMElement) {
            $table = $xpath->query('ancestor::w:tbl[1]', $toCell)->item(0);
            $width = (int) ($xpath->query('w:tcPr/w:tcW[@w:type="dxa"]/@w:w', $toCell)->item(0)?->nodeValue
                ?: $xpath->query('w:tblGrid/w:gridCol[1]/@w:w', $table)->item(0)?->nodeValue);
            $margin = $xpath->query('w:tblPr/w:tblCellMar/w:right/@w:w | w:tblPr/w:tblCellMar/w:end/@w:w', $table)->item(0)?->nodeValue;
            if ($width > 0) {
                $columnEnd = $width - ($margin !== null ? (int) $margin : 108);
            }
        }
        // "TO: ...<tab>FROM: ..." on one line: the tab stop before FROM, less a little room.
        if ($columnEnd === null) {
            foreach ($xpath->query('//w:p[w:r/w:tab]['.$labelled('TO:').']') as $paragraph) {
                $plain = mb_strtoupper(implode('', array_map(fn ($t) => $t->textContent, iterator_to_array($xpath->query('.//w:t', $paragraph)))));
                $stop = $xpath->query('w:pPr/w:tabs/w:tab[@w:val="left"]/@w:pos', $paragraph)->item(0)?->nodeValue;
                if (str_contains($plain, 'FROM:') && $stop !== null) {
                    $columnEnd = (int) $stop - 113;
                    break;
                }
            }
        }

        return $columnEnd !== null && $columnEnd > 0 && $columnEnd < $textWidth ? $textWidth - $columnEnd : null;
    }

    /**
     * Gives a line a right indent so it wraps in the left column. A line under it
     * drawn as this paragraph's bottom border (the ZESCO memo's, under CC) would be
     * shortened too, so the border moves to a thin empty paragraph of its own,
     * still the full width, the spacing kept.
     */
    private function keepInLeftColumn(\DOMDocument $dom, \DOMXPath $xpath, \DOMElement $paragraph, \DOMElement $pPr, int $rightIndent, int $hanging): void
    {
        $ind = $xpath->query('w:ind', $pPr)->item(0);
        if (! $ind instanceof \DOMElement) {
            $ind = $dom->createElementNS(self::W_NS, 'w:ind');
            $after = $xpath->query('w:jc|w:textAlignment|w:textDirection|w:outlineLvl|w:rPr|w:sectPr|w:pPrChange', $pPr)->item(0);
            $pPr->insertBefore($ind, $after);
            $ind->setAttributeNS(self::W_NS, 'w:left', (string) $hanging);
            $ind->setAttributeNS(self::W_NS, 'w:hanging', (string) $hanging);
        }
        $ind->setAttributeNS(self::W_NS, $ind->hasAttributeNS(self::W_NS, 'end') ? 'w:end' : 'w:right', (string) $rightIndent);

        $border = $xpath->query('w:pBdr', $pPr)->item(0);
        if (! $border instanceof \DOMElement || ! $xpath->query('w:bottom', $border)->item(0)) {
            return;
        }
        $spacing = $xpath->query('w:spacing', $pPr)->item(0);
        $after = $spacing instanceof \DOMElement ? $spacing->getAttributeNS(self::W_NS, 'after') : '';
        if ($spacing instanceof \DOMElement) {
            $spacing->setAttributeNS(self::W_NS, 'w:after', '0');
        }

        $line = $dom->createElementNS(self::W_NS, 'w:p');
        $linePr = $line->appendChild($dom->createElementNS(self::W_NS, 'w:pPr'));
        $linePr->appendChild($border);   // moved, not copied
        $lineSpacing = $linePr->appendChild($dom->createElementNS(self::W_NS, 'w:spacing'));
        $lineSpacing->setAttributeNS(self::W_NS, 'w:before', '0');
        $lineSpacing->setAttributeNS(self::W_NS, 'w:after', $after !== '' ? $after : '0');
        $lineSpacing->setAttributeNS(self::W_NS, 'w:line', '20');
        $lineSpacing->setAttributeNS(self::W_NS, 'w:lineRule', 'exact');
        $rPr = $linePr->appendChild($dom->createElementNS(self::W_NS, 'w:rPr'));
        $size = $rPr->appendChild($dom->createElementNS(self::W_NS, 'w:sz'));
        $size->setAttributeNS(self::W_NS, 'w:val', '2');
        $paragraph->parentNode->insertBefore($line, $paragraph->nextSibling);
    }

    /**
     * A value placed inside an existing w:t; line breaks become w:br.
     */
    private function inlineText(string $value): string
    {
        $lines = preg_split('/\R/u', $value) ?: [''];

        return implode(
            '</w:t><w:br/><w:t xml:space="preserve">',
            array_map(fn (string $line) => WordXml::escape($line), $lines),
        );
    }

    private function ensureDrawingNamespaces(string $xml): string
    {
        $namespaces = [
            'wp' => 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing',
            'a' => 'http://schemas.openxmlformats.org/drawingml/2006/main',
            'pic' => 'http://schemas.openxmlformats.org/drawingml/2006/picture',
            'r' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
        ];

        return preg_replace_callback('/<w:(document|hdr|ftr)\b([^>]*)>/', function (array $m) use ($namespaces) {
            $attributes = $m[2];
            foreach ($namespaces as $prefix => $uri) {
                if (! str_contains($attributes, 'xmlns:'.$prefix.'=')) {
                    $attributes .= ' xmlns:'.$prefix.'="'.$uri.'"';
                }
            }

            return '<w:'.$m[1].$attributes.'>';
        }, $xml, 1);
    }

    /**
     * @param  array<string, string>  $files
     */
    private function addImageRelationship(array &$files, string $partName, string $relationshipId, string $target = 'media/pdts-qr.png'): void
    {
        $relsName = 'word/_rels/'.basename($partName).'.rels';
        $rels = $files[$relsName] ?? false;

        if ($rels === false) {
            $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"></Relationships>';
        }

        if (! str_contains($rels, 'Id="'.$relationshipId.'"')) {
            $rels = str_replace(
                '</Relationships>',
                '<Relationship Id="'.$relationshipId.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="'.$target.'"/></Relationships>',
                $rels,
            );
            $files[$relsName] = $rels;
        }
    }

    /**
     * @param  array<string, string>  $files
     */
    private function ensureImageContentType(array &$files, string $extension): void
    {
        $types = $files['[Content_Types].xml'] ?? null;
        $mime = $extension === 'png' ? 'image/png' : 'image/jpeg';

        if ($types !== null && ! preg_match('/Extension="'.preg_quote($extension, '/').'"/i', $types)) {
            $files['[Content_Types].xml'] = str_replace('</Types>', '<Default Extension="'.$extension.'" ContentType="'.$mime.'"/></Types>', $types);
        }
    }

    /**
     * @param  callable(string, string): ?string  $callback
     */
    private function eachPart(string $docxPath, callable $callback): void
    {
        $zip = new ZipArchive;
        if ($zip->open($docxPath, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('The template is not a valid Word file.');
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match(self::PART_PATTERN, $name)) {
                $callback($name, $zip->getFromIndex($i));
            }
        }

        $zip->close();
    }
}
