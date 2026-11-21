<?php

namespace App\Services\Pdts\Templates;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\ByteMatrix;
use BaconQrCode\Encoder\Encoder;

/**
 * Draws a QR code as a black-and-white PNG for Word documents. It writes the
 * PNG itself (zlib only), so it needs neither GD nor Imagick.
 */
class QrCodePng
{
    /**
     * @param  int  $scale  pixels per QR module
     * @param  int  $margin  quiet zone in modules (the standard asks for 4)
     */
    /**
     * The QR code's modules, row by row from the top: true = dark. Used to
     * draw the code directly onto PDF pages.
     *
     * @return list<list<bool>>
     */
    public function modules(string $text): array
    {
        $matrix = $this->encode($text);
        $size = $matrix->getWidth();
        $rows = [];
        for ($y = 0; $y < $size; $y++) {
            $row = [];
            for ($x = 0; $x < $size; $x++) {
                $row[] = $matrix->get($x, $y) === 1;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function encode(string $text): ByteMatrix
    {
        // Plain byte mode without an ECI prefix: some phone scanners misread the prefix.
        return Encoder::encode($text, ErrorCorrectionLevel::M(), Encoder::DEFAULT_BYTE_MODE_ENCODING, null, false)->getMatrix();
    }

    public function make(string $text, int $scale = 10, int $margin = 4): string
    {
        $matrix = $this->encode($text);
        $modules = $matrix->getWidth();
        $size = ($modules + 2 * $margin) * $scale;

        $raw = '';
        for ($y = 0; $y < $size; $y++) {
            $moduleY = intdiv($y, $scale) - $margin;
            $row = "\x00"; // filter type: none
            for ($x = 0; $x < $size; $x++) {
                $moduleX = intdiv($x, $scale) - $margin;
                $dark = $moduleX >= 0 && $moduleY >= 0 && $moduleX < $modules && $moduleY < $modules
                    && $matrix->get($moduleX, $moduleY) === 1;
                $row .= $dark ? "\x00" : "\xFF";
            }
            $raw .= $row;
        }

        // 8-bit greyscale PNG.
        $header = pack('NNCCCCC', $size, $size, 8, 0, 0, 0, 0);

        return "\x89PNG\r\n\x1a\n"
            .$this->chunk('IHDR', $header)
            .$this->chunk('IDAT', gzcompress($raw, 9))
            .$this->chunk('IEND', '');
    }

    private function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }
}
