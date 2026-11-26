<?php

// Small PDFs for tests, in the two shapes PDF writers produce.

/**
 * A one-page A4 PDF with a classic cross-reference table.
 */
function pdtsTestPdfClassic(): string
{
    $content = "0 0 1 rg 100 700 50 50 re f\n";
    $objects = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 595.28 841.89] >>',
        3 => '<< /Type /Page /Parent 2 0 R /Resources << >> /Contents 4 0 R >>',
        4 => '<< /Length '.strlen($content)." >>\nstream\n".$content."\nendstream",
    ];

    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [];
    foreach ($objects as $n => $body) {
        $offsets[$n] = strlen($pdf);
        $pdf .= "$n 0 obj\n$body\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 5\n0000000000 65535 f \n";
    foreach ($offsets as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }

    return $pdf."trailer\n<< /Size 5 /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
}

/**
 * A two-page PDF as current writers make it: pages inside a compressed object
 * stream, a compressed cross-reference stream with a PNG predictor, and the
 * second page rotated 90 degrees.
 */
function pdtsTestPdfModern(): string
{
    $content = "1 0 0 rg 50 50 100 100 re f\n";

    // Objects 2 (Pages), 3 and 4 (pages) live in object stream 6.
    $inStream = [
        2 => '<< /Type /Pages /Kids [3 0 R 4 0 R] /Count 2 /MediaBox [0 0 595.28 841.89] >>',
        3 => '<< /Type /Page /Parent 2 0 R /Resources << >> /Contents [5 0 R] >>',
        4 => '<< /Type /Page /Parent 2 0 R /Resources << >> /Contents 5 0 R /Rotate 90 >>',
    ];
    $header = '';
    $body = '';
    foreach ($inStream as $n => $source) {
        $header .= $n.' '.strlen($body).' ';
        $body .= $source."\n";
    }
    $objectStream = gzcompress($header.$body);

    $pdf = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [];
    $add = function (int $n, string $body) use (&$pdf, &$offsets) {
        $offsets[$n] = strlen($pdf);
        $pdf .= "$n 0 obj\n$body\nendobj\n";
    };
    $add(1, '<< /Type /Catalog /Pages 2 0 R >>');
    $add(5, '<< /Length '.strlen($content)." >>\nstream\n".$content."\nendstream");
    $add(6, '<< /Type /ObjStm /N 3 /First '.strlen($header).' /Filter /FlateDecode /Length '.strlen($objectStream)." >>\nstream\n".$objectStream."\nendstream");

    // Cross-reference stream (object 7): W [1 4 2], PNG "Up" predictor rows.
    $xrefOffset = strlen($pdf);
    $entries = [
        0 => [0, 0, 65535],
        1 => [1, $offsets[1], 0],
        2 => [2, 6, 0],
        3 => [2, 6, 1],
        4 => [2, 6, 2],
        5 => [1, $offsets[5], 0],
        6 => [1, $offsets[6], 0],
        7 => [1, $xrefOffset, 0],
    ];
    $raw = '';
    $previous = str_repeat("\0", 7);
    foreach ($entries as [$type, $field2, $field3]) {
        $row = chr($type).pack('N', $field2).pack('n', $field3);
        $up = '';
        for ($i = 0; $i < 7; $i++) {
            $up .= chr((ord($row[$i]) - ord($previous[$i])) & 0xFF);
        }
        $raw .= "\x02".$up;
        $previous = $row;
    }
    $data = gzcompress($raw);
    $pdf .= "7 0 obj\n<< /Type /XRef /Size 8 /Root 1 0 R /W [1 4 2] /Filter /FlateDecode /DecodeParms << /Predictor 12 /Columns 7 >> /Length ".strlen($data)." >>\nstream\n".$data."\nendstream\nendobj\n";

    return $pdf."startxref\n$xrefOffset\n%%EOF\n";
}

/**
 * A PDF as Microsoft Word saves it: an update at the end whose cross-reference
 * table is empty ("xref 0 0") and points back to the one before, written
 * straight after that one's "startxref".
 */
function pdtsTestPdfWord(): string
{
    $pdf = pdtsTestPdfClassic();
    preg_match('/startxref\n(\d+)\n%%EOF\n$/', $pdf, $m);
    $update = strlen($pdf);

    return $pdf."xref\n0 0\ntrailer\n<< /Size 5 /Root 1 0 R /Prev {$m[1]} >>\nstartxref\n$update\n%%EOF\n";
}
