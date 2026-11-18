<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Cover sheet {{ $document->reference_number }}</title>
    <style>
        @page { size: A4; margin: 14mm; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #e9ecef; font-family: Calibri, "Segoe UI", Arial, sans-serif; color: #1f2d3d; }
        .toolbar { max-width: 210mm; margin: 0 auto; padding: 12px 0; display: flex; gap: 8px; align-items: center; }
        .toolbar a, .toolbar button { font: inherit; font-size: 14px; padding: 8px 14px; border-radius: 6px; border: 1px solid #093f42; background: #fff; color: #093f42; text-decoration: none; cursor: pointer; }
        .toolbar button.primary { background: #ca7533; border-color: #ca7533; color: #fff; font-weight: 600; }
        .toolbar .hint { margin-left: auto; font-size: 13px; color: #6c757d; }
        .sheet { width: 210mm; min-height: 297mm; margin: 0 auto 24px; background: #fff; padding: 16mm 18mm; display: flex; flex-direction: column; box-shadow: 0 2px 12px rgba(0, 0, 0, .12); }
        .head { display: flex; align-items: center; gap: 14px; border-bottom: 3px solid #ca7533; padding-bottom: 10px; }
        .head img { width: 26mm; }
        .head h1 { margin: 0; font-size: 22pt; letter-spacing: .02em; }
        .head .tag { margin-left: auto; text-align: right; font-weight: 700; color: #00873C; font-size: 11pt; }
        .code { text-align: center; margin: 14mm 0 8mm; }
        .code img { width: 70mm; height: 70mm; image-rendering: pixelated; }
        .ref { font-size: 30pt; font-weight: 700; letter-spacing: .03em; margin-top: 4mm; }
        .confidential { display: inline-block; margin-top: 4mm; padding: 2mm 6mm; border: 2px solid #c0392b; color: #c0392b; font-weight: 700; letter-spacing: .12em; font-size: 14pt; }
        table { width: 100%; border-collapse: collapse; font-size: 12.5pt; margin-top: 4mm; }
        th { text-align: left; width: 34%; color: #555; font-weight: 600; padding: 3mm 3mm 3mm 0; vertical-align: top; border-bottom: 1px solid #e3e6ea; }
        td { padding: 3mm 0; border-bottom: 1px solid #e3e6ea; }
        .foot { margin-top: auto; padding-top: 6mm; border-top: 1px solid #ccd2d8; font-size: 11pt; color: #444; }
        .foot strong { color: #093f42; }
        .meta { font-size: 9pt; color: #888; margin-top: 2mm; }
        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .sheet { width: auto; min-height: calc(297mm - 28mm); margin: 0; padding: 0; box-shadow: none; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <a href="{{ route('pdts.documents.show', $document) }}">&larr; Back to document</a>
        <button type="button" class="primary" onclick="window.print()">Print cover sheet</button>
        <span class="hint">Print on plain A4 and staple it to the front of the document.</span>
    </div>

    <main class="sheet">
        <header class="head">
            <img src="{{ $logoDataUri }}" alt="ZESCO">
            <h1>PDTS DOCUMENT COVER SHEET</h1>
            <div class="tag">FOR INTERNAL<br>USE ONLY</div>
        </header>

        <section class="code">
            <img src="{{ $qrDataUri }}" alt="QR code for {{ $document->reference_number }}">
            <div class="ref">{{ $document->reference_number }}</div>
            @if ($confidential)
                <div class="confidential">CONFIDENTIAL</div>
            @endif
        </section>

        @unless ($confidential)
            <table>
                <tr><th>Title</th><td>{{ $document->title }}</td></tr>
                @if ($document->subject && $document->subject !== $document->title)
                    <tr><th>Subject</th><td>{{ $document->subject }}</td></tr>
                @endif
                <tr><th>Type</th><td>{{ $document->documentType?->name ?? 'Not recorded' }}</td></tr>
                <tr><th>From</th><td>{{ collect([$document->originating_office, $document->originating_department, $document->originating_directorate])->filter()->unique()->implode(', ') ?: 'Not recorded' }}</td></tr>
                <tr><th>Registered</th><td>{{ $document->registered_at?->format('d M Y') }}{{ $document->registrar ? ' by '.$document->registrar->name : '' }}</td></tr>
            </table>
        @endunless

        <footer class="foot">
            <strong>Keep this sheet attached to the document.</strong>
            Scan the code with your phone camera, or enter the reference in PDTS, to see where the document is, confirm pickup or receipt, or act on it.
            <div class="meta">{{ $reprint ? 'Reprinted' : 'Printed' }} {{ now()->format('d M Y H:i') }}</div>
        </footer>
    </main>
</body>
</html>
