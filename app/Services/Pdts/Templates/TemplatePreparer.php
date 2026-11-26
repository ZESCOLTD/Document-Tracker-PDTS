<?php

namespace App\Services\Pdts\Templates;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

/**
 * Turns a ZESCO Word template, as the office already uses it, into one PDTS
 * can fill. Nobody has to type ${placeholders}:
 *
 *  - Heading labels are found by their wording (TO:, FROM:, CC:, REF:, DATE:,
 *    SUBJECT: / RE:). Where the label is followed by nothing, a line or dots,
 *    PDTS marks that spot to be filled. Labels already followed by real text
 *    are left alone.
 *  - The QR code and tracking reference are pinned at the exact spot the
 *    administrator chose on the preview, in a floating box anchored to the
 *    page, so the template's own text, logos and signatures do not move.
 *
 * The original file is kept as it was uploaded; this makes the prepared copy
 * that DocxTemplateFiller fills for each document.
 */
class TemplatePreparer
{
    private const EMU_PER_MM = 36000;

    /** Height of the reference line under the QR code, in millimetres. */
    public const REFERENCE_LINE_MM = 6.0;

    /** Label wording => placeholder it becomes. */
    private const LABELS = [
        'to' => 'to',
        'from' => 'from',
        'cc' => 'cc',
        'c.c' => 'cc',
        'c.c.' => 'cc',
        'copy to' => 'cc',
        'copies to' => 'cc',
        'ref' => 'office_reference',
        'ref.' => 'office_reference',
        'ref no' => 'office_reference',
        'ref no.' => 'office_reference',
        'our ref' => 'office_reference',
        'reference' => 'office_reference',
        'date' => 'date',
        'day month year' => 'date',
        // What PDTS already knows: the document's date, the author's department, the signatory.
        'request date' => 'date',
        'date of request' => 'date',
        'requesting department' => 'department',
        'requested by' => 'author',
        'prepared by' => 'author',
        'subject' => 'subject',
        'subj' => 'subject',
        're' => 'subject',
    ];

    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    private const R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const FOOTER_TYPE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer';

    private const HEADER_TYPE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/header';

    /**
     * The first page's size in millimetres, for the preview.
     *
     * @return array{width: float, height: float}
     */
    public function pageSize(string $docxPath): array
    {
        $dom = $this->load(DocxPackage::read($docxPath)['word/document.xml']);
        $size = $this->xpath($dom)->query('//w:sectPr/w:pgSz')->item(0);

        $twips = fn (string $attribute, int $default) => $size instanceof DOMElement && $size->getAttributeNS(self::W, $attribute) !== ''
            ? (int) $size->getAttributeNS(self::W, $attribute) : $default;

        // Word's default page is A4: 11906 x 16838 twentieths of a point.
        return [
            'width' => round($twips('w', 11906) * 25.4 / 1440, 1),
            'height' => round($twips('h', 16838) * 25.4 / 1440, 1),
        ];
    }

    /**
     * @param  array{page: string, x: float, y: float, size: float}  $placement
     *                                                                           page: "all" or "first"; x, y: top-left of the QR in mm from the page's top-left; size in mm
     * @return array{contents: string, fields: list<string>, qr: string}
     *                                                                   fields: placeholders added from labels; qr: "placed", or "as_typed" when the file already had ${qr_code}
     */
    public function prepare(string $docxPath, array $placement, array $marks = [], bool $built = false): array
    {
        $this->built = $built;
        $this->files = DocxPackage::read($docxPath);
        $this->labels = [];
        $this->clearedHints = [];
        $this->choices = [];
        $this->tables = [];
        $this->signatureMarked = false;
        $fields = [];

        // 0. Lines the administrator marked on the preview, before anything else
        //    moves lines around.
        [$markFields, $markReport] = $this->applyMarks($marks);
        $fields = [...$fields, ...$markFields];

        // 1. Heading labels in the body, headers and footers.
        foreach ($this->partNames() as $name) {
            $dom = $this->load($this->read($name));
            $found = $this->markLabels($dom);
            if ($found) {
                $fields = [...$fields, ...$found];
                $this->write($name, $dom->saveXML());
            }
        }

        // 2. In the body: lines that are only a [hint], the spaces to write
        //    in (the memo's body, each report section) and where the author's
        //    signature goes.
        $fields = [...$fields, ...$this->markDocumentBody()];

        // 2b. Two fields on one line, side by side with a tab ("TO: ... <tab> FROM: ..."):
        //     each in its own column, so a long answer wraps under itself, not under the other.
        $this->pairsIntoColumns();

        // 3. The QR code goes where the administrator placed it. A ${qr_code}
        //    already typed in the file (for example a file PDTS prepared before)
        //    is taken out first, so there is only ever one.
        $hadQr = $this->removeTypedQr();
        $this->placeQr($placement);
        $qr = $hadQr ? 'replaced' : 'placed';

        // Fields PDTS took over (a "Name:" beside the signature became the signatory's
        // name) are no longer questions.
        $text = implode('', array_map(fn ($name) => $this->plainText((string) $this->read($name)), $this->partNames()));
        $fields = array_filter($fields, fn ($field) => str_contains($text, '${'.$field.'}'));
        $this->labels = array_filter($this->labels, fn ($field) => str_contains($text, '${'.$field.'}'), ARRAY_FILTER_USE_KEY);

        $contents = DocxPackage::write($this->files);
        $this->files = [];

        return ['contents' => $contents, 'fields' => array_values(array_unique($fields)), 'qr' => $qr, 'labels' => $this->labels, 'choices' => $this->choices, 'tables' => $this->tables, 'marks' => $markReport];
    }

    /**
     * A line holding two fields separated by one tab, such as the memo's
     * "TO: ${to} <tab> FROM: ${from}", becomes a borderless table of two
     * columns split at the tab stop. It prints exactly as before, but a long
     * answer (a long job title) wraps inside its own column instead of
     * running back to the left margin under the other field.
     */
    private function pairsIntoColumns(): void
    {
        $xml = $this->read('word/document.xml');
        if ($xml === false) {
            return;
        }

        $dom = $this->load($xml);
        $xpath = $this->xpath($dom);
        $body = $xpath->query('/w:document/w:body')->item(0);
        if (! $body instanceof DOMElement) {
            return;
        }
        $w = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

        // The width text can use, from the page size and margins.
        $section = $xpath->query('w:sectPr', $body)->item(0);
        $page = (int) ($section ? $xpath->evaluate('string(w:pgSz/@w:w)', $section) : 0);
        $left = (int) ($section ? $xpath->evaluate('string(w:pgMar/@w:left)', $section) : 0);
        $right = (int) ($section ? $xpath->evaluate('string(w:pgMar/@w:right)', $section) : 0);
        $width = $page > 0 ? max(4000, $page - $left - $right) : 9638;

        $element = function (string $name, array $attributes = []) use ($dom, $w): DOMElement {
            $node = $dom->createElementNS($w, 'w:'.$name);
            foreach ($attributes as $key => $value) {
                $node->setAttributeNS($w, 'w:'.$key, (string) $value);
            }

            return $node;
        };

        $changed = false;
        foreach (iterator_to_array($xpath->query('w:p', $body)) as $paragraph) {
            $runs = iterator_to_array($xpath->query('w:r', $paragraph));
            // Exactly one run that is only a tab (a w:tab, or a tab character in its
            // text, as in the ZESCO memo), with a field on each side of it.
            $isTab = fn ($run) => ($xpath->query('w:tab', $run)->length > 0 || str_contains($run->textContent, "\t"))
                && trim(str_replace("\t", '', $run->textContent)) === '';
            $hasTab = fn ($run) => $xpath->query('w:tab', $run)->length > 0 || str_contains($run->textContent, "\t");
            $tabs = array_keys(array_filter($runs, $isTab));
            if (count($tabs) !== 1 || count(array_filter($runs, $hasTab)) !== 1) {
                continue;
            }
            $text = fn (array $part) => implode('', array_map(fn ($run) => $run->textContent, $part));
            $leftRuns = array_slice($runs, 0, $tabs[0]);
            $rightRuns = array_slice($runs, $tabs[0] + 1);
            if (! str_contains($text($leftRuns), '${') || ! str_contains($text($rightRuns), '${')
                || $xpath->query('w:hyperlink|w:fldSimple|w:ins|w:del|w:sdt', $paragraph)->length > 0) {
                continue;
            }

            // The tab stop says where the second column starts.
            $stop = (int) $xpath->evaluate('string(w:pPr/w:tabs/w:tab[@w:val="left" or @w:val="start"][1]/@w:pos)', $paragraph);
            $split = $stop > 600 && $stop < $width - 600 ? $stop : intdiv($width, 2);

            $pPr = $xpath->query('w:pPr', $paragraph)->item(0);
            $cellParagraph = function (array $content) use ($pPr, $element): DOMElement {
                $p = $element('p');
                if ($pPr instanceof DOMElement) {
                    $copy = $pPr->cloneNode(true);
                    foreach (iterator_to_array($copy->childNodes) as $child) {
                        if ($child instanceof DOMElement && in_array($child->localName, ['tabs', 'ind', 'numPr'], true)) {
                            $copy->removeChild($child);
                        }
                    }
                    $p->appendChild($copy);
                }
                foreach ($content as $run) {
                    $p->appendChild($run);
                }

                return $p;
            };

            $table = $element('tbl');
            $properties = $table->appendChild($element('tblPr'));
            $properties->appendChild($element('tblW', ['w' => $width, 'type' => 'dxa']));
            $properties->appendChild($element('tblLayout', ['type' => 'fixed']));
            $borders = $properties->appendChild($element('tblBorders'));
            foreach (['top', 'left', 'bottom', 'right', 'insideH', 'insideV'] as $side) {
                $borders->appendChild($element($side, ['val' => 'nil']));
            }
            $margins = $properties->appendChild($element('tblCellMar'));
            $margins->appendChild($element('left', ['w' => 0, 'type' => 'dxa']));
            $margins->appendChild($element('right', ['w' => 113, 'type' => 'dxa']));
            $grid = $table->appendChild($element('tblGrid'));
            $grid->appendChild($element('gridCol', ['w' => $split]));
            $grid->appendChild($element('gridCol', ['w' => $width - $split]));
            $row = $table->appendChild($element('tr'));
            foreach ([[$split, $leftRuns], [$width - $split, $rightRuns]] as [$cellWidth, $content]) {
                $cell = $row->appendChild($element('tc'));
                $cell->appendChild($element('tcPr'))->appendChild($element('tcW', ['w' => $cellWidth, 'type' => 'dxa']));
                $cell->appendChild($cellParagraph($content));
            }

            $paragraph->parentNode->replaceChild($table, $paragraph);
            $changed = true;
        }

        if ($changed) {
            $this->write('word/document.xml', $dom->saveXML());
        }
    }

