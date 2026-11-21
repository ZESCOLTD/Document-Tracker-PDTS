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
    public function prepare(string $docxPath, array $placement): array
    {
        $this->files = DocxPackage::read($docxPath);
        $this->labels = [];
        $fields = [];

        // 1. Heading labels in the body, headers and footers.
        foreach ($this->partNames() as $name) {
            $dom = $this->load($this->read($name));
            $found = $this->markLabels($dom);
            if ($found) {
                $fields = [...$fields, ...$found];
                $this->write($name, $dom->saveXML());
            }
        }

        // 2. The QR code goes where the administrator placed it. A ${qr_code}
        //    already typed in the file (for example a file PDTS prepared before)
        //    is taken out first, so there is only ever one.
        $hadQr = $this->removeTypedQr();
        $this->placeQr($placement);
        $qr = $hadQr ? 'replaced' : 'placed';

        $contents = DocxPackage::write($this->files);
        $this->files = [];

        return ['contents' => $contents, 'fields' => array_values(array_unique($fields)), 'qr' => $qr, 'labels' => $this->labels];
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

                // Clear the filler (lines, dots, [hints]); the template's tabs stay.
                $removals = [];
                for ($pos = $label['end']; $pos < $regionEnd; $pos++) {
                    if ($map[$pos]['node'] !== null) {
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
            // Single letters are usually item names: Appendix A, Section B.
            $words[$i] = mb_strlen($word) === 1 ? mb_strtoupper($word) : $word;
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
        $parts = ($placement['page'] ?? 'all') === 'first'
            ? [$this->firstPageHeader()]
            : $this->headerFooterParts('header');

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
            .'</wps:wsp></a:graphicData></a:graphic></wp:anchor></w:drawing></mc:Choice></mc:AlternateContent></w:r>';
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
