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
    private const PART_PATTERN = '#^word/(document|header\d*|footer\d*)\.xml$#';

    /**
     * Names of the placeholders a template uses, so an upload can be checked.
     *
     * @return list<string>
     */
    public function placeholdersIn(string $docxPath): array
    {
        $found = [];

        $this->eachPart($docxPath, function (string $name, string $xml) use (&$found): ?string {
            preg_match_all('/\$\{([a-z_]+)\}/', $this->joinSplitPlaceholders($xml), $matches);
            $found = [...$found, ...$matches[1]];

            return null;
        });

        return array_values(array_unique($found));
    }

    /**
     * @param  array<string, string>  $values
     */
    public function fill(string $docxPath, array $values, string $qrPng): string
    {
        $work = tempnam(sys_get_temp_dir(), 'pdts-tpl');
        copy($docxPath, $work);

        $zip = new ZipArchive;
        if ($zip->open($work) !== true) {
            @unlink($work);
            throw new RuntimeException('The template is not a valid Word file.');
        }

        $pictureId = 9000;
        $usedQr = false;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (! preg_match(self::PART_PATTERN, $name)) {
                continue;
            }

            $xml = $this->joinSplitPlaceholders($zip->getFromIndex($i));

            foreach ($values as $key => $value) {
                $xml = str_replace('${'.$key.'}', $this->inlineText($value), $xml);
            }

            if (str_contains($xml, '${qr_code}')) {
                $usedQr = true;
                $relationshipId = 'rIdPdtsQr';
                $xml = $this->ensureDrawingNamespaces($xml);
                $xml = preg_replace_callback(
                    '#<w:r(?:\s[^>]*)?>(?:(?!</w:r>).)*?\$\{qr_code\}(?:(?!</w:r>).)*?</w:r>#s',
                    function () use ($relationshipId, &$pictureId) {
                        return WordXml::picture($relationshipId, $pictureId++, 'PDTS QR code', 0.78, 0.78);
                    },
                    $xml,
                );
                $this->addImageRelationship($zip, $name, $relationshipId);
            }

            $zip->addFromString($name, $xml);
        }

        if ($usedQr) {
            $zip->addFromString('word/media/pdts-qr.png', $qrPng);
            $this->ensurePngContentType($zip);
        }

        $zip->close();
        $bytes = file_get_contents($work);
        @unlink($work);

        return $bytes;
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

    private function addImageRelationship(ZipArchive $zip, string $partName, string $relationshipId): void
    {
        $relsName = 'word/_rels/'.basename($partName).'.rels';
        $rels = $zip->getFromName($relsName);

        if ($rels === false) {
            $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"></Relationships>';
        }

        if (! str_contains($rels, 'Id="'.$relationshipId.'"')) {
            $rels = str_replace(
                '</Relationships>',
                '<Relationship Id="'.$relationshipId.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/pdts-qr.png"/></Relationships>',
                $rels,
            );
            $zip->addFromString($relsName, $rels);
        }
    }

    private function ensurePngContentType(ZipArchive $zip): void
    {
        $types = $zip->getFromName('[Content_Types].xml');

        if ($types !== false && ! preg_match('/Extension="png"/i', $types)) {
            $types = str_replace('</Types>', '<Default Extension="png" ContentType="image/png"/></Types>', $types);
            $zip->addFromString('[Content_Types].xml', $types);
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