    /**
     * The body's lines in order, as the preview shows them (text boxes left
     * out). A mark names a line by its position in this list.
     *
     * @return list<DOMElement>
     */
    private function bodyLines(DOMXPath $xpath): array
    {
        return iterator_to_array($xpath->query('//w:body//w:p[not(ancestor::w:txbxContent)]'));
    }

    /**
     * Applies the administrator's marks: a line becomes a field, a space to
     * write in, or the signature spot. Each mark keeps the line's text, so a
     * mark that no longer matches its line (the file changed) is skipped.
     *
     * @param  list<array{line: int, text?: string, kind: string, label?: string}>  $marks
     * @return array{0: list<string>, 1: list<array{line: int, kind: string, label: string, applied: bool, name: ?string}>}
     */
    private function applyMarks(array $marks): array
    {
        if ($marks === [] || ($xml = $this->read('word/document.xml')) === false) {
            return [[], []];
        }

        $dom = $this->load($xml);
        $xpath = $this->xpath($dom);
        $lines = $this->bodyLines($xpath);
        $found = [];
        $report = [];

        usort($marks, fn ($a, $b) => ($b['line'] ?? 0) <=> ($a['line'] ?? 0));
        foreach ($marks as $mark) {
            $index = (int) ($mark['line'] ?? -1);
            $kind = (string) ($mark['kind'] ?? '');
            $label = trim((string) ($mark['label'] ?? ''));
            $paragraph = $lines[$index] ?? null;
            $entry = ['line' => $index, 'kind' => $kind, 'label' => $label, 'applied' => false, 'name' => null];

            $expected = $this->squash((string) ($mark['text'] ?? ''));
            if (! $paragraph instanceof DOMElement || ($expected !== '' && $this->squash($this->paragraphText($xpath, $paragraph)) !== $expected)) {
                $report[] = $entry; // the line is not where it was: skipped

                continue;
            }

            $text = trim($this->paragraphText($xpath, $paragraph));
            $name = match ($kind) {
                'signature' => 'signature',
                'writing' => 'text_'.$this->slugOf($label !== '' ? $label : 'section'),
                'field' => $this->slugOf($label !== '' ? $label : 'field'),
                default => null,
            };
            if ($name === null) {
                $report[] = $entry;

                continue;
            }
            if ($kind === 'field' && ! ctype_alpha($name[0])) {
                $name = 'field_'.$name;
            }

            if ($kind === 'signature') {
                // Only one signature spot: one marked by hand replaces any found automatically.
                $this->signatureMarked = true;
            }

            if ($text === '' || $this->isHintOnly($text)) {
                // An empty line or one holding only a [hint]: the placeholder takes its place.
                foreach (iterator_to_array($xpath->query('.//w:t', $paragraph)) as $t) {
                    $t->textContent = '';
                }
                $this->putPlaceholder($dom, $paragraph, $name);
            } elseif ($kind === 'writing') {
                // After a heading: the space to write in is a new line below it.
                $new = $dom->createElementNS(self::W, 'w:p');
                $properties = $xpath->query('w:pPr', $paragraph)->item(0);
                if ($properties instanceof DOMElement) {
                    $new->appendChild($properties->cloneNode(true));
                }
                $paragraph->parentNode->insertBefore($new, $paragraph->nextSibling);
                $this->putPlaceholder($dom, $new, $name);
            } else {
                // A line with its label: the value goes at the end of it.
                $runs = $xpath->query('.//w:r[not(ancestor::w:txbxContent)]', $paragraph);
                if ($runs->length > 0) {
                    $this->insertRunAfter($dom, $runs->item($runs->length - 1), ' ${'.$name.'}');
                } else {
                    $this->putPlaceholder($dom, $paragraph, $name);
                }
            }

            if ($kind !== 'signature' && $label !== '') {
                $this->labels[$name] = self::displayLabel($label);
            }
            $found[] = $name;
            $report[] = ['applied' => true, 'name' => $name] + $entry;
        }

        $this->write('word/document.xml', $dom->saveXML());

        return [$found, array_reverse($report)];
    }

    /**
     * A file made by the Template Builder: laid out exactly as designed, so
     * nothing is guessed from its look (no writing space between two
     * headings, no field from text in brackets that the designer typed).
     */
    private bool $built = false;

    /** True once a signature spot was marked by hand, so none is looked for. */
    private bool $signatureMarked = false;

