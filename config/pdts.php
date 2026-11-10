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
];
