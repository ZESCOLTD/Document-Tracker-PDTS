<?php

namespace App\Services\Ai;

/**
 * Gives a web request enough time to wait for the AI or OCR service. Only for
 * web requests: on the command line (artisan, queues, tests) PHP has no time
 * limit, and setting one there would stop a whole test run part way.
 */
class AiTimeLimit
{
    public static function allow(int $seconds): void
    {
        if (PHP_SAPI === 'cli' || ! function_exists('set_time_limit')) {
            return;
        }

        set_time_limit($seconds);
    }
}