    private function squash(string $text): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($text)));
    }

    private function slugOf(string $label): string
    {
        $slug = trim(preg_replace('/[^a-z0-9]+/', '_', mb_strtolower($label)), '_');

        return $slug === '' ? 'field' : $slug;
    }

    /**
     * Finds "LABEL:" followed only by blanks, lines or dots, clears the filler
     * and puts the placeholder in its place, keeping the template's own tabs.
     *
     * @return list<string>
     */
    private function markLabels(DOMDocument $dom): array
    {
        $xpath = $this->xpath($dom);
        $found = [];
        // A label starts a line or follows a tab or a wide gap, and ends with ":".
        $pattern = '/(?<=^|\t|\s{2})([A-Za-z][A-Za-z.&\'\/() -]{0,40}?)\s*:/u';

        foreach ($xpath->query('//w:p') as $paragraph) {
            // The paragraph's text, with each character traced back to its text node.
            $map = [];
            $text = '';
            foreach ($xpath->query('.//w:r', $paragraph) as $run) {
                foreach ($run->childNodes as $child) {
                    if (! $child instanceof DOMElement) {
                        continue;
                    }
                    if ($child->localName === 't') {
                        $chars = mb_str_split($child->textContent);
                        foreach ($chars as $i => $char) {
                            $map[] = ['node' => $child, 'run' => $run, 'index' => $i];
                            $text .= $char;
                        }
                    } elseif ($child->localName === 'tab') {
                        $map[] = ['node' => null, 'run' => $run, 'index' => null];
                        $text .= "\t";
                    }
                }
            }

            if ($text === '' || ! preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            // Work from the last label back, so earlier positions stay valid.
            $labelsHere = [];
            foreach ($matches[0] as $i => [$whole, $byteOffset]) {
                $start = mb_strlen(substr($text, 0, $byteOffset));
                $labelsHere[] = [
                    'name' => $this->fieldName($matches[1][$i][0]),
                    'text' => $matches[1][$i][0],
                    'start' => $start,
                    'end' => $start + mb_strlen($whole),
                ];
            }

            $length = mb_strlen($text);
            for ($i = count($labelsHere) - 1; $i >= 0; $i--) {
                $label = $labelsHere[$i];
                $regionEnd = $labelsHere[$i + 1]['start'] ?? $length;
                $region = mb_substr($text, $label['end'], $regionEnd - $label['end']);

                if ($label['name'] === null || ! $this->isBlank($region)) {
                    continue; // a signature line, or real text already there: leave it
                }

                // The value goes straight after the label, or after the first
                // tab that follows it, so "TO:<tab>____<tab>FROM:" keeps its
                // layout: "TO:<tab>value<tab>FROM:".
                $labelRun = $map[$label['end'] - 1]['run'];
                $anchor = $labelRun;
                for ($pos = $label['end']; $pos < $regionEnd; $pos++) {
                    if ($map[$pos]['node'] === null) {
                        $anchor = $map[$pos]['run'];
                        break;
                    }
                    if (trim(mb_substr($text, $pos, 1)) !== '') {
                        break;
                    }
                }

                // A [hint] here may be repeated on the lines below (several CC
                // lines); those repeats are taken out with it.
                if (str_contains($region, '[')) {
                    $this->clearedHints[] = $this->normalHint($region);
                }

                // Clear the filler (lines, dots, [hints]); the template's tabs stay,
                // whether they are tab elements or tab characters typed in the text.
                $removals = [];
                for ($pos = $label['end']; $pos < $regionEnd; $pos++) {
                    if ($map[$pos]['node'] !== null && mb_substr($text, $pos, 1) !== "\t") {
                        $removals[] = $map[$pos];
                    }
                }
                foreach (array_reverse($removals) as $entry) {
                    $node = $entry['node'];
                    $chars = mb_str_split($node->textContent);
                    unset($chars[$entry['index']]);
                    $node->textContent = implode('', $chars);
                }

                $this->insertRunAfter($dom, $anchor, ($anchor === $labelRun ? ' ' : '').'${'.$label['name'].'}');
                $found[] = $label['name'];
                $this->labels[$label['name']] ??= self::displayLabel($label['text']);
            }
        }

        return $found;
    }

    /** @var array<string, string> placeholder => label as written in the file */
    private array $labels = [];

    /** @var list<string> [hints] cleared after a label, e.g. "[Title of person copied]" */
    private array $clearedHints = [];

    /**
     * Goes through the body of the document, top to bottom:
     *
     *  - A line that is only a [hint] becomes something to fill in: the
     *    subject, the author's name, or a field named after the hint. A hint
     *    that repeats one already cleared after a label (the extra CC lines)
     *    is taken out.
     *  - An empty line after a section heading becomes a space to write that
     *    section in (text_<heading>), and the first empty line after the
     *    subject becomes the body (text_body).
     *  - The author's signature spot is marked (${signature}).
     *
     * @return list<string> placeholders added
     */
    private function markDocumentBody(): array
    {
        $xml = $this->read('word/document.xml');
        if ($xml === false) {
            return [];
        }

        $dom = $this->load($xml);
        $xpath = $this->xpath($dom);
        $body = $xpath->query('/w:document/w:body')->item(0);
        if (! $body instanceof DOMElement) {
            return [];
        }

        $found = [];
        $subjectSeen = false;
        // A file that already has its body (typed, or made by the Template Builder) gets no second one.
        $bodyDone = str_contains($this->plainText($dom->saveXML()), '${text_body}');
        $previous = null;
        $headingsUsed = [];
        // "[Full Name]" lines: [paragraph, before the subject and body?].
        $nameHints = [];

        // The space to write a section in (text_<heading>), on an empty line under its heading.
        // A line holding a hint ("[BODY]") keeps its own look; an empty line takes the text's.
        $sectionSpace = function (DOMElement $heading, DOMElement $line) use ($dom, $xpath, &$found, &$headingsUsed): void {
            $headingsUsed[spl_object_id($heading)] = true;
            $title = $this->headingTitle($this->paragraphText($xpath, $heading));
            $slug = trim(preg_replace('/[^a-z0-9]+/', '_', mb_strtolower($title)), '_');
            $name = 'text_'.($slug !== '' ? $slug : 'section');
            for ($n = 2; in_array($name, $found, true); $n++) {
                $name = 'text_'.($slug !== '' ? $slug : 'section').'_'.$n;
            }
            $texts = iterator_to_array($xpath->query('.//w:t', $line));
            if ($texts !== []) {
                foreach ($texts as $i => $t) {
                    $t->textContent = $i === 0 ? '${'.$name.'}' : '';
                }
            } else {
                $this->putPlaceholder($dom, $line, $name);
            }
            $found[] = $name;
            $this->labels[$name] ??= self::displayLabel($title);
        };
        // A heading not yet given a space to write in.
        $openHeading = fn (?DOMElement $node) => $node instanceof DOMElement && $node->localName === 'p'
            && ! isset($headingsUsed[spl_object_id($node)]) && $this->isHeading($xpath, $node);

        foreach (iterator_to_array($xpath->query('w:p|w:tbl', $body)) as $node) {
            if ($node->localName === 'tbl') {
                // A box to write in under a heading (a table of one empty cell): the text goes inside the box.
                $cells = $xpath->query('w:tr/w:tc', $node);
                $inBox = $cells->length === 1 ? $xpath->query('w:p', $cells->item(0))->item(0) : null;
                if (! $this->built && $openHeading($previous) && $inBox instanceof DOMElement
                    && trim($this->paragraphText($xpath, $cells->item(0))) === '' && ! $this->hasPicture($xpath, $cells->item(0))) {
                    $sectionSpace($previous, $inBox);
                }
                $previous = $node;

                continue;
            }

            $text = $this->paragraphText($xpath, $node);

            // "[BODY]" under a section heading: that section's own space, not the document's one body.
            if (! $this->built && $this->isHintOnly($text) && $openHeading($previous) && $this->isBodyHint(trim(preg_replace('/[\[\]]+/u', ' ', $this->normalHint($text))))) {
                $sectionSpace($previous, $node);
                $previous = $node;

                continue;
            }

            if ($this->isHintOnly($text)) {
                $name = $this->markHint($dom, $xpath, $node, $text);
                if ($name === false) {
                    continue; // taken out
                }
                if ($name !== null) {
                    if ($name === 'author' || $name === 'author_upper') {
                        $nameHints[] = [$node, ! $subjectSeen && ! $bodyDone];
                    }
                    $found[] = $name;
                    $subjectSeen = $subjectSeen || $name === 'subject';
                    // A [BODY] hint is the body: no other empty line becomes one.
                    $bodyDone = $bodyDone || $name === 'text_body';
                }
                $previous = $node;

                continue;
            }

            // A page break is not an empty line to write in.
            if (trim($text) === '' && ! $this->hasPicture($xpath, $node) && $xpath->query('.//w:br[@w:type="page"]', $node)->length > 0) {
                continue;
            }

            if (trim($text) === '' && ! $this->hasPicture($xpath, $node)) {
                // An empty line between a heading and its table is only spacing.
                if ($openHeading($previous)) {
                    if ($this->built || ! $this->tableFollows($xpath, $node)) {
                        $sectionSpace($previous, $node);
                    }
                } elseif (! $this->built && $subjectSeen && ! $bodyDone) {
                    $this->putPlaceholder($dom, $node, 'text_body');
                    $found[] = 'text_body';
                    $this->labels['text_body'] ??= 'Body';
                    $bodyDone = true;
                }

                continue;
            }

            // A heading straight after another heading, with no line between them
            // ("1. EXECUTIVE SUMMARY", "2. INTRODUCTION", ...): the first gets a line to write in.
            if (! $this->built && $previous instanceof DOMElement && $previous->localName === 'p' && ! isset($headingsUsed[spl_object_id($previous)])
                && $this->isHeading($xpath, $previous) && $this->isHeading($xpath, $node)) {
                $line = $dom->createElementNS(self::W, 'w:p');
                // In the document's own text font, not the heading's.
                $line->appendChild($dom->createElementNS(self::W, 'w:pPr'))->appendChild($dom->createElementNS(self::W, 'w:rPr'));
                $node->parentNode->insertBefore($line, $node);
                $sectionSpace($previous, $line);
            }

            // Hints inside the line's text ("Dear [Mr./Ms. Surname]").
            if (! $this->built && preg_match('/\[[^\[\]]+\]/u', $text)) {
                foreach ($this->markInlineHints($xpath, $node) as $name) {
                    if ($name === 'author' || $name === 'author_upper') {
                        $nameHints[] = [$node, ! $subjectSeen && ! $bodyDone];
                    }
                    $found[] = $name;
                }
                $text = $this->paragraphText($xpath, $node);
            }

            $subjectSeen = $subjectSeen || str_contains($text, '${subject}');
            $previous = $node;
        }

        // A letter has two names: the person it goes to, in the address before the
        // subject, and the signatory after "Yours sincerely". The signatory is the last.
        if (count($nameHints) > 1) {
            foreach (array_slice($nameHints, 0, -1) as [$paragraph, $beforeBody]) {
                if (! $beforeBody) {
                    continue;
                }
                foreach ($xpath->query('.//w:t', $paragraph) as $t) {
                    $t->textContent = str_replace(['${author_upper}', '${author}'], '${recipient_name}', $t->textContent);
                }
                $found[] = 'recipient_name';
                $this->labels['recipient_name'] ??= "Recipient's full name";
            }
        }

        // Grids to fill in row by row (action points, passengers): a table the author adds rows to.
        $found = [...$found, ...$this->markGridTables($dom, $xpath, $body, $found)];
        // Forms laid out as tables: a label in one cell, the answer in the empty cell beside it.
        $found = [...$found, ...$this->markTableFields($dom, $xpath, $body, $found)];

        // A file that already has its signature spot (typed, or made by the Template Builder) keeps just that one.
        $hasSpot = str_contains($this->plainText($dom->saveXML()), '${signature}');
        if (! $this->signatureMarked && ! $hasSpot && $this->markSignature($dom, $xpath, $body)) {
            $found[] = 'signature';
        }
        $this->claimSignerLines($xpath, $body);

        $this->write('word/document.xml', $dom->saveXML());

        return $found;
    }

    /**
     * Forms laid out as tables, such as a request form or a voucher: a row
     * that goes label, empty cell, label, empty cell ("Request no. | | Date | ")
     * gets each answer in the empty cell beside its label. A cell of tick
     * boxes beside a label ("☐ Within town ☐ Out of town") becomes a choice
     * list of those options.
     *
     * Only rows that are wholly label / answer pairs are taken, so grids are
     * left alone: a list with a heading row and numbered empty rows (the
     * passengers), or an approvals table (Stage | Name | Signature | Date),
     * stay as they are, to fill in by hand.
     *
     * @param  list<string>  $taken  placeholder names already used
     * @return list<string> placeholders added
     */
    private function markTableFields(DOMDocument $dom, DOMXPath $xpath, DOMElement $body, array $taken): array
    {
        $found = [];
        $boxes = '/[\x{2610}\x{2611}\x{2612}\x{25A1}]/u';   // ☐ ☑ ☒ □

        foreach (iterator_to_array($xpath->query('.//w:tbl/w:tr', $body)) as $row) {
            $cells = iterator_to_array($xpath->query('w:tc', $row));
            if ($cells === [] || count($cells) % 2 !== 0) {
                continue;
            }

            // The whole row must be label / answer pairs.
            $pairs = [];
            for ($i = 0; $i < count($cells); $i += 2) {
                $label = trim(preg_replace('/\s+/u', ' ', $this->paragraphText($xpath, $cells[$i])));
                $answer = trim($this->paragraphText($xpath, $cells[$i + 1]));
                $isLabel = $label !== '' && ! str_contains($label, '${') && ! preg_match($boxes, $label)
                    && ! preg_match('/^[\d.\s]+$/u', $label) && ! $this->hasPicture($xpath, $cells[$i]);
                $choices = $answer !== '' && preg_match($boxes, $answer)
                    ? array_values(array_filter(array_map(fn ($c) => trim($c, " \t\u{00A0}"), preg_split($boxes, $answer)), fn ($c) => $c !== ''))
                    : null;
                $isAnswer = ($answer === '' && ! $this->hasPicture($xpath, $cells[$i + 1])) || ($choices !== null && count($choices) >= 2);
                if (! $isLabel || ! $isAnswer) {
                    continue 2;
                }
                $pairs[] = [$label, $cells[$i], $cells[$i + 1], $choices];
            }

            foreach ($pairs as [$label, $labelCell, $answerCell, $choices]) {
                $label = rtrim($label, ': ');
                $name = $this->fieldName($label);
                if ($name === null) {
                    continue; // a signature or stamp: signed by hand
                }
                // The same label twice (e.g. "Date" in two sections): the second is date_2.
                $base = $name;
                for ($n = 2; in_array($name, [...$taken, ...$found], true); $n++) {
                    $name = $base.'_'.$n;
                }

                $this->putInCell($dom, $xpath, $answerCell, $labelCell, $name);
                $found[] = $name;
                $this->labels[$name] ??= self::displayLabel($label);
                if ($choices !== null) {
                    $this->choices[$name] = $choices;
                }
            }
        }

        return $found;
    }

    /**
     * A grid to fill in row by row: a heading row ("No. | Action | Responsible
     * | Due date") over rows that are empty, or numbered 1, 2, 3 in the first
     * column. It becomes a table the author adds as many rows to as needed
     * (text_<heading above it>), printed with the same headings; a numbering
     * column is left out. A table whose rows have their own labels (an
     * approvals table: Requested by, Head of Department) is not a grid.
     *
     * @param  list<string>  $taken  placeholder names already used
     * @return list<string> placeholders added
     */
    private function markGridTables(DOMDocument $dom, DOMXPath $xpath, DOMElement $body, array $taken): array
    {
        $found = [];

        foreach (iterator_to_array($xpath->query('w:tbl', $body)) as $table) {
            $rows = iterator_to_array($xpath->query('w:tr', $table));
            if (count($rows) < 2) {
                continue;
            }
            $text = fn (DOMElement $cell) => trim(preg_replace('/\s+/u', ' ', $this->paragraphText($xpath, $cell)));
            $headings = array_map($text, iterator_to_array($xpath->query('w:tc', $rows[0])));
            if (count($headings) < 2 || in_array('', $headings, true) || str_contains(implode('', $headings), '${')) {
                continue;
            }

            // Every other row: empty, but for a number in the first column. Total rows
            // at the end ("GRAND TOTAL (K)" and an empty cell) become questions of their own.
            $numbered = false;
            $totals = [];
            foreach (array_slice($rows, 1) as $row) {
                $cells = iterator_to_array($xpath->query('w:tc', $row));
                $words = array_values(array_filter(array_map($text, $cells), fn ($value) => $value !== ''));
                if (count($words) === 1 && count($cells) > 1 && preg_match('/\btotal\b/i', $words[0])) {
                    $totals[] = $words[0];

                    continue;
                }
                if ($totals !== [] || count($cells) !== count($headings)) {
                    continue 2;
                }
                foreach ($cells as $i => $cell) {
                    $value = $text($cell);
                    if ($value === '' && ! $this->hasPicture($xpath, $cell)) {
                        continue;
                    }
                    if ($i === 0 && preg_match('/^\d+\.?$/', $value)) {
                        $numbered = true;

                        continue;
                    }

                    continue 3;
                }
            }

            if (count($rows) - 1 - count($totals) < 1) {
                continue; // no rows to fill in
            }

            // The numbering column ("No.", "#", "S/N") is not something the author writes.
            $columns = $headings;
            if ($numbered || preg_match('/^(no\.?|#|s\/?n|item)$/i', $columns[0])) {
                array_shift($columns);
            }
            if ($columns === []) {
                continue;
            }

            // Named after the heading above it ("E. ACTION POINTS" -> text_action_points), else after its columns.
            $above = $table->previousSibling;
            while ($above !== null && (! $above instanceof DOMElement || ($above->localName === 'p' && trim($this->paragraphText($xpath, $above)) === ''))) {
                $above = $above->previousSibling;
            }
            // The section letter or number goes ("E. ACTION POINTS", "3. ACTION POINTS").
            $title = $above instanceof DOMElement && $above->localName === 'p'
                ? preg_replace('/^\s*[A-Za-z]\.\s+/u', '', $this->headingTitle($this->paragraphText($xpath, $above))) : '';
            $title = $title !== '' && mb_strlen($title) <= 60 ? $title : implode(' ', array_slice($columns, 0, 2));
            $base = 'text_'.(trim(preg_replace('/[^a-z0-9]+/', '_', mb_strtolower($title)), '_') ?: 'table');
            $name = $base;
            for ($n = 2; in_array($name, [...$taken, ...$found], true); $n++) {
                $name = $base.'_'.$n;
            }

            // The grid gives way to one line holding the placeholder, in the headings' font without the bold.
            $paragraph = $dom->createElementNS(self::W, 'w:p');
            $run = $dom->createElementNS(self::W, 'w:r');
            $font = $xpath->query('.//w:r[w:t[normalize-space()]]/w:rPr', $rows[0])->item(0);
            if ($font instanceof DOMElement) {
                $copy = $font->cloneNode(true);
                foreach (['b', 'bCs', 'caps', 'u', 'color'] as $strip) {
                    foreach (iterator_to_array($copy->getElementsByTagNameNS(self::W, $strip)) as $node) {
                        $copy->removeChild($node);
                    }
                }
                $run->appendChild($copy);
            }
            $t = $dom->createElementNS(self::W, 'w:t');
            $t->appendChild($dom->createTextNode('${'.$name.'}'));
            $run->appendChild($t);
            $paragraph->appendChild($run);
            $table->parentNode->replaceChild($paragraph, $table);

            $found[] = $name;
            $this->labels[$name] ??= self::displayLabel($title);
            $this->tables[$name] = array_values($columns);

            // Each total under the list: "Grand total (K): ${grand_total_k}". PDTS does not add up; the author types it.
            $after = $paragraph;
            foreach ($totals as $label) {
                $label = rtrim($label, ': ');
                $field = $this->fieldName($label);
                if ($field === null) {
                    continue;
                }
                for ($n = 2, $base = $field; in_array($field, [...$taken, ...$found], true); $n++) {
                    $field = $base.'_'.$n;
                }
                $line = $dom->createElementNS(self::W, 'w:p');
                foreach ([[$label.': ', true], ['${'.$field.'}', false]] as [$words, $bold]) {
                    $r = $dom->createElementNS(self::W, 'w:r');
                    if ($bold) {
                        $r->appendChild($dom->createElementNS(self::W, 'w:rPr'))->appendChild($dom->createElementNS(self::W, 'w:b'));
                    }
                    $t = $dom->createElementNS(self::W, 'w:t');
                    $t->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
                    $t->appendChild($dom->createTextNode($words));
                    $r->appendChild($t);
                    $line->appendChild($r);
                }
                $after->parentNode->insertBefore($line, $after->nextSibling);
                $after = $line;
                $found[] = $field;
                $this->labels[$field] ??= self::displayLabel($label);
            }
        }

        return $found;
    }

    /**
     * The row the PDTS signature goes in (an approvals table): its other empty
     * cells filled from the signatory too, by their column heading. "Name"
     * gets the signatory's name ("Name and job title" or "Position": the job
     * title under it), "Date" the document's date.
     *
     * @param  \DOMNodeList<DOMElement>  $headings
     * @param  \DOMNodeList<DOMElement>  $cells
     */
    private function fillSignatoryRow(DOMDocument $dom, DOMXPath $xpath, \DOMNodeList $headings, \DOMNodeList $cells): void
    {
        foreach ($headings as $index => $heading) {
            $title = mb_strtolower(trim($this->paragraphText($xpath, $heading)));
            $cell = $cells->item($index);
            $paragraph = $cell instanceof DOMElement ? $xpath->query('w:p', $cell)->item(0) : null;
            if (! $paragraph instanceof DOMElement || trim($this->paragraphText($xpath, $cell)) !== '' || str_contains($this->paragraphText($xpath, $cell), '${')) {
                continue;
            }

            $name = (bool) preg_match('/\bname\b/u', $title);
            $jobTitle = (bool) preg_match('/\b(job title|title|position|designation)\b/u', $title);
            if ($name || $jobTitle) {
                $this->putPlaceholder($dom, $paragraph, $name ? 'author' : 'author_job_title');
                if ($name && $jobTitle) {
                    // The job title on the line under the name, in the same look.
                    $run = $paragraph->lastChild->cloneNode(true);
                    foreach (iterator_to_array($xpath->query('w:t', $run)) as $t) {
                        $run->removeChild($t);
                    }
                    $run->appendChild($dom->createElementNS(self::W, 'w:br'));
                    $t = $dom->createElementNS(self::W, 'w:t');
                    $t->appendChild($dom->createTextNode('${author_job_title}'));
                    $run->appendChild($t);
                    $paragraph->appendChild($run);
                }
            } elseif (preg_match('/^date\b/u', $title)) {
                $this->putPlaceholder($dom, $paragraph, 'date');
            }
        }
    }

    /** @var array<string, list<string>> placeholder => the column headings of a grid made into a table field */
    private array $tables = [];

    /**
     * The answer's placeholder alone in a table cell (tick boxes or filler
     * taken out), in the label cell's font without its bold.
     */
    private function putInCell(DOMDocument $dom, DOMXPath $xpath, DOMElement $cell, DOMElement $labelCell, string $name): void
    {
        $paragraphs = iterator_to_array($xpath->query('w:p', $cell));
        if ($paragraphs === []) {
            $paragraphs = [$cell->appendChild($dom->createElementNS(self::W, 'w:p'))];
        }
        foreach (array_slice($paragraphs, 1) as $extra) {
            $cell->removeChild($extra);
        }
        $paragraph = $paragraphs[0];
        foreach (iterator_to_array($xpath->query('w:r|w:hyperlink|w:ins', $paragraph)) as $old) {
            $paragraph->removeChild($old);
        }

        $run = $dom->createElementNS(self::W, 'w:r');
        $font = $xpath->query('.//w:r[w:t[normalize-space()]]/w:rPr', $labelCell)->item(0);
        if ($font instanceof DOMElement) {
            $copy = $font->cloneNode(true);
            foreach (['b', 'bCs', 'caps', 'u', 'color'] as $strip) {
                foreach (iterator_to_array($copy->getElementsByTagNameNS(self::W, $strip)) as $node) {
                    $copy->removeChild($node);
                }
            }
            $run->appendChild($copy);
        }
        $t = $dom->createElementNS(self::W, 'w:t');
        $t->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
        $t->appendChild($dom->createTextNode('${'.$name.'}'));
        $run->appendChild($t);
        $paragraph->appendChild($run);
    }

    /** @var array<string, list<string>> placeholder => the tick-box options found beside its label */
    private array $choices = [];

    /**
     * A repeated line being taken out (the memo's extra CC lines) may carry
     * the look of the block: the line under it (a bottom border) and the
     * indent of the continuation lines. Both move to the line above (the CC
     * line), so the separator stays and the names after the first line up
     * under it.
     */
    private function keepLookOnPrevious(DOMDocument $dom, DOMXPath $xpath, DOMElement $paragraph): void
    {
        $previous = $paragraph->previousSibling;
        while ($previous && ! ($previous instanceof DOMElement && $previous->localName === 'p')) {
            $previous = $previous->previousSibling;
        }
        if (! $previous instanceof DOMElement) {
            return;
        }

        $w = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
        $border = $xpath->query('w:pPr/w:pBdr', $paragraph)->item(0);
        $indent = $xpath->query('w:pPr/w:ind', $paragraph)->item(0);
        $spacing = $xpath->query('w:pPr/w:spacing', $paragraph)->item(0);
        if (! $border && ! $indent) {
            return;
        }

        $pPr = $xpath->query('w:pPr', $previous)->item(0);
        if (! $pPr instanceof DOMElement) {
            $pPr = $dom->createElementNS($w, 'w:pPr');
            $previous->insertBefore($pPr, $previous->firstChild);
        }
        // Where an element goes among a paragraph's properties: before the first that must follow it.
        $placeBefore = function (DOMElement $node, array $after) use ($pPr): void {
            foreach ($pPr->childNodes as $child) {
                if ($child instanceof DOMElement && in_array($child->localName, $after, true)) {
                    $pPr->insertBefore($node, $child);

                    return;
                }
            }
            $pPr->appendChild($node);
        };
        $tail = ['shd', 'tabs', 'suppressAutoHyphens', 'kinsoku', 'wordWrap', 'overflowPunct', 'topLinePunct', 'autoSpaceDE', 'autoSpaceDN', 'bidi', 'adjustRightInd', 'snapToGrid',
            'spacing', 'ind', 'contextualSpacing', 'mirrorIndents', 'suppressOverlap', 'jc', 'textDirection', 'textAlignment', 'textboxTightWrap', 'outlineLvl', 'divId', 'cnfStyle', 'rPr', 'sectPr', 'pPrChange'];

        if ($border instanceof DOMElement && $xpath->query('w:pBdr', $pPr)->length === 0) {
            $placeBefore($border->cloneNode(true), $tail);
            // The space under the line, as the removed line had it.
            $after = $spacing instanceof DOMElement ? $spacing->getAttributeNS($w, 'after') : '';
            if ($after !== '') {
                $own = $xpath->query('w:spacing', $pPr)->item(0);
                if (! $own instanceof DOMElement) {
                    $own = $dom->createElementNS($w, 'w:spacing');
                    $placeBefore($own, array_slice($tail, array_search('ind', $tail, true)));
                }
                $own->setAttributeNS($w, 'w:after', $after);
            }
        }

        // Continuation lines start where the removed lines did; the first line keeps its label at the margin.
        $left = $indent instanceof DOMElement ? ($indent->getAttributeNS($w, 'left') ?: $indent->getAttributeNS($w, 'start')) : '';
        if ($left !== '' && (int) $left > 0 && $xpath->query('w:ind', $pPr)->length === 0) {
            $ind = $dom->createElementNS($w, 'w:ind');
            $ind->setAttributeNS($w, 'w:left', $left);
            $ind->setAttributeNS($w, 'w:hanging', $left);
            $placeBefore($ind, array_slice($tail, array_search('contextualSpacing', $tail, true)));
        }
    }

    /**
     * A line that is only a [hint]: the subject, the author's name, or a field
     * named after the hint. Returns the placeholder, null to leave the line as
     * it is (a signature or stamp hint), or false when the line was taken out.
     */
    private function markHint(DOMDocument $dom, DOMXPath $xpath, DOMElement $paragraph, string $text): string|false|null
    {
        $hint = $this->normalHint($text);

        if (in_array($hint, $this->clearedHints, true)) {
            if ($xpath->query('w:p', $paragraph->parentNode)->length > 1) {
                $this->keepLookOnPrevious($dom, $xpath, $paragraph);
                $paragraph->parentNode->removeChild($paragraph);

                return false;
            }
        }

        $inner = trim(preg_replace('/[\[\]]+/u', ' ', $hint));

        // "[SIGNATURE]", "[Sign here]": the signatory's signature goes on this line (once).
        if (preg_match('/^(your\s+)?(signature|sign\s+here|signature\s+here)$/i', $inner) && ! $this->signatureMarked) {
            foreach (iterator_to_array($xpath->query('.//w:t', $paragraph)) as $i => $t) {
                $t->textContent = $i === 0 ? '${signature}' : '';
            }
            $this->signatureMarked = true;

            return 'signature';
        }

        if (preg_match('/\b(sign|signed|signature|stamp|seal)\b/i', $inner)) {
            return null;
        }

        // "[BODY]", "[Main text]", "[Text of the memo]": the space the author writes the text in.
        if ($this->isBodyHint($inner)) {
            foreach (iterator_to_array($xpath->query('.//w:t', $paragraph)) as $i => $t) {
                $t->textContent = $i === 0 ? '${text_body}' : '';
            }
            $this->labels['text_body'] ??= 'Body';

            return 'text_body';
        }

        $name = $this->hintName($inner);
        if ($name === null) {
            return null;
        }

        // The hint's text goes; the line keeps its look and holds the placeholder.
        $first = true;
        foreach (iterator_to_array($xpath->query('.//w:t', $paragraph)) as $t) {
            $t->textContent = $first ? '${'.$name.'}' : '';
            $first = false;
        }

        return $name;
    }

    /** True when the next thing after this (empty) line, past other empty lines, is a table. */
    private function tableFollows(DOMXPath $xpath, DOMElement $line): bool
    {
        for ($next = $line->nextSibling; $next !== null; $next = $next->nextSibling) {
            if (! $next instanceof DOMElement) {
                continue;
            }
            if ($next->localName === 'p' && trim($this->paragraphText($xpath, $next)) === '' && ! $this->hasPicture($xpath, $next)) {
                continue;
            }

            return $next->localName === 'tbl';
        }

        return false;
    }

    /** "[BODY]", "[Main text]", "[Text of the memo]" (the words inside the brackets). */
    private function isBodyHint(string $inner): bool
    {
        return preg_match('/^(the\s+)?(body|main\s+text|text|text\s+of\s+the\s+\w+|body\s+of\s+the\s+\w+|write\s+(the\s+)?(body|text)(\s+here)?)$/i', trim($inner)) === 1;
    }

    /**
     * The field a hint stands for (the words inside the brackets): the subject,
     * the author's name, a standard field, or one named after the hint. Null
     * when the hint is not something to type (too long to be a name).
     */
    private function hintName(string $inner): ?string
    {
        if (preg_match('/\bsubject\b/i', $inner)) {
            return 'subject';
        }
        if (preg_match('/\bfull name\b|\bname in capitals\b|\byour name\b/i', $inner)) {
            return preg_match('/\bcapital/i', $inner) ? 'author_upper' : 'author';
        }

        // "Directorate / Department / Section" -> directorate_department_section;
        // "Author initials, e.g. ABC/xy" -> author_initials.
        $short = trim(preg_split('/,|\s+e\.g\.?|\s+eg\b|\s+-\s+/iu', $inner)[0]);
        if ($short === '' || str_word_count($short) > 6) {
            return null;
        }
        $normal = mb_strtolower(preg_replace('/\s+/u', ' ', $short));
        $name = self::LABELS[$normal] ?? trim(preg_replace('/[^a-z0-9]+/', '_', $normal), '_');
        if ($name === '') {
            return null;
        }
        $name = ctype_alpha($name[0]) ? $name : 'field_'.$name;
        if (! isset(self::LABELS[$normal])) {
            $this->labels[$name] ??= self::displayLabel($short);
        }

        return $name;
    }

    /**
     * Hints inside a line of text ("Dear [Mr./Ms. Surname]", "[Full Name] <tab>
     * [Private and Confidential]"): each becomes its own field, and the text
     * around it stays. A hint split over several runs of text is left alone.
     *
     * @return list<string> placeholders added, in order
     */
    private function markInlineHints(DOMXPath $xpath, DOMElement $paragraph): array
    {
        $found = [];
        foreach ($xpath->query('.//w:t[not(ancestor::w:txbxContent)]', $paragraph) as $t) {
            $t->textContent = preg_replace_callback('/\[([^\[\]]+)\]/u', function (array $m) use (&$found) {
                if (preg_match('/\b(sign|signed|signature|stamp|seal)\b/i', $m[1])) {
                    return $m[0]; // signed by hand
                }
                $name = $this->hintName($m[1]);
                if ($name === null) {
                    return $m[0];
                }
                $found[] = $name;

                return '${'.$name.'}';
            }, $t->textContent);
        }

        return $found;
    }

    /**
     * The lines around the signature are the signatory's: "Name:" and "Job
     * title:" under "Prepared by", or the job title under the name after
     * "Yours sincerely". PDTS fills them itself (${author},
     * ${author_job_title}) instead of asking the author.
     */
    private function claimSignerLines(DOMXPath $xpath, DOMElement $body): void
    {
        $spot = $xpath->query('w:p[.//w:t[contains(., "${signature}")]]', $body)->item(0);
        if (! $spot instanceof DOMElement) {
            return;
        }

        $swap = [
            '${name}' => '${author}', '${full_name}' => '${author}', '${name_in_full}' => '${author}',
            '${job_title}' => '${author_job_title}', '${designation}' => '${author_job_title}', '${position}' => '${author_job_title}',
        ];
        foreach (['previousSibling', 'nextSibling'] as $direction) {
            $lines = 0;
            for ($node = $spot->$direction; $node !== null && $lines < 4; $node = $node->$direction) {
                if (! $node instanceof DOMElement) {
                    continue;
                }
                if ($node->localName !== 'p' || $this->isHeading($xpath, $node)) {
                    break;
                }
                if (trim($this->paragraphText($xpath, $node)) === '') {
                    continue;
                }
                $lines++;
                foreach ($xpath->query('.//w:t', $node) as $t) {
                    $t->textContent = strtr($t->textContent, $swap);
                }
            }
        }
    }

    /**
     * Where the author's signature goes, marked ${signature}, in this order:
     * a "Signature:" line (the first, e.g. under "Prepared by"); the Signature
     * column of a table, in its first row; the line above the author's name.
     */
    private function markSignature(DOMDocument $dom, DOMXPath $xpath, DOMElement $body): bool
    {
        // 1. "Signature: ________"
        foreach ($xpath->query('.//w:p', $body) as $paragraph) {
            $text = $this->paragraphText($xpath, $paragraph);
            if (! preg_match('/(?:^|\t|\s{2})(?:signature|signed|signed by|sign here)\s*:(.*)$/iu', $text, $m, PREG_OFFSET_CAPTURE)
                || ! $this->isBlank($m[1][0])) {
                continue;
            }

            // Keep the label, drop the line after it.
            $keep = mb_strlen(substr($text, 0, $m[1][1]));
            $seen = 0;
            foreach ($xpath->query('.//w:t|.//w:tab', $paragraph) as $node) {
                if ($node->localName === 'tab') {
                    $seen++;

                    continue;
                }
                $chars = mb_str_split($node->textContent);
                $node->textContent = implode('', array_slice($chars, 0, max(0, $keep - $seen)));
                $seen += count($chars);
            }
            $runs = $xpath->query('.//w:r', $paragraph);
            $this->insertRunAfter($dom, $runs->item($runs->length - 1), ' ${signature}');

            return true;
        }

        // 2. A table with a "Signature" column: the cell below the heading.
        foreach ($xpath->query('.//w:tbl', $body) as $table) {
            $rows = $xpath->query('w:tr', $table);
            for ($r = 0; $r + 1 < $rows->length; $r++) {
                $cells = $xpath->query('w:tc', $rows->item($r));
                foreach ($cells as $index => $cell) {
                    if (! preg_match('/^\s*signature\s*$/iu', $this->paragraphText($xpath, $cell))) {
                        continue;
                    }
                    $target = $xpath->query('w:tc', $rows->item($r + 1))->item($index);
                    if ($target instanceof DOMElement && trim($this->paragraphText($xpath, $target)) === '') {
                        $paragraph = $xpath->query('w:p', $target)->item(0);
                        if ($paragraph instanceof DOMElement) {
                            $this->putPlaceholder($dom, $paragraph, 'signature');
                            $this->fillSignatoryRow($dom, $xpath, $cells, $xpath->query('w:tc', $rows->item($r + 1)));

                            return true;
                        }
                    }
                }
            }
        }

        // 3. Above the author's name (memos: the signature sits over the name). The
        //    last name: in a letter the first one is the addressee's.
        $named = null;
        foreach ($xpath->query('w:p', $body) as $paragraph) {
            $text = $this->paragraphText($xpath, $paragraph);
            if (str_contains($text, '${author_upper}') || str_contains($text, '${author}')) {
                $named = $paragraph;
            }
        }
        foreach ($named ? [$named] : [] as $paragraph) {

            $before = $paragraph->previousSibling;
            while ($before !== null && ! $before instanceof DOMElement) {
                $before = $before->previousSibling;
            }
            if ($before instanceof DOMElement && $before->localName === 'p'
                && trim($this->paragraphText($xpath, $before)) === '' && ! $this->hasPicture($xpath, $before)) {
                $this->putPlaceholder($dom, $before, 'signature');
            } else {
                $new = $dom->createElementNS(self::W, 'w:p');
                $properties = $xpath->query('w:pPr', $paragraph)->item(0);
                if ($properties instanceof DOMElement) {
                    $new->appendChild($properties->cloneNode(true));
                }
                $paragraph->parentNode->insertBefore($new, $paragraph);
                $this->putPlaceholder($dom, $new, 'signature');
            }

            return true;
        }

        return false;
    }

    /**
     * Adds ${name} to an empty line, in the line's own font; a line with no
     * font of its own takes the font of the text above it, so what is typed
     * matches the document.
     */
    private function putPlaceholder(DOMDocument $dom, DOMElement $paragraph, string $name): void
    {
        $run = $dom->createElementNS(self::W, 'w:r');
        $xpath = $this->xpath($dom);

        $font = $xpath->query('w:pPr/w:rPr', $paragraph)->item(0);
        if (! $font instanceof DOMElement) {
            for ($above = $paragraph->previousSibling; $above !== null; $above = $above->previousSibling) {
                if (! $above instanceof DOMElement || $above->localName !== 'p') {
                    continue;
                }
                $font = $xpath->query('w:r[w:t[normalize-space()]]/w:rPr', $above)->item(0);
                if ($font instanceof DOMElement || trim($this->paragraphText($xpath, $above)) !== '') {
                    break;
                }
            }
        }
        if ($font instanceof DOMElement) {
            $copy = $font->cloneNode(true);
            // The text's font and size, not the bold or capitals of a heading.
            foreach (['b', 'bCs', 'caps', 'u'] as $strip) {
                foreach (iterator_to_array($copy->getElementsByTagNameNS(self::W, $strip)) as $node) {
                    $copy->removeChild($node);
                }
            }
            $run->appendChild($copy);
        }
        $t = $dom->createElementNS(self::W, 'w:t');
        $t->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
        $t->appendChild($dom->createTextNode('${'.$name.'}'));
        $run->appendChild($t);
        $paragraph->appendChild($run);
    }

    /** A line's text, tabs included; text in floating boxes is left out. */
    private function paragraphText(DOMXPath $xpath, DOMElement $node): string
    {
        $text = '';
        foreach ($xpath->query('.//w:t[not(ancestor::w:txbxContent)]|.//w:tab[not(ancestor::w:txbxContent)]', $node) as $part) {
            $text .= $part->localName === 'tab' ? "\t" : $part->textContent;
        }

        return $text;
    }

    private function hasPicture(DOMXPath $xpath, DOMElement $node): bool
    {
        return $xpath->query('.//w:drawing|.//w:pict|.//w:object', $node)->length > 0;
    }

    /** Only one or more [bracketed hints], e.g. "[SUBJECT IN CAPITAL LETTERS]". */
    private function isHintOnly(string $text): bool
    {
        return preg_match('/^\s*(\[[^\[\]]+\]\s*)+$/u', $text) === 1;
    }

    /** Just the [hints] in a piece of text, spaced evenly, for comparing. */
    private function normalHint(string $text): string
    {
        preg_match_all('/\[[^\[\]]*\]/u', $text, $matches);

        return trim(preg_replace('/\s+/u', ' ', implode(' ', $matches[0])));
    }

    /** A section heading: a Heading style, an outline level, or "3. ACTIVITIES UNDERTAKEN". */
    private function isHeading(DOMXPath $xpath, DOMElement $paragraph): bool
    {
        $style = (string) $xpath->query('w:pPr/w:pStyle/@w:val', $paragraph)->item(0)?->nodeValue;
        if (preg_match('/^(heading|berschrift|titre)/i', $style) || $xpath->query('w:pPr/w:outlineLvl', $paragraph)->length > 0) {
            return true;
        }

        $text = trim($this->paragraphText($xpath, $paragraph));

        // "1. PURPOSE", "2.1 SCOPE", "C. PURPOSE AND JUSTIFICATION".
        return preg_match('/^(\d+(\.\d+)*\.?|[A-Z]\.)\s+\S/u', $text) === 1 && $text === mb_strtoupper($text) && mb_strlen($text) <= 80;
    }

    /** "3. ACTIVITIES UNDERTAKEN" -> "ACTIVITIES UNDERTAKEN" */
    private function headingTitle(string $text): string
    {
        return trim(preg_replace('/^\s*(\d+(\.\d+)*\.?|[A-Z]\.)\s+/u', '', trim($text)));
    }

    /**
     * A label as the form shows it: the document's own words, tidied.
     * "APPENDIX A" -> "Appendix A", "your  ref." -> "Your ref", "Prepared for" stays.
     */
    public static function displayLabel(string $text): string
    {
        $text = rtrim(preg_replace('/\s+/u', ' ', trim($text)), '.');

        // Shouted labels (all capitals) read better in sentence case.
        if ($text === mb_strtoupper($text)) {
            $text = mb_strtolower($text);
        }

        $words = explode(' ', $text);
        foreach ($words as $i => $word) {
            // Single letters are usually item names or units: Appendix A, Section B, Total (K).
            $words[$i] = mb_strlen(trim($word, '()[].,:')) === 1 ? mb_strtoupper($word) : $word;
        }

        return mb_strtoupper(mb_substr(implode(' ', $words), 0, 1)).mb_substr(implode(' ', $words), 1);
    }

    /**
     * The placeholder a label becomes: the usual heading lines keep their
     * names (TO -> to, OUR REF -> office_reference); any other label becomes a
     * field named after it ("Your Ref" -> your_ref). Lines that are signed or
     * stamped by hand are not fields (null).
     */
    public function fieldName(string $label): ?string
    {
        $normal = mb_strtolower(preg_replace('/\s+/u', ' ', trim($label)));

        if (isset(self::LABELS[$normal]) || isset(self::LABELS[rtrim($normal, '.')])) {
            return self::LABELS[$normal] ?? self::LABELS[rtrim($normal, '.')];
        }
        if (str_word_count($normal) > 5 || preg_match('/\b(sign|signed|signature|stamp|seal|initials?)\b/', $normal)) {
            return null;
        }

        $name = trim(preg_replace('/[^a-z0-9]+/', '_', $normal), '_');

        return $name === '' ? null : (ctype_alpha($name[0]) ? $name : 'field_'.$name);
    }

    /** Only spaces, tabs, lines, dots or a [bracketed hint] after the label. */
    private function isBlank(string $region): bool
    {
        $stripped = preg_replace('/\[[^\]]*\]/u', '', $region);

        return preg_match('/^[\s_.\-…·]*$/u', $stripped) === 1;
    }

    private function insertRunAfter(DOMDocument $dom, DOMElement $run, string $text): void
    {
        $new = $dom->createElementNS(self::W, 'w:r');

        // Same font as the label, but not bold.
        $properties = null;
        foreach ($run->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === 'rPr') {
                $properties = $child->cloneNode(true);
            }
        }
        if ($properties instanceof DOMElement) {
            foreach (['b', 'bCs'] as $bold) {
                foreach (iterator_to_array($properties->getElementsByTagNameNS(self::W, $bold)) as $node) {
                    $properties->removeChild($node);
                }
            }
            $new->appendChild($properties);
        }

        $t = $dom->createElementNS(self::W, 'w:t');
        $t->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
        $t->appendChild($dom->createTextNode($text));
        $new->appendChild($t);

        $run->parentNode->insertBefore($new, $run->nextSibling);
    }

    /**
     * Takes out any ${qr_code} typed in the file. A line that held nothing
     * but the QR code and "PDTS: ${reference}" (what PDTS itself used to add)
     * is removed whole; elsewhere only the QR code's run is removed.
     */
    private function removeTypedQr(): bool
    {
        $found = false;

        foreach ($this->partNames() as $name) {
            $xml = $this->read($name);
            if (! str_contains($this->plainText($xml), '${qr_code}')) {
                continue;
            }

            $dom = $this->load($xml);
            $xpath = $this->xpath($dom);
            foreach (iterator_to_array($xpath->query('//w:p')) as $paragraph) {
                $text = '';
                foreach ($xpath->query('.//w:t', $paragraph) as $t) {
                    $text .= $t->textContent;
                }
                if (! str_contains($text, '${qr_code}')) {
                    continue;
                }
                $found = true;

                $rest = trim(str_replace(['${qr_code}', '${reference}', 'PDTS:', 'PDTS tracking reference:'], '', $text));
                if ($rest === '' && $paragraph->parentNode !== null) {
                    // Keep at least one paragraph where Word requires one.
                    $siblings = $xpath->query('w:p', $paragraph->parentNode)->length;
                    if ($siblings > 1) {
                        $paragraph->parentNode->removeChild($paragraph);

                        continue;
                    }
                    foreach (iterator_to_array($xpath->query('w:r', $paragraph)) as $run) {
                        $paragraph->removeChild($run);
                    }

                    continue;
                }

                foreach (iterator_to_array($xpath->query('.//w:t', $paragraph)) as $t) {
                    if (str_contains($t->textContent, '${qr_code}')) {
                        $t->textContent = str_replace('${qr_code}', '', $t->textContent);
                    }
                }
            }

            $this->write($name, $dom->saveXML());
        }

        return $found;
    }

    /**
     * Pins the QR code and reference at the chosen spot: in every header for
     * "all", or in a first-page header for "first" (copied from the normal
     * header and footer so page 1 keeps its letterhead).
     *
     * @param  array{page: string, x: float, y: float, size: float}  $placement
     */
    private function placeQr(array $placement): void
    {
        $everyPage = ($placement['page'] ?? 'all') !== 'first';
        // Every page: the first page keeps a header of its own (a copy), so room made
        // for the QR code on the next pages (reserveQrSpace) leaves the first page as it is.
        // The usual header first (made when the file has none), then the first page's copy of it.
        $usual = $everyPage ? $this->headerFooterParts('header') : [];
        $first = $this->firstPageHeader();
        $parts = $everyPage ? array_values(array_unique([$first, ...$usual])) : [$first];

        $id = 9100;
        foreach ($parts as $name) {
            $dom = $this->load($this->read($name));
            $root = $dom->documentElement;
            foreach ([
                'wp' => 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing',
                'a' => 'http://schemas.openxmlformats.org/drawingml/2006/main',
                'pic' => 'http://schemas.openxmlformats.org/drawingml/2006/picture',
                'wps' => 'http://schemas.microsoft.com/office/word/2010/wordprocessingShape',
                'mc' => 'http://schemas.openxmlformats.org/markup-compatibility/2006',
                'r' => self::R,
            ] as $prefix => $uri) {
                if (! $root->hasAttribute('xmlns:'.$prefix)) {
                    $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:'.$prefix, $uri);
                }
            }

            // The box goes into the header's first paragraph, so the header
            // does not grow by a line.
            $paragraph = $this->xpath($dom)->query('/*/w:p')->item(0);
            if (! $paragraph instanceof DOMElement) {
                $paragraph = $dom->createElementNS(self::W, 'w:p');
                $root->appendChild($paragraph);
            }

            $fragment = $dom->createDocumentFragment();
            $fragment->appendXML($this->anchorRun($placement, $id++));
            $paragraph->appendChild($fragment);

            $this->write($name, $dom->saveXML());
        }

        if ($everyPage) {
            $this->reserveQrSpace(array_values(array_diff($parts, [$first])), $placement);
        }
    }

    /**
     * The QR code on every page: on the pages after the first (no letterhead
     * pushing the text down), the text starts below the QR code and its
     * reference instead of under them. Their header gets an empty line tall
     * enough, only when the QR code reaches below the page's top margin.
     *
     * @param  list<string>  $headers  the headers of the pages after the first
     */
    private function reserveQrSpace(array $headers, array $placement): void
    {
        $document = $this->load($this->read('word/document.xml'));
        $margins = $this->xpath($document)->query('//w:sectPr/w:pgMar')->item(0);
        $top = $margins instanceof DOMElement ? (int) $margins->getAttributeNS(self::W, 'top') : 1440;
        $headerDistance = $margins instanceof DOMElement && $margins->getAttributeNS(self::W, 'header') !== '' ? (int) $margins->getAttributeNS(self::W, 'header') : 720;

        // The QR code's foot, with its reference under it and a little room (twentieths of a point).
        $bottom = (int) ceil(((float) $placement['y'] + (float) $placement['size'] + 6) * 1440 / 25.4);
        if ($bottom <= $top || $bottom <= $headerDistance) {
            return; // the text already starts below it
        }

        foreach ($headers as $name) {
            $dom = $this->load($this->read($name));
            $spacer = $dom->createElementNS(self::W, 'w:p');
            $pPr = $spacer->appendChild($dom->createElementNS(self::W, 'w:pPr'));
            $spacing = $pPr->appendChild($dom->createElementNS(self::W, 'w:spacing'));
            $spacing->setAttributeNS(self::W, 'w:before', '0');
            $spacing->setAttributeNS(self::W, 'w:after', '0');
            $spacing->setAttributeNS(self::W, 'w:line', (string) ($bottom - $headerDistance));
            $spacing->setAttributeNS(self::W, 'w:lineRule', 'exact');
            $dom->documentElement->appendChild($spacer);
            $this->write($name, $dom->saveXML());
        }
    }

    /**
     * A floating text box pinned to the page, holding the QR code and the
     * reference under it. It does not push text aside (wrapNone).
     */
    private function anchorRun(array $placement, int $id): string
    {
        $size = max(10.0, (float) $placement['size']);
        $boxWidth = max($size, 26.0);
        $boxHeight = $size + self::REFERENCE_LINE_MM;
        // The box is centred on the QR code, which starts at x.
        $left = max(0.0, (float) $placement['x'] - ($boxWidth - $size) / 2);
        $top = max(0.0, (float) $placement['y']);

        $emu = fn (float $mm) => (int) round($mm * self::EMU_PER_MM);
        $ns = 'xmlns:w="'.self::W.'" xmlns:r="'.self::R.'"'
            .' xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"'
            .' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
            .' xmlns:wps="http://schemas.microsoft.com/office/word/2010/wordprocessingShape"'
            .' xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006"';
        $tight = '<w:spacing w:before="0" w:after="0" w:line="240" w:lineRule="auto"/><w:jc w:val="center"/>';

        return '<w:r '.$ns.'><mc:AlternateContent><mc:Choice Requires="wps"><w:drawing>'
            .'<wp:anchor distT="0" distB="0" distL="0" distR="0" simplePos="0" relativeHeight="251659264" behindDoc="0" locked="1" layoutInCell="1" allowOverlap="1">'
            .'<wp:simplePos x="0" y="0"/>'
            .'<wp:positionH relativeFrom="page"><wp:posOffset>'.$emu($left).'</wp:posOffset></wp:positionH>'
            .'<wp:positionV relativeFrom="page"><wp:posOffset>'.$emu($top).'</wp:posOffset></wp:positionV>'
            .'<wp:extent cx="'.$emu($boxWidth).'" cy="'.$emu($boxHeight).'"/><wp:effectExtent l="0" t="0" r="0" b="0"/>'
            .'<wp:wrapNone/><wp:docPr id="'.$id.'" name="PDTS QR code"/><wp:cNvGraphicFramePr/>'
            .'<a:graphic><a:graphicData uri="http://schemas.microsoft.com/office/word/2010/wordprocessingShape"><wps:wsp>'
            .'<wps:cNvSpPr txBox="1"/><wps:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="'.$emu($boxWidth).'" cy="'.$emu($boxHeight).'"/></a:xfrm>'
            .'<a:prstGeom prst="rect"><a:avLst/></a:prstGeom><a:noFill/><a:ln><a:noFill/></a:ln></wps:spPr>'
            .'<wps:txbx><w:txbxContent>'
            .'<w:p><w:pPr>'.$tight.'</w:pPr><w:r><w:t>${qr_code}</w:t></w:r></w:p>'
            .'<w:p><w:pPr>'.$tight.'</w:pPr><w:r><w:rPr><w:sz w:val="13"/></w:rPr><w:t>${reference}</w:t></w:r></w:p>'
            .'</w:txbxContent></wps:txbx>'
            .'<wps:bodyPr rot="0" vert="horz" wrap="square" lIns="0" tIns="0" rIns="0" bIns="0" anchor="t" anchorCtr="0"><a:spAutoFit/></wps:bodyPr>'
            // An (empty) fallback for programs that do not read Word's text boxes, such as
            // the in-browser preview; without one they stop reading the document.
            .'</wps:wsp></a:graphicData></a:graphic></wp:anchor></w:drawing></mc:Choice><mc:Fallback/></mc:AlternateContent></w:r>';
    }

    /**
     * The first page's own header. If the first section has none, one is made
     * (with a matching first-page footer) as a copy of the normal ones, and
     * "different first page" is switched on.
     */
    private function firstPageHeader(): string
    {
        $document = $this->load($this->read('word/document.xml'));
        $xpath = $this->xpath($document);
        $relsName = 'word/_rels/document.xml.rels';
        $rels = $this->read($relsName) ?: '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"></Relationships>';
        $targets = $this->relationshipTargets($rels);

        $section = $xpath->query('//w:sectPr')->item(0);
        if (! $section instanceof DOMElement) {
            $section = $document->createElementNS(self::W, 'w:sectPr');
            $xpath->query('//w:body')->item(0)->appendChild($section);
        }

        $header = null;
        foreach (['header', 'footer'] as $kind) {
            $first = $xpath->query('w:'.$kind.'Reference[@w:type="first"]', $section)->item(0);
            if ($first instanceof DOMElement && $xpath->query('w:titlePg', $section)->length > 0) {
                $target = $targets[$first->getAttributeNS(self::R, 'id')] ?? null;
                if ($target !== null && $this->exists($target)) {
                    if ($kind === 'header') {
                        $header = $target;
                    }

                    continue;
                }
            }
            if ($first instanceof DOMElement) {
                $section->removeChild($first);
            }

            $default = $xpath->query('w:'.$kind.'Reference[@w:type="default"]', $section)->item(0);
            $copyOf = $default instanceof DOMElement ? ($targets[$default->getAttributeNS(self::R, 'id')] ?? null) : null;

            $created = $this->createHeaderFooter($kind, $rels, $copyOf !== null && $this->exists($copyOf) ? $copyOf : null);
            $rels = $created['rels'];

            $reference = $document->createElementNS(self::W, 'w:'.$kind.'Reference');
            $reference->setAttributeNS(self::W, 'w:type', 'first');
            $reference->setAttributeNS(self::R, 'r:id', $created['id']);
            $section->insertBefore($reference, $section->firstChild);

            if ($kind === 'header') {
                $header = $created['name'];
            }
        }

        if ($xpath->query('w:titlePg', $section)->length === 0) {
            // titlePg comes after the page settings; Word accepts it at the end.
            $section->appendChild($document->createElementNS(self::W, 'w:titlePg'));
        }

        $this->write('word/document.xml', $document->saveXML());
        $this->write($relsName, $rels);

        return $header;
    }

    /** @return array<string, string> relationship id => part name */
    private function relationshipTargets(string $rels): array
    {
        $targets = [];
        preg_match_all('/<Relationship\b[^>]*>/', $rels, $all);
        foreach ($all[0] as $relationship) {
            if (preg_match('/Id="([^"]+)"/', $relationship, $id) && preg_match('/Target="([^"]+)"/', $relationship, $target)) {
                $targets[$id[1]] = 'word/'.ltrim($target[1], '/');
            }
        }

        return $targets;
    }

    /**
     * Every header or footer part used by the document's sections, creating
     * one for any section that has none, so the QR code is on every page.
     *
     * @return list<string>
     */
    private function headerFooterParts(string $kind): array
    {
        $document = $this->load($this->read('word/document.xml'));
        $xpath = $this->xpath($document);
        $relsName = 'word/_rels/document.xml.rels';
        $rels = $this->read($relsName) ?: '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"></Relationships>';

        $targets = $this->relationshipTargets($rels);

        $parts = [];
        $created = null;
        $sections = $xpath->query('//w:sectPr');
        if ($sections->length === 0) {
            $body = $xpath->query('//w:body')->item(0);
            $body->appendChild($document->createElementNS(self::W, 'w:sectPr'));
            $sections = $xpath->query('//w:sectPr');
        }

        foreach ($sections as $section) {
            $references = $xpath->query('w:'.$kind.'Reference', $section);
            if ($references->length === 0) {
                if ($created === null) {
                    $created = $this->createHeaderFooter($kind, $rels);
                    $rels = $created['rels'];
                }
                $reference = $document->createElementNS(self::W, 'w:'.$kind.'Reference');
                $reference->setAttributeNS(self::W, 'w:type', 'default');
                $reference->setAttributeNS(self::R, 'r:id', $created['id']);
                // Header and footer references come first in a section's properties.
                $section->insertBefore($reference, $section->firstChild);
                $parts[] = $created['name'];

                continue;
            }

            foreach ($references as $reference) {
                $id = $reference->getAttributeNS(self::R, 'id');
                if (isset($targets[$id]) && $this->exists($targets[$id])) {
                    $parts[] = $targets[$id];
                }
            }
        }

        $this->write('word/document.xml', $document->saveXML());
        $this->write($relsName, $rels);

        return array_values(array_unique($parts));
    }

    /**
     * @return array{name: string, id: string, rels: string}
     */
    private function createHeaderFooter(string $kind, string $rels, ?string $copyOf = null): array
    {
        $number = 1;
        while ($this->exists("word/{$kind}{$number}.xml")) {
            $number++;
        }
        $name = "word/{$kind}{$number}.xml";
        $id = 'rIdPdts'.ucfirst($kind).$number;
        $root = $kind === 'header' ? 'hdr' : 'ftr';

        if ($copyOf !== null) {
            // A copy of the normal header or footer, with its pictures.
            $this->write($name, $this->read($copyOf));
            $copyRels = $this->read('word/_rels/'.basename($copyOf).'.rels');
            if ($copyRels !== false) {
                $this->write('word/_rels/'.basename($name).'.rels', $copyRels);
            }
        } else {
            $this->write($name, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<w:'.$root.' xmlns:w="'.self::W.'" xmlns:r="'.self::R.'"><w:p/></w:'.$root.'>');
        }

        $rels = str_replace('</Relationships>',
            '<Relationship Id="'.$id.'" Type="'.($kind === 'header' ? self::HEADER_TYPE : self::FOOTER_TYPE).'" Target="'.$kind.$number.'.xml"/></Relationships>',
            $rels);

        $types = $this->read('[Content_Types].xml');
        $contentType = 'application/vnd.openxmlformats-officedocument.wordprocessingml.'.$kind.'+xml';
        if ($types !== false && ! str_contains($types, 'PartName="/'.$name.'"')) {
            $this->write('[Content_Types].xml', str_replace('</Types>',
                '<Override PartName="/'.$name.'" ContentType="'.$contentType.'"/></Types>', $types));
        }

        return ['name' => $name, 'id' => $id, 'rels' => $rels];
    }

    /** @return list<string> */
    private function partNames(): array
    {
        return array_values(array_filter(
            array_keys($this->files),
            fn (string $name) => preg_match('#^word/(document|header\d*|footer\d*)\.xml$#', $name) === 1,
        ));
    }

    /**
     * The Word file's parts, held in memory while preparing (see DocxPackage).
     *
     * @var array<string, string>
     */
    private array $files = [];

    private function read(string $name): string|false
    {
        return $this->files[$name] ?? false;
    }

    private function write(string $name, string $contents): void
    {
        $this->files[$name] = $contents;
    }

    private function exists(string $name): bool
    {
        return isset($this->files[$name]);
    }

    private function plainText(string $xml): string
    {
        return strip_tags(preg_replace('/<w:tab\b[^>]*\/>/', "\t", $xml));
    }

    private function load(string $xml): DOMDocument
    {
        $dom = new DOMDocument;
        $dom->preserveWhiteSpace = true;
        if (! @$dom->loadXML($xml, LIBXML_NONET)) {
            throw new RuntimeException('This file could not be read as a Word document.');
        }

        return $dom;
    }

    private function xpath(DOMDocument $dom): DOMXPath
    {
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', self::W);

        return $xpath;
    }
}
