<?php

namespace App\Services\Pdts;

/**
 * Job titles come from the staff records in capitals ("PRINCIPAL ENGINEER - AI
 * SOLUTIONS & DIGITAL PRODUCTS"). On a document they read better, and take less
 * room, in ordinary capitals: "Principal Engineer - AI Solutions & Digital
 * Products". Short forms stay in capitals; small joining words stay small.
 * Text that is not all capitals (typed by someone) is left as it is.
 */
class PositionText
{
    /** Kept in capitals. */
    public const SHORT_FORMS = [
        'AI', 'ICT', 'IT', 'HR', 'HRM', 'ZESCO', 'IDC', 'SHEQ', 'SHE', 'QA', 'QC', 'PR', 'MD', 'CEO', 'CFO', 'COO', 'CTO', 'CIO',
        'GM', 'DGM', 'AGM', 'PA', 'PS', 'ERP', 'SAP', 'GIS', 'SCADA', 'MV', 'HV', 'LV', 'KV', 'EHV', 'UPS', 'CCTV', 'PABX', 'PMO',
        'R&D', 'M&E', 'ML', 'DB', 'DBA', 'UI', 'UX', 'API', 'LAN', 'WAN', 'VPN', 'II', 'III', 'IV', 'VI', 'VII', 'VIII', 'IX', 'X',
    ];

    /** Kept small, unless they start the title. */
    public const SMALL_WORDS = ['and', 'of', 'for', 'the', 'in', 'on', 'to', 'at', 'by', 'a', 'an', 'or', 'with'];

    public static function tidy(?string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $text) ?? '');
        // Only all-capital text is changed: what someone typed is theirs.
        if ($text === '' || mb_strtoupper($text) !== $text || ! preg_match('/\p{Lu}{2}/u', $text)) {
            return $text;
        }

        $first = true;

        return preg_replace_callback('/[\p{L}\p{N}&\'’.]+/u', function (array $m) use (&$first) {
            $word = $m[0];
            $plain = rtrim($word, '.');
            $isFirst = $first;
            $first = false;

            if (in_array($plain, self::SHORT_FORMS, true) || preg_match('/\d/u', $plain)) {
                return $word;
            }
            $lower = mb_strtolower($word);
            if (! $isFirst && in_array(rtrim($lower, '.'), self::SMALL_WORDS, true)) {
                return $lower;
            }

            return mb_strtoupper(mb_substr($lower, 0, 1)).mb_substr($lower, 1);
        }, $text) ?? $text;
    }
}
