# ZESCO Physical Document Tracking System

PDTS is an internal Laravel application for registering physical documents and tracking custody as they move between employees. A handover remains pending until its intended recipient acknowledges physical receipt; the system then records the custody transfer and preserves the movement history.

## Registration Autofill

On **Register Document**, uploading a scan automatically runs local OCR and sends the extracted text to Azure AI to suggest the title, subject, description, printed document reference, document date, and an existing active document type. The description is a draft factual summary of 1-3 sentences (up to 1,000 characters) based on readable document content, generated in the same request. This requires both `documents.register` and `ai.ocr.use`; users without OCR permission can register manually. Unknown fields stay blank. Review and correct suggestions before submitting **Register Document**, which remains the only action that saves the record.

Extraction fills blank fields only. Replacing the upload clears unchanged previous suggestions but keeps manual corrections. Errors retain the scan and entered values for retry. Original OCR text remains available for comparison. The printed **Document Reference** and **Document Date** are optional and separate from the generated PDTS tracking reference; duplicate printed references are allowed.

The applicant is the signed-in employee. Their name, man number and available organisational details come from the existing PHRIS-synced local profile, not AI; registration does not make a fresh PHRIS request. A usable assigned PDTS office takes precedence for registration routing. Otherwise, unique case-insensitive directorate/division name matches prefill the corresponding active PDTS units; unmatched or ambiguous units and the office require manual selection. AI never selects the applicant or routing office.

Deployment requires the two nullable columns below. Back up the database and obtain approval before applying this specific migration; do not reset or seed an existing database:

```shell
php artisan migrate --path=database/migrations/2026_11_18_120000_add_document_reference_and_date_to_pdts_documents.php --force
```

Start the existing local OCR service from the repository root in a separate terminal:

```powershell
.\ai-service\.venv\Scripts\python.exe -m uvicorn app.main:app --app-dir ai-service --host 127.0.0.1 --port 8001
```

The service uses `AI_SERVICE_URL`, `AI_SERVICE_TOKEN` (falling back to `APP_KEY` for local development), and `AI_SERVICE_TIMEOUT`. Its Python dependencies must be installed in that environment. Keep the service running while using analysis; the first image analysis may take longer while OCR models load.

The focused OCR tests fake HTTP and exercise registration actions without connecting to a database:

```shell
php vendor/pestphp/pest/bin/pest tests/Unit/DocumentOcrClientTest.php --compact
```

## Receipt Verification

**Acknowledge** on Pending Acknowledgements opens **Verify document match**. The intended recipient or active delegate uploads a complete received PDF or a single-page image (up to 20 MB) and selects **Compare documents**. Both `documents.acknowledge` and `ai.ocr.use` are required; assign AI access to recipients before using this mandatory screen workflow. A messenger must have confirmed pickup when one is assigned.

Local OCR reads both the unchanged registered scan and the received copy; one AI request compares their text (up to 20,000 characters per scan). This is not visual, signature or physical-original authentication. Treat a likely match as review assistance, not proof of authenticity. Unreadable/ambiguous scans or service errors keep receipt blocked and allow retry. Page-count differences are independently classified as material.

When differences are found, the recipient enters the printed reference on the received document. The application cross-checks it against references extracted from both scans, ignoring case/whitespace but preserving separators. A matching reference cannot override material differences. A missing/unreadable reference cannot be replaced by a typed guess. Reference mismatches or material differences block receipt and draft a correction explanation. **Request correction from sender** opens the existing decline confirmation, where the reason can be reviewed and edited; nothing is sent until the user confirms **Decline**. Delegates retain acknowledgement rights but only the intended recipient can decline, following existing custody policy.

**Confirm receipt** is enabled only for a likely match. The browser action also enforces verification server-side and binds it to the handover, recipient, acting user and SHA-256 hashes of both files. Changing the upload or handover invalidates the result. The binding is checked again inside the existing locked custody transaction before updates. Successful received copies become non-primary private attachments, and comparison details, references, hashes and attachment identity are recorded in the existing acknowledgement audit. Failed transaction copies are cleaned up. Declined comparisons retain the reviewed decline reason; temporary received uploads for unsuccessful comparisons are not added as permanent evidence. No database migration or reset is needed. Underlying trusted internal custody calls retain their existing API; the pending-receipt screen always supplies the verification gate.

Database-free verification checks:

```shell
php vendor/pestphp/pest/bin/pest tests/Unit/ReceiptVerificationTest.php tests/Unit/AzureOpenAiClientTest.php --compact
```

## Document Analysis And Chat

On a registered document's detail page, **AI Analysis** remains beneath **Scanned Copy** for users with both `documents.view` and `ai.ocr.use`, when a primary attachment exists. It opens `/documents/{document}/analysis` in the same tab, with a back link, scan preview and analysis/chat alongside each other (stacked on smaller screens). Select **Analyse with AI** to read the primary scan and generate its summary, key points, requested actions, and explicitly stated dates or amounts. Page references are shown when OCR provides page information; check those references against the scan, since AI answers can still be incorrect.

