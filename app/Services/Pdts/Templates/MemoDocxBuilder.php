<?php

namespace App\Services\Pdts\Templates;

use RuntimeException;
use ZipArchive;

/**
 * Builds the built-in ZESCO memorandum as a Word file: letterhead, TO / FROM /
 * CC, REF / DATE, subject, a space for the body and signature, and a footer on
 * every page with the PDTS QR code and tracking reference.
 */
class MemoDocxBuilder
{
    private const PAGE_WIDTH = 11906;   // A4, twentieths of a point

    private const PAGE_HEIGHT = 16838;

    private const MARGIN = 1134;        // 2 cm

    private const CONTENT_WIDTH = self::PAGE_WIDTH - 2 * self::MARGIN;

    private const GREEN = '00873C';

    private const GREY = '595959';

    /**
     * @param  array<string, string>  $values  placeholder values (see PdtsDocumentTemplate::PLACEHOLDERS)
     */
    public function build(array $values, string $qrPng): string
    {
        $logo = file_get_contents(resource_path('images/pdts/zesco-memo-logo.png'));

        $files = [
            '[Content_Types].xml' => $this->contentTypes(),
            '_rels/.rels' => $this->packageRelationships(),
            'docProps/core.xml' => $this->coreProperties($values),
            'word/document.xml' => $this->document($values),
            'word/styles.xml' => $this->styles(),
            'word/settings.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<w:settings xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:updateFields w:val="true"/></w:settings>',
            'word/footer1.xml' => $this->footer($values),
            'word/_rels/document.xml.rels' => $this->documentRelationships(),
            'word/_rels/footer1.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rIdQr" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/pdts-qr.png"/>'
                .'</Relationships>',
            'word/media/zesco-logo.png' => $logo,
            'word/media/pdts-qr.png' => $qrPng,
        ];

        return $this->zip($files);
    }

