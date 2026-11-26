<div class="container-fluid py-4" style="max-width: 980px;">
    <style>
        .tg-rule { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 1rem; padding: 1rem 0; border-bottom: 1px solid #e6ecec; }
        @media (max-width: 760px) { .tg-rule { grid-template-columns: minmax(0, 1fr); } }
        .tg-rule h3 { font-size: 1rem; font-weight: 700; margin: 0 0 .25rem; }
        .tg-rule p { margin: 0 0 .375rem; color: #586a6b; font-size: .9375rem; }
        .tg-rule p:last-child { margin-bottom: 0; }
        .tg-word { font-family: {{ config('pdts.builder.font', 'Tahoma') }}, Verdana, sans-serif; font-size: .875rem; background: #fff; border: 1px solid #d9e2e1; border-radius: .375rem; padding: .75rem 1rem; white-space: pre-wrap; }
        .tg-word b { color: #093f42; }
        .tg-word .tg-hint { color: #8a6100; }
        .tg-tab { display: inline-block; min-width: 3em; border-bottom: 1px dotted #9aa; margin: 0 .25em; }
        .tg-line { display: block; border-bottom: 2px solid #111; margin: .25rem 0 .5rem; }
        .tg-ways { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: .75rem; margin: 1rem 0 .5rem; }
        @media (max-width: 760px) { .tg-ways { grid-template-columns: minmax(0, 1fr); } }
        .tg-way { border: 1px solid #d9e2e1; border-radius: .625rem; padding: .875rem 1rem; background: #fff; }
        .tg-way h2 { font-size: .9375rem; font-weight: 700; margin: 0 0 .25rem; }
        .tg-way p { font-size: .875rem; color: #586a6b; margin: 0 0 .5rem; }
        .tg-steps { margin: .5rem 0 0; padding-left: 1.25rem; font-size: .875rem; color: #44595a; }
    </style>

    <a href="{{ route('pdts.organization.document-templates') }}" class="small text-muted"><i class="fas fa-arrow-left mr-1"></i>Document Templates</a>
    <h1 class="h3 mt-1 mb-1">How to prepare a template for PDTS</h1>
    <p class="text-muted mb-0">There are two ways to give authors a template. Either way, PDTS fills it in, adds the QR code and reference, and the signatory signs it in PDTS.</p>

    <div class="tg-ways">
        <div class="tg-way">
            <h2><i class="fas fa-upload mr-1"></i>Upload ZESCO's own Word document</h2>
            <p>For documents with a fixed ZESCO layout, such as the memorandum. Use the document as it is; the rules below let PDTS fill it without any manual work. Anything PDTS misses can be marked on the preview after upload.</p>
        </div>
        <div class="tg-way">
            <h2><i class="fas fa-th-list mr-1"></i>Build it in PDTS</h2>
            <p>For forms and requests ZESCO has no Word file for. Drag lines, questions, headings, tables and signature blocks onto a page; it always carries the ZESCO logo and footer. No Word rules to follow.</p>
            <a href="{{ route('pdts.organization.document-templates.build') }}" class="btn btn-sm btn-outline-primary">Build a template</a>
        </div>
    </div>

    <h2 class="h5 mt-4 mb-0">Rules for a Word document</h2>

    <div class="tg-rule">
        <div><h3>1. Fields: a label, a colon and a blank</h3><p>Any line with a label followed by a colon and a blank, a line of underscores, dots or a [hint in brackets] becomes a box on the form. The usual memo labels (TO, FROM, CC, REF, DATE, SUBJECT) are recognised by name.</p></div>
        <div class="tg-word"><b>TO:</b> <span class="tg-hint">[Title of main recipient]</span>
<b>Amount in words:</b> ____________________
<b>Cost centre:</b> ........</div>
    </div>

    <div class="tg-rule">
        <div>
            <h3>2. Two columns on one line: use a tab</h3>
            <p>Put a tab (not spaces) between the two halves, and set the tab stop where the second column should start.</p>
            <p>PDTS turns the line into two columns at that tab stop, so a long answer (a long job title in FROM, for example) wraps under itself instead of running back under the other field.</p>
        </div>
        <div class="tg-word"><b>TO:</b> <span class="tg-hint">[recipient]</span><span class="tg-tab"></span><b>FROM:</b> <span class="tg-hint">[your title]</span>
<b>REF:</b> <span class="tg-hint">[DEPT/000/2026]</span><span class="tg-tab"></span><b>DATE:</b> <span class="tg-hint">[Day Month Year]</span></div>
    </div>

    <div class="tg-rule">
        <div><h3>3. The main text: leave an empty line</h3><p>An empty line straight after the subject becomes the body. In reports, an empty line under each heading (Heading style, or numbered like "1. INTRODUCTION") becomes a space for that section.</p></div>
        <div class="tg-word"><b>SUBJECT:</b> <span class="tg-hint">[SUBJECT IN CAPITALS]</span>
Reference is made to the above subject matter.
<span class="tg-hint">(empty line: the body goes here)</span>

<b>1. EXECUTIVE SUMMARY</b>
<span class="tg-hint">(empty line: this section's text)</span></div>
    </div>

    <div class="tg-rule">
        <div>
            <h3>4. Lines PDTS fills by itself</h3>
            <p>A line that is only a hint in brackets is filled for you: a [SUBJECT …] hint becomes the subject, a [FULL NAME …] hint becomes the signatory's name (in capitals if the hint says so).</p>
            <p>Extra hint lines under CC (one per person copied) are taken out once CC becomes a field. Their look is kept: a line drawn under the CC block stays (also when nobody is copied), and the names after the first line up under it.</p>
        </div>
        <div class="tg-word"><b>CC:</b> <span class="tg-hint">[Title of person copied]</span>
        <span class="tg-hint">[Title of person copied]</span><span class="tg-line"></span><span class="tg-hint">[SUBJECT IN CAPITAL LETTERS]</span>
<span class="tg-hint">[FULL NAME IN CAPITALS]</span>
<span class="tg-hint">[Typist initials, e.g. ABC/xy]</span></div>
    </div>

    <div class="tg-rule">
        <div>
            <h3>5. Where the signature goes</h3>
            <p>Use one of these. PDTS looks for them in this order: a "Signature:" line; a table column headed "Signature" (first row under it); otherwise the empty line just above the [FULL NAME] line.</p>
            <p><strong>No signature spot found?</strong> The template is not offered to authors until you either mark the signature line on the QR code page, or mark it <em>not signed</em> on its review page (for forms and notices nobody signs; they are issued in PDTS without a signature).</p>
        </div>
        <div class="tg-word"><b>Signature:</b> ____________________

<span class="tg-hint">(empty line: signature)</span>
<span class="tg-hint">[FULL NAME IN CAPITALS]</span></div>
    </div>

    <div class="tg-rule">
        <div><h3>6. Leave room for the QR code</h3><p>Keep a free corner on the first page, about 25 × 30 mm, usually top left or top right. The QR code and the reference number under it are placed there after upload.</p></div>
        <div class="tg-word"><span class="tg-hint">┌────────┐
│  QR    │   (free corner, ~25 × 30 mm)
└────────┘
OMTS-2026-0001</span></div>
    </div>

    <div class="tg-rule">
        <div><h3>7. ZESCO's font: {{ config('pdts.builder.font', 'Tahoma') }}</h3><p>Write the document in {{ config('pdts.builder.font', 'Tahoma') }}, ZESCO's house font, as the memorandum is. PDTS keeps the fonts of the document, so what authors produce matches what you uploaded. Templates built in PDTS use {{ config('pdts.builder.font', 'Tahoma') }} too.</p></div>
        <div class="tg-word">Home → Font → <b>{{ config('pdts.builder.font', 'Tahoma') }}</b></div>
    </div>

    <div class="tg-rule">
        <div><h3>8. Save it the right way</h3><p>Save as a Word document (.docx). PDF templates can be uploaded too, but they are printed and filled in by hand: only the QR code is added. Use the logo and letterhead as images in the document or its header, as usual.</p></div>
        <div class="tg-word">File → Save As → <b>Word Document (*.docx)</b></div>
    </div>

    <details class="mt-4" data-test="guide-advanced">
        <summary class="font-weight-bold" style="cursor: pointer;">Advanced: type a detail's place yourself</summary>
        <p class="text-muted small mt-2 mb-2">Optional, for experts. Type a placeholder in the Word file where a detail should go. Any other name, for example <code>${amount}</code> or <code>${due_date}</code>, becomes a field on the form ("Amount", "Due date"); a name starting with <code>text_</code> becomes a space to write paragraphs. Use lower-case letters, numbers and underscores.</p>
        <div class="table-responsive">
            <table class="table table-sm bg-white border mb-0">
                <thead><tr><th>Placeholder</th><th>Becomes</th></tr></thead>
                <tbody>
                    @foreach (\App\Models\PdtsDocumentTemplate::PLACEHOLDERS as $key => $meaning)
                        <tr><td><code>${{ '{' }}{{ $key }}{{ '}' }}</code></td><td>{{ $meaning }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </details>

    <div class="mt-3 small text-muted">
        <i class="fas fa-info-circle mr-1"></i>After uploading:
        <ol class="tg-steps">
            <li>Place the QR code on the preview, and mark any line PDTS missed (a field, a space to write in, the signature spot).</li>
            <li>Save, then check what PDTS found on the template's review page, and set how each field looks on the form.</li>
            <li>Use <strong>Try it</strong> to see the exact PDF with sample details before authors use it.</li>
        </ol>
    </div>
</div>
