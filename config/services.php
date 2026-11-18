<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'pdts_ai' => [
        'url' => env('AI_SERVICE_URL', 'http://127.0.0.1:8001'),
        'token' => env('AI_SERVICE_TOKEN') ?: env('APP_KEY'),
        'timeout' => (int) env('AI_SERVICE_TIMEOUT', 180),
    ],

    // SMS. "log" writes messages to the log file instead of sending them, until
    // ICT provides an SMS gateway. "http" posts to that gateway's address.
    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),
        'sender' => env('SMS_SENDER', 'ZESCO PDTS'),
        'url' => env('SMS_API_URL'),
        'key' => env('SMS_API_KEY'),
        'timeout' => (int) env('SMS_TIMEOUT', 10),
    ],

];