    /**
     * @param  array<string, string>  $files
     */
    private function zip(array $files): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pdts-memo');
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the Word file.');
        }

        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        $bytes = file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    private function document(array $v): string
    {
        $half = intdiv(self::CONTENT_WIDTH, 2);
        $label = fn (string $label, string $value) => WordXml::paragraph(
            WordXml::run($label.' ', ['bold' => true]).WordXml::run($value),
            ['after' => 60],
        );

        $body = WordXml::paragraph(WordXml::picture('rIdLogo', 1, 'ZESCO logo', 1.05, 0.86), ['align' => 'center', 'after' => 0])
            .WordXml::paragraph(WordXml::run('Powering The Nation', ['bold' => true, 'italic' => true, 'color' => self::GREEN, 'size' => 22]), ['align' => 'center', 'after' => 120])
            .WordXml::paragraph(WordXml::run('MEMORANDUM', ['bold' => true, 'size' => 36]), ['align' => 'center', 'after' => 240])
            .WordXml::table([
                [$label('TO:', $v['to']), $label('FROM:', $v['from'])],
                [$label('CC:', $v['cc'] !== '' ? $v['cc'] : '—'), ''],
            ], [$half, self::CONTENT_WIDTH - $half])
            .WordXml::paragraph('', ['borderBottom' => true, 'after' => 160])
            .WordXml::table([
                [$label('REF:', $v['office_reference'] !== '' ? $v['office_reference'] : '—'), $label('DATE:', $v['date'])],
            ], [$half, self::CONTENT_WIDTH - $half])
            .WordXml::paragraph(WordXml::run($v['subject'], ['bold' => true, 'caps' => true, 'size' => 24]), ['align' => 'both', 'before' => 160, 'after' => 200])
            .WordXml::paragraph(WordXml::run('Reference is made to the above subject matter.'), ['align' => 'both'])
            .WordXml::paragraph(WordXml::run('[Type the body of the memorandum here.]', ['italic' => true, 'color' => self::GREY]), ['align' => 'both'])
            .WordXml::paragraph('', ['after' => 0])
            .WordXml::paragraph('', ['after' => 0])
            .WordXml::paragraph('', ['after' => 0])
            .WordXml::paragraph(WordXml::run($v['author'], ['bold' => true, 'caps' => true]), ['after' => 0])
            .WordXml::paragraph(WordXml::run($v['office'], ['color' => self::GREY, 'size' => 20]), ['after' => 0]);

        $section = '<w:sectPr><w:footerReference w:type="default" r:id="rIdFooter"/>'
            .'<w:pgSz w:w="'.self::PAGE_WIDTH.'" w:h="'.self::PAGE_HEIGHT.'"/>'
            .'<w:pgMar w:top="850" w:right="'.self::MARGIN.'" w:bottom="1134" w:left="'.self::MARGIN.'" w:header="567" w:footer="397" w:gutter="0"/>'
            .'</w:sectPr>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document '.WordXml::NS.'><w:body>'.$body.$section.'</w:body></w:document>';
    }

    private function footer(array $v): string
    {
        $small = ['size' => 16, 'color' => self::GREY];

        $text = WordXml::paragraph(WordXml::run('PDTS tracking reference: ', ['bold' => true, 'size' => 18]).WordXml::run($v['reference'], ['bold' => true, 'size' => 18, 'color' => self::GREEN]), ['after' => 20])
            .WordXml::paragraph(WordXml::run('Scan the code to track, receive or action this document in PDTS.', $small), ['after' => 20])
            .WordXml::paragraph(WordXml::run('ZESCO Limited · Stand No. 6949, Great East Road · P.O. Box 33304, Lusaka, Zambia · www.zesco.co.zm', $small), ['after' => 20])
            .WordXml::paragraph(WordXml::run('Page ', $small).WordXml::field('PAGE', $small).WordXml::run(' of ', $small).WordXml::field('NUMPAGES', $small), ['after' => 0]);

        $qr = WordXml::paragraph(WordXml::picture('rIdQr', 101, 'PDTS QR code', 0.78, 0.78), ['after' => 0]);

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:ftr '.WordXml::NS.'>'
            .WordXml::paragraph('', ['borderBottom' => true, 'after' => 80])
            .WordXml::table([[$qr, $text]], [1300, self::CONTENT_WIDTH - 1300])
            .'<w:p/></w:ftr>';
    }

    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:cs="Calibri" w:eastAsia="Calibri"/>'
            .'<w:sz w:val="23"/><w:szCs w:val="23"/><w:lang w:val="en-GB"/></w:rPr></w:rPrDefault>'
            .'<w:pPrDefault><w:pPr><w:spacing w:after="120" w:line="264" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults>'
            .'<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:qFormat/></w:style>'
            .'<w:style w:type="table" w:default="1" w:styleId="TableNormal"><w:name w:val="Normal Table"/>'
            .'<w:tblPr><w:tblInd w:w="0" w:type="dxa"/><w:tblCellMar><w:top w:w="0" w:type="dxa"/><w:left w:w="108" w:type="dxa"/>'
            .'<w:bottom w:w="0" w:type="dxa"/><w:right w:w="108" w:type="dxa"/></w:tblCellMar></w:tblPr></w:style>'
            .'</w:styles>';
    }

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Default Extension="png" ContentType="image/png"/>'
            .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            .'<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
            .'<Override PartName="/word/settings.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml"/>'
            .'<Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/>'
            .'<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            .'</Types>';
    }

    private function packageRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            .'</Relationships>';
    }

    private function documentRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'<Relationship Id="rIdSettings" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/settings" Target="settings.xml"/>'
            .'<Relationship Id="rIdFooter" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer1.xml"/>'
            .'<Relationship Id="rIdLogo" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/zesco-logo.png"/>'
            .'</Relationships>';
    }

    private function coreProperties(array $v): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
            .'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" '
            .'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            .'<dc:title>'.WordXml::escape($v['subject']).'</dc:title>'
            .'<dc:creator>'.WordXml::escape($v['author']).'</dc:creator>'
            .'<dc:identifier>'.WordXml::escape($v['reference']).'</dc:identifier>'
            .'</cp:coreProperties>';
    }
}
