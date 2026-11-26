<?php

return [
    'reference' => [
        'prefix' => env('PDTS_REFERENCE_PREFIX', 'PDTS'),
        'year_mode' => env('PDTS_REFERENCE_YEAR_MODE', 'calendar'),
        'financial_year_start_month' => (int) env('PDTS_FINANCIAL_YEAR_START_MONTH', 1),
        'padding' => (int) env('PDTS_REFERENCE_PADDING', 4),
    ],

    // A handover not acknowledged within this many days is shown as overdue (FR-24).
    'overdue_after_days' => (int) env('PDTS_OVERDUE_AFTER_DAYS', 3),

    // Whether scanning a QR code needs a login. On by default: the person
    // scanning logs in first and comes straight back to the document. Set
    // PDTS_SCAN_REQUIRES_LOGIN=false to let anyone with the code see a
    // view-only page without logging in.
    'scan_requires_login' => (bool) env('PDTS_SCAN_REQUIRES_LOGIN', true),

    // Electronic signatures. The signed copy is a PDF made from the filled
    // Word template. "auto" uses LibreOffice when it is installed, and on a
    // Windows computer with Microsoft Word, Word itself; "libreoffice",
    // "word" or "none" choose one. PDTS_SOFFICE_PATH points to soffice when
    // it is not in the usual place.
    // Templates built in PDTS: the organisation name printed at the top.
    'builder' => [
        'organisation' => env('PDTS_BUILDER_ORGANISATION', 'ZESCO Limited'),
        // ZESCO's house font, for text the builder writes itself.
        'font' => env('PDTS_DOCUMENT_FONT', 'Tahoma'),
    ],

    'signatures' => [
        'pdf_converter' => env('PDTS_PDF_CONVERTER', 'auto'),
        'soffice_path' => env('PDTS_SOFFICE_PATH'),
        'convert_timeout' => (int) env('PDTS_PDF_CONVERT_TIMEOUT', 120),
        // Wrong signature PINs allowed before signing is paused, and for how long.
        'max_pin_attempts' => (int) env('PDTS_SIGNATURE_MAX_ATTEMPTS', 5),
        'lockout_minutes' => (int) env('PDTS_SIGNATURE_LOCKOUT_MINUTES', 15),
        // Grades (from PHRIS) of the officers who may have a secretary or
        // assistant prepare documents for them: Directors, Senior Managers and
        // Managers. Others need the documents.authorise_preparers permission.
        'officer_grades' => array_values(array_filter(array_map(
            fn ($grade) => strtoupper(trim($grade)),
            explode(',', (string) env('PDTS_OFFICER_GRADES', 'ML1,ML2,ML3,ML4')),
        ))),
        // Signing internal documents on paper (print, sign, scan back):
        // "allowed" offers it beside signing in PDTS; "fallback" only when
        // the signatory has no verified signature yet; "off" only for
        // templates without a signature spot. Templates without a signature
        // spot can always be signed on paper.
        'paper_signing' => env('PDTS_PAPER_SIGNING', 'allowed'),
        // Size of the signature on the page, in millimetres.
        'height_mm' => 14.0,
        'max_width_mm' => 50.0,
    ],
];
