<?php

namespace App\Services\Pdts\Templates;

/**
 * Small helpers for writing WordprocessingML by hand.
 */
final class WordXml
{
    public const NS = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" '
        .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
        .'xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" '
        .'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
        .'xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"';

    public const EMU_PER_INCH = 914400;

    public static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * Text as w:t elements, with line breaks for "\n".
     */
    public static function text(string $text): string
    {
        $lines = preg_split('/\R/u', $text) ?: [''];

        return implode('<w:br/>', array_map(
            fn (string $line) => '<w:t xml:space="preserve">'.self::escape($line).'</w:t>',
            $lines,
        ));
    }

    /**
     * @param  array{bold?: bool, italic?: bool, caps?: bool, size?: int, color?: string}  $style  size in half-points
     */
    public static function run(string $text, array $style = []): string
    {
        $props = '';
        if ($style['bold'] ?? false) {
            $props .= '<w:b/>';
        }
        if ($style['italic'] ?? false) {
            $props .= '<w:i/>';
        }
        if ($style['caps'] ?? false) {
            $props .= '<w:caps/>';
        }
        if (isset($style['color'])) {
            $props .= '<w:color w:val="'.$style['color'].'"/>';
        }
        if (isset($style['size'])) {
            $props .= '<w:sz w:val="'.$style['size'].'"/><w:szCs w:val="'.$style['size'].'"/>';
        }

        return '<w:r>'.($props !== '' ? '<w:rPr>'.$props.'</w:rPr>' : '').self::text($text).'</w:r>';
    }

    /**
     * @param  array{align?: string, after?: int, before?: int, borderBottom?: bool, keepNext?: bool}  $style  spacing in twentieths of a point
     */
    public static function paragraph(string $runs, array $style = []): string
    {
        $props = '';
        if ($style['keepNext'] ?? false) {
            $props .= '<w:keepNext/>';
        }
        if ($style['borderBottom'] ?? false) {
            $props .= '<w:pBdr><w:bottom w:val="single" w:sz="12" w:space="4" w:color="000000"/></w:pBdr>';
        }
        $props .= '<w:spacing w:before="'.($style['before'] ?? 0).'" w:after="'.($style['after'] ?? 120).'"/>';
        if (isset($style['align'])) {
            $props .= '<w:jc w:val="'.$style['align'].'"/>';
        }

        return '<w:p><w:pPr>'.$props.'</w:pPr>'.$runs.'</w:p>';
    }

    /**
     * An inline picture run.
     */
    public static function picture(string $relationshipId, int $id, string $name, float $widthInches, float $heightInches): string
    {
        $cx = (int) round($widthInches * self::EMU_PER_INCH);
        $cy = (int) round($heightInches * self::EMU_PER_INCH);
        $name = self::escape($name);

        return '<w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0">'
            .'<wp:extent cx="'.$cx.'" cy="'.$cy.'"/><wp:docPr id="'.$id.'" name="'.$name.'" descr="'.$name.'"/>'
            .'<wp:cNvGraphicFramePr><a:graphicFrameLocks noChangeAspect="1"/></wp:cNvGraphicFramePr>'
            .'<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            .'<pic:pic><pic:nvPicPr><pic:cNvPr id="'.$id.'" name="'.$name.'"/><pic:cNvPicPr/></pic:nvPicPr>'
            .'<pic:blipFill><a:blip r:embed="'.$relationshipId.'"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
            .'<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="'.$cx.'" cy="'.$cy.'"/></a:xfrm>'
            .'<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic>'
            .'</a:graphicData></a:graphic></wp:inline></w:drawing></w:r>';
    }

    /**
     * A borderless table. Each row is a list of cell contents (paragraph XML);
     * widths are in twentieths of a point.
     *
     * @param  list<list<string>>  $rows
     * @param  list<int>  $widths
     */
    public static function table(array $rows, array $widths): string
    {
        $grid = implode('', array_map(fn (int $w) => '<w:gridCol w:w="'.$w.'"/>', $widths));
        $xml = '<w:tbl><w:tblPr><w:tblW w:w="'.array_sum($widths).'" w:type="dxa"/><w:tblLayout w:type="fixed"/>'
            .'<w:tblBorders><w:top w:val="nil"/><w:left w:val="nil"/><w:bottom w:val="nil"/><w:right w:val="nil"/>'
            .'<w:insideH w:val="nil"/><w:insideV w:val="nil"/></w:tblBorders>'
            .'<w:tblCellMar><w:left w:w="0" w:type="dxa"/><w:right w:w="113" w:type="dxa"/></w:tblCellMar>'
            .'</w:tblPr><w:tblGrid>'.$grid.'</w:tblGrid>';

        foreach ($rows as $cells) {
            $xml .= '<w:tr>';
            foreach ($cells as $i => $content) {
                $xml .= '<w:tc><w:tcPr><w:tcW w:w="'.$widths[$i].'" w:type="dxa"/><w:vAlign w:val="center"/></w:tcPr>'
                    .($content !== '' ? $content : '<w:p/>').'</w:tc>';
            }
            $xml .= '</w:tr>';
        }

        return $xml.'</w:tbl>';
    }

    /**
     * A field such as PAGE or NUMPAGES.
     */
    public static function field(string $instruction, array $style = []): string
    {
        $size = isset($style['size']) ? '<w:rPr><w:sz w:val="'.$style['size'].'"/><w:szCs w:val="'.$style['size'].'"/></w:rPr>' : '';

        return '<w:r>'.$size.'<w:fldChar w:fldCharType="begin"/></w:r>'
            .'<w:r>'.$size.'<w:instrText xml:space="preserve"> '.$instruction.' </w:instrText></w:r>'
            .'<w:r>'.$size.'<w:fldChar w:fldCharType="separate"/></w:r>'
            .'<w:r>'.$size.'<w:t>1</w:t></w:r>'
            .'<w:r>'.$size.'<w:fldChar w:fldCharType="end"/></w:r>';
    }
}
