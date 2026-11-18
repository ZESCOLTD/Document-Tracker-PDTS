<?php

return [
    'reference' => [
        'prefix' => env('PDTS_REFERENCE_PREFIX', 'OMTS'),
        'year_mode' => env('PDTS_REFERENCE_YEAR_MODE', 'calendar'),
        'financial_year_start_month' => (int) env('PDTS_FINANCIAL_YEAR_START_MONTH', 1),
        'padding' => (int) env('PDTS_REFERENCE_PADDING', 4),
    ],

    // A handover not acknowledged within this many days is shown as overdue (FR-24).
    'overdue_after_days' => (int) env('PDTS_OVERDUE_AFTER_DAYS', 3),

    // Whether scanning a QR code needs a login. While false, anyone with the
    // code sees a view-only page: the reference, where the document is going,
    // who has it and its journey. Confirming and every other action are done
    // in the app after logging in. Set PDTS_SCAN_REQUIRES_LOGIN=true to make
    // scanning ask for a login again.
    'scan_requires_login' => (bool) env('PDTS_SCAN_REQUIRES_LOGIN', false),
];
