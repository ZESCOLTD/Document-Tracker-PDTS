<?php

namespace App\Services\Sms;

/**
 * Zambian mobile numbers in international form (+260 then 9 digits starting
 * 7 or 9). Accepts 0977123456, 977123456, 260977123456 and +260 97 712 3456.
 */
final class PhoneNumber
{
    public static function normalize(?string $input): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $input);

        if (str_starts_with($digits, '260')) {
            $digits = substr($digits, 3);
        } elseif (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        return preg_match('/^[79]\d{8}$/', $digits) ? '+260'.$digits : null;
    }

    /** +260977123456 shown as +260 97 *** 3456. */
    public static function masked(string $number): string
    {
        return substr($number, 0, 4).' '.substr($number, 4, 2).' *** '.substr($number, -4);
    }
}
