<?php

use App\Models\PdtsDocumentTemplate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// Shared helpers for tests that build and read small Word (.docx) files.

/**
 * A minimal Word file. Placeholders can be split across runs, as Word does.
 */
function pdtsTestDocx(string $bodyRuns, string $footerRuns = '<w:r><w:t>${qr_code}</w:t></w:r>'): string
{
    $path = tempnam(sys_get_temp_dir(), 'pdts-test-docx');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    $w = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
        .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
        .'<Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
    $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8"?><w:document '.$w.'><w:body><w:p>'.$bodyRuns.'</w:p>'
        .'<w:sectPr><w:footerReference w:type="default" r:id="rIdF"/></w:sectPr></w:body></w:document>');
    $zip->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        .'<Relationship Id="rIdF" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer1.xml"/></Relationships>');
    $zip->addFromString('word/footer1.xml', '<?xml version="1.0" encoding="UTF-8"?><w:ftr '.$w.'><w:p>'.$footerRuns.'</w:p></w:ftr>');
    $zip->close();

    $bytes = file_get_contents($path);
    unlink($path);

    return $bytes;
}

function pdtsZipPart(string $bytes, string $name): string|false
{
    $path = tempnam(sys_get_temp_dir(), 'pdts-test-read');
    file_put_contents($path, $bytes);
    $zip = new ZipArchive;
    $zip->open($path);
    $part = $zip->getFromName($name);
    $zip->close();
    unlink($path);

    return $part;
}

/**
 * A ZESCO memorandum template as it is after upload: heading lines marked
 * with placeholders and the QR code and reference in the footer. Stored on the
 * (faked) private disk.
 */
function pdtsMemoTemplate(?int $documentTypeId = null, string $name = 'ZESCO Memorandum'): PdtsDocumentTemplate
{
    $line = fn (string $label, string $placeholder) => '<w:r><w:rPr><w:b/></w:rPr><w:t xml:space="preserve">'.$label.' </w:t></w:r><w:r><w:t>'.$placeholder.'</w:t></w:r></w:p><w:p>';
    $body = '<w:r><w:t>MEMORANDUM</w:t></w:r></w:p><w:p>'
        .$line('TO:', '${to}').$line('FROM:', '${from}').$line('CC:', '${cc}')
        .$line('REF:', '${office_reference}').$line('DATE:', '${date}')
        .'<w:r><w:rPr><w:b/></w:rPr><w:t>${subject}</w:t></w:r></w:p><w:p><w:r><w:t>${author}</w:t></w:r>';
    $footer = '<w:r><w:t>${qr_code}</w:t></w:r><w:r><w:t xml:space="preserve"> PDTS: </w:t></w:r><w:r><w:t>${reference}</w:t></w:r>';

    $path = 'pdts/templates/'.Str::random(20).'.docx';
    Storage::disk('private')->put($path, pdtsTestDocx($body, $footer));

    return PdtsDocumentTemplate::create([
        'name' => $name,
        'document_type_id' => $documentTypeId,
        'storage_path' => $path,
        'original_filename' => 'ZESCO Memorandum.docx',
        'placeholders' => ['to', 'from', 'cc', 'office_reference', 'date', 'subject', 'author', 'qr_code', 'reference'],
        'original_path' => $path,
        'kind' => 'docx',
        'qr_size_mm' => 20.0, // ${qr_code} is typed in the footer
        'configured_at' => now(),
        'is_active' => true,
    ]);
}