Analysis sends the private scan to local OCR and its extracted text to Azure. Each summary or chat question is a billable Azure request. No AI request runs simply by opening the detail page. OCR is reused for follow-up questions and Azure retries in the current page session. Text is limited to 20,000 characters without truncation, files to 20 MB, and each question to 1,000 characters. Each response requests up to 4,096 completion tokens. After five questions, clear the chat to continue using the same analysis.

The tracking panel refreshes every 30 seconds while visible. Each question fetches a fresh, read-only snapshot of status, current custodian and pending/latest handover (sender, recipient, forwarding, messenger pickup and acknowledgement). Tracking access follows `CustodyService::canViewHistory`; users without it receive no tracking payload, and previous conversation is withheld from the model to avoid reusing restricted tracking answers. Inline preview uses the existing scan route and the same history permission. Only relevant names and tracking facts are sent, not employee profiles. Earlier handovers beyond the latest/pending entries are not supplied.

Chat is instructed to distinguish "According to PDTS tracking" from "According to the scanned document", prefer the fresh snapshot over earlier answers, and never infer custody from OCR. Forwarding or pickup does not transfer custody; acknowledgement does. Completed tracking status does not establish approval of the document's business request. Scan summaries remain based only on OCR. Each answer records the tracking snapshot timestamp where available, so older answers are not mistaken for current status.

The model must ignore embedded instructions and say "Not stated in the document" for unsupported scan answers. Responses are structurally validated, page references must exist in the supplied source, and text is escaped rather than rendered as HTML. Every action rechecks permissions; analysis and chat also recheck the private file, discarding previous context when the primary scan changes. Conversation and source state are locked against client edits and belong only to the current Livewire component, not a shared cache. Reloading or leaving the page clears the conversation. Nothing updates document metadata, approvals, custody or movement history, and no database migration is required.

## Azure OpenAI Connection

On **Document OCR Review**, extract a scan and select **Format with Azure AI** to send the extracted text to the configured Azure deployment. The result uses headings, paragraphs, lists, and tables where the source clearly supports them. **Original** retains the unchanged OCR text for comparison. AI output is a draft: check wording, amounts, dates, and table alignment against the scan. Metadata suggestions are unchanged by formatting.

Formatting requires `ai.ocr.use`, accepts up to 20,000 characters, and requests up to 8,192 completion tokens (including reasoning tokens). It does not silently truncate longer input or accept incomplete responses. Errors leave the original available for retry. A successful result is reused within the current review; replacing, clearing, or reanalysing the scan clears it. Formatting does not save a document or change the database. Output is validated into supported blocks and escaped when displayed; model-provided HTML and external resources are never rendered.

Registration automatically makes a billable Azure request after local OCR succeeds, accepting up to 20,000 characters and requesting up to 4,096 completion tokens. It sends OCR text and active document type options, not separately attached employee profile data. OCR text may itself contain personal information: use only documents approved for processing in the configured Azure environment. Selecting **Format with Azure AI** on OCR Review also makes a billable request. The general completion limit below applies to other client calls, while these two document actions use their own limits. Failed requests can be retried manually; successful registration suggestions are reused until the scan changes.

Set these values in the local, Git-ignored `.env` file. Never put credentials in `.env.example`, browser code, screenshots, or commits:

```dotenv
AZURE_OPENAI_ENDPOINT=https://YOUR-RESOURCE.openai.azure.com
AZURE_OPENAI_DEPLOYMENT=YOUR-DEPLOYMENT-NAME
AZURE_OPENAI_API_KEY=
AZURE_OPENAI_API_VERSION=
AZURE_OPENAI_TIMEOUT=90
AZURE_OPENAI_MAX_COMPLETION_TOKENS=1024
```

Use the exact Azure deployment name, which can differ from the underlying model name. This connection uses API-key authentication and requires a deployment supporting Chat Completions and `max_completion_tokens`; Microsoft Entra ID authentication and Responses-only deployments are not implemented.

With an empty API version, the client uses `/openai/v1/chat/completions`. The endpoint may be the resource root or the `/openai/v1` base URL. For a versioned API, use the resource root and enter the API version shown in Azure's sample request. Do not enter a full Chat Completions URL or a URL containing query parameters.

After configuring the values, clear Laravel's configuration cache and run the explicit connectivity check:

```shell
php artisan config:clear
php artisan azure:check
```

The check makes one billable request containing only a fixed test prompt, not document data. It does not update the database. Restart long-running workers after changing configuration. Credentials must remain server-side; HTTP redirects and automatic retries are disabled. The configured completion limit includes reasoning tokens on models that use them; an incomplete response is reported as an error rather than accepted.

The standalone client tests fake all HTTP requests and do not boot the Laravel application or connect to a database:

```shell
php vendor/pestphp/pest/bin/pest tests/Unit/AzureOpenAiClientTest.php --compact
```
