<?php

use App\Livewire\Pdts\Documents\DocumentAiPanel;
use App\Livewire\Pdts\Documents\Registration;
use App\Livewire\Pdts\OcrWorkbench;
use App\Models\DocumentMovement;
use App\Models\PdtsDepartment;
use App\Models\PdtsDirectorate;
use App\Models\PdtsDocument;
use App\Models\PdtsDocumentAttachment;
use App\Models\PdtsOffice;
use App\Models\User;
use App\Services\Ai\DocumentConversation;
use App\Services\Ai\DocumentMetadataExtractor;
use App\Services\Ai\DocumentOcrClient;
use App\Services\Ai\DocumentTrackingContext;
use App\Services\Ai\OcrTextFormatter;
use App\Services\Documents\CustodyService;
use App\Services\Pdts\RegistrationApplicant;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory as ViewFactory;
use Illuminate\View\FileViewFinder;
use Livewire\Mechanisms\DataStore;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->http = new Factory;
    $this->http->preventStrayRequests();
    $this->config = new Repository([
        'services' => ['pdts_ai' => ['url' => 'http://127.0.0.1:8001/', 'token' => 'test-only-token', 'timeout' => 180]],
    ]);
    $this->client = new DocumentOcrClient($this->http, $this->config);
    $this->scan = UploadedFile::fake()->createWithContent('scan.pdf', '%PDF-test-scan');
});

it('sends the upload to the local OCR service and returns only review data', function () {
    $this->http->fake(['*' => $this->http->response([
        'text' => "  Travel authority\nInspection visit.  ",
        'page_count' => 2,
        'suggestions' => ['suggested_subject' => 'Not applied'],
    ])]);

    expect($this->client->extract($this->scan))->toBe([
        'text' => "Travel authority\nInspection visit.", 'page_count' => 2,
    ]);
    $this->http->assertSent(fn (Request $request) => $request->url() === 'http://127.0.0.1:8001/api/ocr'
        && $request->method() === 'POST'
        && $request->hasHeader('X-Pdts-Ai-Token', 'test-only-token')
        && $request->hasFile('file', '%PDF-test-scan', 'scan.pdf'));
    $this->http->assertSentCount(1);
    expect($this->scan->get())->toBe('%PDF-test-scan');
});

it('preserves metadata and page analysis for the OCR review page', function () {
    $result = [
        'text' => 'Travel authority', 'page_count' => 1,
        'pages' => [['page' => 1, 'text' => 'Travel authority', 'source' => 'ocr', 'confidence' => 0.95]],
        'suggestions' => ['reference_number' => 'DOC-2026-001', 'suggested_subject' => 'Travel', 'dates' => ['17/11/2026']],
    ];
    $this->http->fake(['*' => $this->http->response($result)]);
    expect($this->client->review($this->scan))->toBe($result);
    $this->http->assertSentCount(1);
});

it('rejects malformed review details', function (array $details) {
    $this->http->fake(['*' => $this->http->response(array_merge(['text' => 'Travel', 'page_count' => 1], $details))]);
    expect(fn () => $this->client->review($this->scan))->toThrow(RuntimeException::class);
})->with([
    [['pages' => 'invalid']],
    [['suggestions' => ['dates' => 'invalid']]],
    [['pages' => [['page' => 1, 'text' => 'Travel', 'source' => 'ocr', 'confidence' => []]]]],
]);

it('requires valid configuration without sending an upload', function (string $key, mixed $value) {
    $this->config->set('services.pdts_ai.'.$key, $value);
    expect(fn () => $this->client->extract($this->scan))->toThrow(RuntimeException::class, 'not configured');
    $this->http->assertNothingSent();
})->with([['url', ''], ['token', ''], ['timeout', 0]]);

it('handles unreadable or malformed results', function (array $body, string $message) {
    $this->http->fake(['*' => $this->http->response($body)]);
    expect(fn () => $this->client->extract($this->scan))->toThrow(RuntimeException::class, $message);
})->with([
    [['text' => " \n\t ", 'page_count' => 1], 'No readable text'],
    [['text' => null, 'page_count' => 1], 'invalid result'],
    [['text' => 'Readable', 'page_count' => 0], 'invalid result'],
    [['text' => 'Readable', 'page_count' => 'one'], 'invalid result'],
    [[], 'invalid result'],
    [['text' => str_repeat('text ', 40001), 'page_count' => 50], 'too large'],
]);

it('handles service errors without exposing response details', function (int $status, string $message) {
    $this->http->fake(['*' => $this->http->response(['detail' => 'private-service-details'], $status)]);
    expect(fn () => $this->client->extract($this->scan))->toThrow(RuntimeException::class, $message);
    $this->http->assertSentCount(1);
})->with([
    [401, 'authenticate'], [403, 'authenticate'], [413, 'processing limits'],
    [415, 'could not be read'], [422, 'could not be read'], [500, 'analysis failed'], [302, 'analysis failed'],
]);

it('handles network failures without exposing credentials', function () {
    $this->http->fake(fn () => throw new ConnectionException('private-connection-details'));
    expect(fn () => $this->client->extract($this->scan))->toThrow(RuntimeException::class, 'unavailable or took too long');
});

it('renders structured OCR safely while retaining the original text', function () {
    $files = new Filesystem;
    $cachePath = sys_get_temp_dir().'/pdts-formatting-'.bin2hex(random_bytes(6));
    $files->makeDirectory($cachePath);

    try {
        $compiler = new BladeCompiler($files, $cachePath);
        $engines = new EngineResolver;
        $engines->register('blade', fn () => new CompilerEngine($compiler, $files));
        $views = new ViewFactory($engines, new FileViewFinder($files, [dirname(__DIR__, 2).'/resources/views']), new Dispatcher);
        $html = $views->make('livewire.pdts.ocr-workbench', [
            'document' => null,
            'analysis' => ['text' => '</textarea><script>alert("source")</script>', 'page_count' => 1],
            'uploadedFilename' => 'sample.pdf',
            'analysisError' => null,
            'formattingError' => null,
            'errors' => new ViewErrorBag,
            'formattedBlocks' => [
                ['type' => 'heading', 'text' => 'REQUEST'],
                ['type' => 'paragraph', 'text' => '<img src="https://example.test/track" onerror="alert(1)">'],
                ['type' => 'list', 'items' => ['Check request']],
                ['type' => 'table', 'headers' => ['Item', 'Value'], 'rows' => [['<script>alert(1)</script>', '1,200.00']]],
            ],
        ])->render();

        expect($html)->toContain('ocr-formatted-panel', 'ocr-original-panel', 'REQUEST', '<ul', '1,200.00', 'table-responsive')
            ->toContain('&lt;script&gt;alert(1)&lt;/script&gt;', '&lt;img', '&lt;/textarea&gt;')
            ->not->toContain('<script>', '<img', 'wire:click="formatText"');
    } finally {
        $files->deleteDirectory($cachePath);
    }
});

it('keeps the printed reference and document date distinct from the tracking reference', function () {
    $document = new PdtsDocument([
        'reference_number' => 'PDTS-2026-001', 'document_reference' => 'HR/123/2026', 'document_date' => '2026-11-18',
    ]);
    expect($document->reference_number)->toBe('PDTS-2026-001')
        ->and($document->document_reference)->toBe('HR/123/2026')
        ->and($document->document_date->format('Y-m-d'))->toBe('2026-11-18');
    $document->fill(['document_reference' => null, 'document_date' => null]);
    expect($document->document_reference)->toBeNull()->and($document->document_date)->toBeNull();
});

describe('PHRIS applicant mapping without database access', function () {
    beforeEach(function () {
        $this->employee = new User(['name' => 'Demo Employee', 'man_no' => '001234', 'directorate' => ' technology ', 'division' => 'Applications', 'job_title' => 'Analyst']);
        $this->directorate = new PdtsDirectorate(['name' => 'Technology', 'is_active' => true]);
        $this->directorate->id = 10;
        $this->department = new PdtsDepartment(['name' => 'Applications', 'directorate_id' => 10, 'is_active' => true]);
        $this->department->id = 20;
        $this->department->setRelation('directorate', $this->directorate);
        $this->directorate->setRelation('departments', collect([$this->department]));
    });

    it('uses the logged-in employee profile and their usable assigned office', function () {
        $office = new PdtsOffice(['name' => 'Development', 'department_id' => 20, 'is_active' => true]);
        $office->id = 30;
        $office->setRelation('department', $this->department);
        $result = (new RegistrationApplicant)->resolve($this->employee, $office, collect());
        expect($result['applicant']['name'])->toBe('Demo Employee')
            ->and($result['applicant']['man_no'])->toBe('001234')
            ->and($result['directorate_id'])->toBe('10')
            ->and($result['department_id'])->toBe('20')
            ->and($result['office_id'])->toBe('30')
            ->and($result['unit_source'])->toBe('Employee office assignment');
    });

    it('matches unique active HR names without inventing an office', function () {
        $result = (new RegistrationApplicant)->resolve($this->employee, null, collect([$this->directorate]));
        expect($result['directorate_id'])->toBe('10')->and($result['department_id'])->toBe('20')->and($result['office_id'])->toBe('');
    });

    it('leaves unknown or ambiguous HR mappings unselected', function () {
        $resolver = new RegistrationApplicant;
        expect($resolver->resolve($this->employee, null, collect([$this->directorate, $this->directorate]))['directorate_id'])->toBe('');
        $this->employee->division = 'Not configured';
        expect($resolver->resolve($this->employee, null, collect([$this->directorate]))['department_id'])->toBe('');
        $this->directorate->is_active = false;
        expect($resolver->resolve($this->employee, null, collect([$this->directorate]))['directorate_id'])->toBe('');
    });
});

describe('document analysis without database access', function () {
    beforeEach(function () {
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApp = Facade::getFacadeApplication();
        $application = new Application(dirname(__DIR__, 2));
        $application->instance('config', $this->config);
        $application->singleton(DataStore::class);
        $this->authorization = new class
        {
            public ?string $deniedAbility = null;

            public function user(): self
            {
                return $this;
            }

            public function can(string $ability): bool
            {
                return $ability !== $this->deniedAbility;
            }
        };
        $application->instance('auth', $this->authorization);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($application);
        $this->registration = $this->getMockBuilder(Registration::class)
            ->onlyMethods(['validateOnly', 'resetValidation', 'documentTypeOptions'])
            ->getMock();
        $this->registration->attachment = $this->scan;
        $this->registration->title = 'My entered title';
        $this->registration->subject = 'My entered subject';
        $this->registration->description = 'My entered description';
        $this->registration->document_type_id = '7';
        $this->workbench = $this->getMockBuilder(OcrWorkbench::class)
            ->onlyMethods(['validate', 'resetValidation'])
            ->getMock();
        $this->workbench->document = $this->scan;
    });

    afterEach(function () {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApp);
        Container::setInstance($this->previousContainer);
    });

    it('summarizes a stored scan once and reuses OCR for follow-up chat', function () {
        $panel = $this->getMockBuilder(DocumentAiPanel::class)->onlyMethods(['findScan', 'readScan', 'validate', 'resetValidation', 'trackingContext'])->getMock();
        $panel->documentId = 5;
        $scan = new PdtsDocumentAttachment(['original_filename' => 'memo.pdf', 'storage_path' => 'memo.pdf']);
        $panel->method('findScan')->willReturn($scan);
        $panel->method('readScan')->willReturn($this->scan);
        $panel->method('validate')->willReturn([]);
        $ocr = $this->createMock(DocumentOcrClient::class);
        $review = ['text' => 'A travel request.', 'page_count' => 1];
        $ocr->expects($this->once())->method('review')->willReturn($review);
        $conversation = $this->createMock(DocumentConversation::class);
        $source = [['page' => null, 'text' => 'A travel request.']];
        $summary = ['summary' => ['text' => 'Travel is requested.', 'pages' => []], 'key_points' => [], 'requested_actions' => [], 'dates_and_amounts' => []];
        $conversation->expects($this->once())->method('source')->with($review)->willReturn($source);
        $conversation->expects($this->once())->method('summarize')->with($source)->willReturn($summary);
        $panel->analyse($ocr, $conversation);
        $panel->analyse($ocr, $conversation);
        $panel->question = 'Is it approved?';
        $conversation->expects($this->once())->method('answer')->with($source, [], 'Is it approved?')->willReturn(['text' => 'Not stated in the document.', 'pages' => []]);
        $panel->ask($conversation);
        expect($panel->summary)->toBe($summary)->and($panel->messages)->toHaveCount(2)
            ->and($panel->messages[1]['text'])->toBe('Not stated in the document.')
            ->and($panel->question)->toBe('')->and($panel->analysisError)->toBeNull();
        $panel->clearChat();
        expect($panel->messages)->toBe([])->and($panel->source)->toBe($source)->and($panel->summary)->toBe($summary);
    });

    it('denies document AI actions before accessing files when a permission is revoked', function (string $ability, string $action) {
        $this->authorization->deniedAbility = $ability;
        $panel = $this->getMockBuilder(DocumentAiPanel::class)->onlyMethods(['findScan'])->getMock();
        $panel->expects($this->never())->method('findScan');
        $conversation = $this->createMock(DocumentConversation::class);
        $conversation->expects($this->never())->method('answer');
        $conversation->expects($this->never())->method('summarize');
        expect(fn () => match ($action) {
            'analyse' => $panel->analyse($this->client, $conversation),
            'ask' => $panel->ask($conversation),
            'clearChat' => $panel->clearChat(),
            'render' => $panel->render(),
        })->toThrow(HttpException::class);
    })->with(['documents.view', 'ai.ocr.use'])->with(['analyse', 'ask', 'clearChat', 'render']);

    it('does not answer using stale analysis after the attached scan changes', function () {
        $panel = $this->getMockBuilder(DocumentAiPanel::class)->onlyMethods(['findScan', 'readScan', 'validate'])->getMock();
        $panel->documentId = 5;
        $panel->fingerprint = 'old-file';
        $panel->source = [['page' => 1, 'text' => 'Old scan']];
        $panel->summary = ['summary' => ['text' => 'Old analysis', 'pages' => [1]]];
        $panel->question = 'What does it say?';
        $panel->method('validate')->willReturn([]);
        $panel->method('findScan')->willReturn(new PdtsDocumentAttachment(['original_filename' => 'new.pdf']));
        $panel->method('readScan')->willReturn($this->scan);
        $conversation = $this->createMock(DocumentConversation::class);
        $conversation->expects($this->never())->method('answer');
        $panel->ask($conversation);
        expect($panel->source)->toBe([])->and($panel->summary)->toBe([])
            ->and($panel->analysisError)->toContain('scan may have changed')
            ->and($panel->question)->toBe('What does it say?');
    });

    it('retains OCR and unanswered questions for retry after Azure failures', function () {
        $panel = $this->getMockBuilder(DocumentAiPanel::class)->onlyMethods(['findScan', 'readScan', 'validate', 'trackingContext'])->getMock();
        $panel->documentId = 5;
        $panel->method('findScan')->willReturn(new PdtsDocumentAttachment(['original_filename' => 'memo.pdf']));
        $panel->method('readScan')->willReturn($this->scan);
        $panel->method('validate')->willReturn([]);
        $ocr = $this->createMock(DocumentOcrClient::class);
        $ocr->expects($this->once())->method('review')->willReturn(['text' => 'Scan', 'page_count' => 1]);
        $conversation = $this->createMock(DocumentConversation::class);
        $conversation->method('source')->willReturn([['page' => null, 'text' => 'Scan']]);
        $attempt = 0;
        $conversation->expects($this->exactly(2))->method('summarize')->willReturnCallback(function () use (&$attempt) {
            if (++$attempt === 1) {
                throw new RuntimeException('Azure unavailable');
            }

            return ['summary' => ['text' => 'Summary', 'pages' => []]];
        });
        $panel->analyse($ocr, $conversation);
        expect($panel->analysisError)->toBe('Azure unavailable')->and($panel->source)->not->toBe([]);
        $panel->analyse($ocr, $conversation);
        expect($panel->analysisError)->toBeNull()->and($panel->summary)->not->toBe([]);
        $panel->question = 'What is requested?';
        $conversation->expects($this->once())->method('answer')->willThrowException(new RuntimeException('Azure unavailable'));
        $panel->ask($conversation);
        expect($panel->question)->toBe('What is requested?')->and($panel->messages)->toBe([])
            ->and($panel->analysisError)->toBe('Azure unavailable');
        $panel->messages = array_fill(0, 10, ['role' => 'user', 'text' => 'Question', 'pages' => []]);
        $panel->ask($conversation);
        expect($panel->analysisError)->toContain('five questions');
    });

    it('refreshes tracking for every question and withholds previous messages if history access is lost', function () {
        $panel = $this->getMockBuilder(DocumentAiPanel::class)->onlyMethods(['findScan', 'readScan', 'validate', 'trackingContext'])->getMock();
        $panel->documentId = 5;
        $panel->method('findScan')->willReturn(new PdtsDocumentAttachment(['original_filename' => 'memo.pdf']));
        $panel->method('readScan')->willReturn($this->scan);
        $panel->method('validate')->willReturn([]);
        $ocr = $this->createMock(DocumentOcrClient::class);
        $ocr->method('review')->willReturn(['text' => 'Scan', 'page_count' => 1]);
        $conversation = $this->createMock(DocumentConversation::class);
        $conversation->method('source')->willReturn([['page' => null, 'text' => 'Scan']]);
        $conversation->method('summarize')->willReturn(['summary' => ['text' => 'Summary', 'pages' => []]]);
        $snapshots = [['available' => true, 'status' => 'awaiting_receipt'], ['available' => true, 'status' => 'received'], ['available' => false]];
        $panel->expects($this->exactly(3))->method('trackingContext')->willReturnOnConsecutiveCalls(...$snapshots);
        $attempt = 0;
        $conversation->expects($this->exactly(3))->method('answer')->willReturnCallback(function ($source, $history, $question, $tracking) use (&$attempt, $snapshots) {
            expect($tracking)->toBe($snapshots[$attempt]);
            expect($history)->toHaveCount($attempt === 1 ? 2 : 0);
            $attempt++;

            return ['text' => 'According to PDTS tracking, '.($tracking['status'] ?? 'Unavailable'), 'pages' => []];
        });
        $panel->analyse($ocr, $conversation);
        foreach ($snapshots as $snapshot) {
            $panel->question = 'Who holds it now?';
            $panel->ask($conversation);
        }
        expect($panel->messages)->toHaveCount(6);
    });

    it('maps custody and handover facts without treating pickup as receipt', function () {
        $document = $this->getMockBuilder(PdtsDocument::class)->onlyMethods(['load'])->getMock();
        $document->method('load')->willReturnSelf();
        $document->status = 'awaiting_receipt';
        $document->reference_number = 'TEST-1';
        $sender = new User(['name' => 'Sender', 'email' => 'private@example.test']);
        $recipient = new User(['name' => 'Recipient']);
        $movement = new DocumentMovement;
        $movement->setDateFormat('Y-m-d H:i:s');
        $movement->fill(['status' => 'awaiting_receipt', 'picked_up_at' => '2026-11-18 10:00:00']);
        $movement->setRelation('sender', $sender)->setRelation('recipient', $recipient)->setRelation('messenger', new User(['name' => 'Messenger']));
        $document->setRelation('currentCustodian', $sender)->setRelation('pendingMovement', $movement)->setRelation('latestMovement', $movement);
        $custody = $this->createMock(CustodyService::class);
        $custody->method('canViewHistory')->willReturn(true);
        $context = new DocumentTrackingContext($custody);
        $pending = $context->forDocument($document, $sender);
        expect($pending['current_custodian'])->toBe('Sender')->and($pending['has_been_forwarded'])->toBeTrue()
            ->and($pending['pending_handover']['recipient'])->toBe('Recipient')
            ->and($pending['pending_handover']['picked_up_at'])->not->toBeNull()
            ->and($pending['pending_handover']['acknowledged_at'])->toBeNull()
            ->and(json_encode($pending))->not->toContain('private@example.test');
        $document->status = 'received';
        $movement->status = 'acknowledged';
        $movement->acknowledged_at = '2026-11-18 11:00:00';
        $document->setRelation('currentCustodian', $recipient)->setRelation('pendingMovement', null);
        $received = $context->forDocument($document, $sender);
        expect($received['status'])->toBe('received')->and($received['current_custodian'])->toBe('Recipient')
            ->and($received['pending_handover'])->toBeNull()->and($received['latest_handover']['acknowledged_at'])->not->toBeNull();
        $document->status = 'completed';
        expect($context->forDocument($document, $sender)['status_label'])->toBe('Completed');
    });

    it('does not load or expose tracking relations without history permission', function () {
        $document = $this->getMockBuilder(PdtsDocument::class)->onlyMethods(['load'])->getMock();
        $document->expects($this->never())->method('load');
        $custody = $this->createMock(CustodyService::class);
        $custody->method('canViewHistory')->willReturn(false);
        expect((new DocumentTrackingContext($custody))->forDocument($document, new User))->toBe(['available' => false]);
    });

    it('reports a missing primary scan without calling OCR or Azure', function () {
        $panel = $this->getMockBuilder(DocumentAiPanel::class)->onlyMethods(['findScan', 'readScan'])->getMock();
        $panel->method('findScan')->willReturn(null);
        $panel->expects($this->never())->method('readScan');
        $ocr = $this->createMock(DocumentOcrClient::class);
        $ocr->expects($this->never())->method('review');
        $conversation = $this->createMock(DocumentConversation::class);
        $conversation->expects($this->never())->method('summarize');
        $panel->analyse($ocr, $conversation);
        expect($panel->analysisError)->toContain('no primary scanned copy');
    });

    it('reads a stored scan from the private disk and handles missing storage files', function () {
        $this->config->set('filesystems.disks.private', ['driver' => 'local', 'root' => dirname($this->scan->getPathname())]);
        Storage::swap(new FilesystemManager(Container::getInstance()));
        $scan = new PdtsDocumentAttachment(['original_filename' => 'scan.pdf', 'storage_path' => basename($this->scan->getPathname()), 'mime_type' => 'application/pdf']);
        $method = new ReflectionMethod(DocumentAiPanel::class, 'readScan');
        $file = $method->invoke(new DocumentAiPanel, $scan);
        expect($file->getClientOriginalName())->toBe('scan.pdf')->and($file->get())->toBe($this->scan->get());
        $scan->storage_path = 'missing-'.bin2hex(random_bytes(8)).'.pdf';
        expect(fn () => $method->invoke(new DocumentAiPanel, $scan))->toThrow(RuntimeException::class, 'unavailable');
    });

    it('validates only the attachment and preserves manually entered fields', function () {
        $this->registration->expects($this->once())->method('validateOnly')->with('attachment')->willReturn([]);
        $this->http->fake(['*' => $this->http->response(['text' => 'Extracted scan', 'page_count' => 1])]);

        $this->registration->analyseDocument($this->client);

        expect($this->registration->ocrText)->toBe('Extracted scan')
            ->and($this->registration->ocrPageCount)->toBe(1)
            ->and($this->registration->ocrError)->toBeNull()
            ->and($this->registration->title)->toBe('My entered title')
            ->and($this->registration->subject)->toBe('My entered subject')
            ->and($this->registration->description)->toBe('My entered description')
            ->and($this->registration->document_type_id)->toBe('7')
            ->and($this->registration->attachment)->toBe($this->scan);
    });

    it('clears stale results on failure but retains the scan and manual fields', function () {
        $this->registration->method('validateOnly')->willReturn([]);
        $this->registration->ocrText = 'Previous result';
        $this->registration->ocrPageCount = 3;
        $this->http->fake(['*' => $this->http->response(['text' => '', 'page_count' => 1])]);

        $this->registration->analyseDocument($this->client);

        expect($this->registration->ocrText)->toBe('')
            ->and($this->registration->ocrPageCount)->toBe(0)
            ->and($this->registration->ocrError)->toContain('No readable text')
            ->and($this->registration->title)->toBe('My entered title')
            ->and($this->registration->attachment)->toBe($this->scan);
    });

    it('clears the previous scan result when the upload changes', function () {
        $this->registration->ocrText = 'Previous result';
        $this->registration->ocrPageCount = 3;
        $this->registration->ocrError = 'Previous error';

        $this->registration->updatedAttachment();

        expect($this->registration->ocrText)->toBe('')
            ->and($this->registration->ocrPageCount)->toBe(0)
            ->and($this->registration->ocrError)->toBeNull()
            ->and($this->registration->title)->toBe('My entered title');
        $this->http->assertNothingSent();
    });

    it('refuses analysis without permission before sending the scan', function (string $ability) {
        $this->authorization->deniedAbility = $ability;
        $this->registration->expects($this->never())->method('validateOnly');

        expect(fn () => $this->registration->analyseDocument($this->client))->toThrow(HttpException::class);
        $this->http->assertNothingSent();
    })->with(['documents.register', 'ai.ocr.use']);

    it('automatically fills blank document fields once without saving or replacing manual values', function (string $description, string $expectedDescription) {
        $this->registration->method('validateOnly')->willReturn([]);
        $this->registration->method('documentTypeOptions')->willReturn([8 => 'Memo']);
        $this->registration->subject = '';
        $this->registration->description = $description;
        $this->registration->document_type_id = '';
        $this->http->fake(['*' => $this->http->response(['text' => 'OCR content', 'page_count' => 1])]);
        $extractor = $this->createMock(DocumentMetadataExtractor::class);
        $extractor->expects($this->once())->method('extract')->with('OCR content', [8 => 'Memo'])->willReturn([
            'title' => 'AI title', 'subject' => 'Travel authority', 'document_reference' => 'HR/123/2026',
            'description' => 'The memo requests travel authority for a site inspection.',
            'document_date' => '2026-11-18', 'document_type_id' => 8,
        ]);
        $this->registration->fillFromDocument($this->client, $extractor);
        $this->registration->fillFromDocument($this->client, $extractor);

        expect($this->registration->title)->toBe('My entered title')
            ->and($this->registration->subject)->toBe('Travel authority')
            ->and($this->registration->document_reference)->toBe('HR/123/2026')
            ->and($this->registration->document_date)->toBe('2026-11-18')
            ->and($this->registration->document_type_id)->toBe('8')
            ->and($this->registration->description)->toBe($expectedDescription)
            ->and($this->registration->autoFillComplete)->toBeTrue()
            ->and($this->registration->attachment)->toBe($this->scan);
        $this->http->assertSentCount(1);
    })->with([
        ['My entered description', 'My entered description'],
        ['', 'The memo requests travel authority for a site inspection.'],
    ]);

    it('clears only unchanged machine-filled values when another scan is uploaded', function (string $description, string $expectedDescription) {
        $this->registration->title = 'My corrected title';
        $this->registration->subject = 'Old AI subject';
        $this->registration->document_reference = 'OLD/1';
        $this->registration->description = $description;
        $this->registration->autoFilledValues = ['title' => 'Old AI title', 'subject' => 'Old AI subject', 'document_reference' => 'OLD/1', 'description' => 'Old AI description'];
        $this->registration->autoFillComplete = true;
        $this->registration->autoFillError = 'Old error';
        $this->registration->updatedAttachment();

        expect($this->registration->title)->toBe('My corrected title')
            ->and($this->registration->subject)->toBe('')
            ->and($this->registration->document_reference)->toBe('')
            ->and($this->registration->description)->toBe($expectedDescription)
            ->and($this->registration->autoFilledValues)->toBe([])
            ->and($this->registration->autoFillComplete)->toBeFalse()
            ->and($this->registration->autoFillError)->toBeNull();
        $events = \Livewire\store($this->registration)->get('dispatched', []);
        expect(array_map(fn ($event) => $event->serialize()['name'], $events))->toContain('registration-uploaded');
    })->with([
        ['Old AI description', ''],
        ['My corrected description', 'My corrected description'],
    ]);

    it('keeps OCR and manual fields available after an AI failure and permits retry', function () {
        $this->registration->method('validateOnly')->willReturn([]);
        $this->registration->method('documentTypeOptions')->willReturn([]);
        $this->http->fake(['*' => $this->http->response(['text' => 'OCR content', 'page_count' => 1])]);
        $extractor = $this->createMock(DocumentMetadataExtractor::class);
        $extractor->method('extract')->willThrowException(new RuntimeException('Azure unavailable'));
        $this->registration->fillFromDocument($this->client, $extractor);
        expect($this->registration->autoFillError)->toBe('Azure unavailable')
            ->and($this->registration->ocrText)->toBe('OCR content')
            ->and($this->registration->title)->toBe('My entered title')
            ->and($this->registration->autoFillComplete)->toBeFalse();
        $retry = $this->createMock(DocumentMetadataExtractor::class);
        $this->registration->description = '';
        $retry->method('extract')->willReturn(array_fill_keys(['title', 'subject', 'description', 'document_reference', 'document_date', 'document_type_id'], null));
        $this->registration->fillFromDocument($this->client, $retry);
        expect($this->registration->autoFillError)->toBeNull()
            ->and($this->registration->autoFillComplete)->toBeTrue()
            ->and($this->registration->description)->toBe('')
            ->and($this->registration->document_date)->toBe('');
    });

    it('does not send unreadable OCR to Azure', function () {
        $this->registration->method('validateOnly')->willReturn([]);
        $this->http->fake(['*' => $this->http->response(['text' => '', 'page_count' => 1])]);
        $extractor = $this->createMock(DocumentMetadataExtractor::class);
        $extractor->expects($this->never())->method('extract');
        $this->registration->fillFromDocument($this->client, $extractor);
        expect($this->registration->ocrError)->toContain('No readable text');
    });

    it('requires both permissions before automatic processing', function (string $ability) {
        $this->authorization->deniedAbility = $ability;
        $extractor = $this->createMock(DocumentMetadataExtractor::class);
        $extractor->expects($this->never())->method('extract');
        $this->registration->updatedAttachment();
        expect(\Livewire\store($this->registration)->get('dispatched', []))->toBe([]);
        expect(fn () => $this->registration->fillFromDocument($this->client, $extractor))->toThrow(HttpException::class);
        $this->http->assertNothingSent();
    })->with(['ai.ocr.use', 'documents.register']);

    it('validates the new metadata fields without requiring unknown values', function (array $data, bool $valid) {
        $rules = (new ReflectionMethod(Registration::class, 'rules'))->invoke($this->registration);
        $validator = new Illuminate\Validation\Factory(new Translator(new ArrayLoader, 'en'));
        expect($validator->make($data, array_intersect_key($rules, array_flip(['document_reference', 'document_date'])))->passes())->toBe($valid);
    })->with([
        [['document_reference' => '', 'document_date' => ''], true],
        [['document_reference' => 'HR/123/2026', 'document_date' => '2026-11-18'], true],
        [['document_reference' => str_repeat('a', 256), 'document_date' => ''], false],
        [['document_reference' => 'HR/123/2026', 'document_date' => '2026-02-30'], false],
    ]);

    it('renders registration and document AI controls without interpreting supplied HTML', function () {
        $application = Container::getInstance();
        $application->instance(Gate::class, new class
        {
            public function check($ability): bool
            {
                return true;
            }
        });
        $application->instance('url', new class
        {
            public function route($name, $parameters = [], $absolute = true): string
            {
                return '/documents';
            }
        });
        $files = new Filesystem;
        $cachePath = sys_get_temp_dir().'/pdts-autofill-'.bin2hex(random_bytes(6));
        $files->makeDirectory($cachePath);
        try {
            $compiler = new BladeCompiler($files, $cachePath);
            $engines = new EngineResolver;
            $engines->register('blade', fn () => new CompilerEngine($compiler, $files));
            $views = new ViewFactory($engines, new FileViewFinder($files, [dirname(__DIR__, 2).'/resources/views']), new Dispatcher);
            $this->registration->applicantDetails = ['name' => '<script>alert(1)</script>', 'man_no' => '001234'];
            $this->registration->autoFillError = '<img src=x onerror=alert(1)>';
            $html = $views->make('livewire.pdts.documents.registration', get_object_vars($this->registration) + [
                'documentTypes' => collect([(object) ['id' => 7, 'name' => 'Memo']]),
                'directorates' => collect([(object) ['id' => 1, 'name' => 'Technology']]),
                'departments' => collect(), 'offices' => collect(),
                'canManage' => ['types' => false, 'directorates' => false, 'departments' => false, 'offices' => false],
                'errors' => new ViewErrorBag,
            ])->render();
            expect($html)->toContain('x-on:registration-uploaded.window=', '$wire.fillFromDocument()', 'Retry extraction')
                ->toContain('wire:model="document_reference"', 'wire:model="document_date"', 'wire:submit.prevent="save"')
                ->toContain('&lt;script&gt;alert(1)&lt;/script&gt;', '&lt;img', '001234')
                ->not->toContain('<script>', '<img', 'wire:model="applicantDetails');
            $panel = new DocumentAiPanel;
            $panel->documentId = 5;
            $idleHtml = $views->make('livewire.pdts.documents.document-ai-panel', get_object_vars($panel) + ['errors' => new ViewErrorBag])->render();
            expect($idleHtml)->toContain('wire:click="analyse"', 'Analyse with AI')->not->toContain('wire:submit.prevent="ask"');
            $panel->summary = ['summary' => ['text' => '<script>alert(1)</script>', 'pages' => [1]], 'key_points' => [], 'requested_actions' => [], 'dates_and_amounts' => []];
            $panel->messages = [['role' => 'assistant', 'text' => '<img src=x onerror=alert(1)>', 'pages' => [1]]];
            $panelHtml = $views->make('livewire.pdts.documents.document-ai-panel', get_object_vars($panel) + ['errors' => new ViewErrorBag])->render();
            expect($panelHtml)->toContain('Source pages: 1', 'wire:submit.prevent="ask"', 'wire:click="clearChat"')
                ->toContain('&lt;script&gt;alert(1)&lt;/script&gt;', '&lt;img', 'wire:loading.attr="disabled"')
                ->not->toContain('<script>', '<img');
            $document = new PdtsDocument(['reference_number' => 'TEST-5', 'title' => '<script>Document title</script>']);
            $scan = new PdtsDocumentAttachment(['original_filename' => 'test.pdf', 'mime_type' => 'application/pdf']);
            $tracking = ['available' => true, 'as_of' => '2026-11-18T12:00:00+02:00', 'status_label' => 'Sent, not received yet',
                'current_custodian' => 'Current holder', 'pending_handover' => null, 'latest_handover' => null];
            $pageData = get_object_vars($panel) + ['document' => $document, 'scan' => $scan, 'tracking' => $tracking, 'errors' => new ViewErrorBag];
            $pageHtml = $views->make('livewire.pdts.documents.document-analysis', $pageData)->render();
            expect($pageHtml)->toContain('Back to document', 'wire:poll.30s.visible', '<iframe', 'Current holder', 'Sent, not received yet')
                ->toContain('&lt;script&gt;Document title&lt;/script&gt;', 'grid-template-columns: minmax(0, 1fr)')
                ->not->toContain('<script>', 'Azure');
            $pageData['tracking'] = ['available' => false];
            $restrictedHtml = $views->make('livewire.pdts.documents.document-analysis', $pageData)->render();
            expect($restrictedHtml)->toContain('Tracking details are not available with your current permissions.')
                ->not->toContain('<iframe', 'Current holder', 'Movement history');
            $showTemplate = $files->get(dirname(__DIR__, 2).'/resources/views/livewire/pdts/documents/show.blade.php');
            expect($showTemplate)->toContain("route('pdts.documents.analysis', \$document)")
                ->not->toContain('<livewire:pdts.documents.document-ai-panel');
        } finally {
            $files->deleteDirectory($cachePath);
        }
    });

    it('reviews a scan while retaining the upload for retry', function () {
        $this->workbench->expects($this->once())->method('validate')->willReturn([]);
        $this->authorization->deniedAbility = 'documents.register';
        $this->http->fake(['*' => $this->http->response([
            'text' => 'Travel', 'page_count' => 1,
            'pages' => [['page' => 1, 'text' => 'Travel', 'source' => 'ocr', 'confidence' => 0.9]],
            'suggestions' => ['suggested_subject' => 'Travel'],
        ])]);

        $this->workbench->analyze($this->client);

        expect($this->workbench->analysis['text'])->toBe('Travel')
            ->and($this->workbench->analysis['pages'][0]['confidence'])->toBe(0.9)
            ->and($this->workbench->analysis['suggestions']['suggested_subject'])->toBe('Travel')
            ->and($this->workbench->uploadedFilename)->toBe('scan.pdf')
            ->and($this->workbench->document)->toBe($this->scan)
            ->and($this->workbench->analysisError)->toBeNull();
    });

    it('clears stale review results and reports unreadable scans', function () {
        $this->workbench->method('validate')->willReturn([]);
        $this->workbench->analysis = ['text' => 'Old result'];
        $this->workbench->uploadedFilename = 'old.pdf';
        $this->http->fake(['*' => $this->http->response(['text' => '', 'page_count' => 1])]);

        $this->workbench->analyze($this->client);

        expect($this->workbench->analysis)->toBeNull()
            ->and($this->workbench->uploadedFilename)->toBe('')
            ->and($this->workbench->analysisError)->toContain('No readable text')
            ->and($this->workbench->document)->toBe($this->scan);
    });

    it('clears review state when the file is replaced or results are cleared', function () {
        $this->workbench->analysis = ['text' => 'Old result'];
        $this->workbench->uploadedFilename = 'old.pdf';
        $this->workbench->analysisError = 'Old error';
        $this->workbench->updatedDocument();

        expect($this->workbench->analysis)->toBeNull()
            ->and($this->workbench->uploadedFilename)->toBe('')
            ->and($this->workbench->analysisError)->toBeNull()
            ->and($this->workbench->document)->toBe($this->scan);

        $this->workbench->clearResults();
        expect($this->workbench->document)->toBeNull();
        $this->http->assertNothingSent();
    });

    it('checks OCR permission before reviewing a scan', function () {
        $this->authorization->deniedAbility = 'ai.ocr.use';
        $this->workbench->expects($this->never())->method('validate');
        expect(fn () => $this->workbench->analyze($this->client))->toThrow(HttpException::class);
        $this->http->assertNothingSent();
    });

    it('formats once while preserving original OCR and metadata', function () {
        $analysis = ['text' => "Original\nOCR text", 'suggestions' => ['dates' => ['19 August 2026']]];
        $blocks = [['type' => 'paragraph', 'text' => 'Original OCR text']];
        $this->workbench->analysis = $analysis;
        $formatter = $this->createMock(OcrTextFormatter::class);
        $formatter->expects($this->once())->method('format')->with($analysis['text'])->willReturn($blocks);

        $this->workbench->formatText($formatter);
        $this->workbench->formatText($formatter);

        expect($this->workbench->formattedBlocks)->toBe($blocks)
            ->and($this->workbench->analysis)->toBe($analysis)
            ->and($this->workbench->formattingError)->toBeNull()
            ->and($this->workbench->document)->toBe($this->scan);
        $this->http->assertNothingSent();
    });

    it('preserves OCR on formatting failure and allows a retry', function () {
        $this->workbench->analysis = ['text' => 'Original text'];
        $formatter = $this->createMock(OcrTextFormatter::class);
        $formatter->method('format')->willThrowException(new RuntimeException('Formatting unavailable'));
        $this->workbench->formatText($formatter);

        expect($this->workbench->formattingError)->toBe('Formatting unavailable')
            ->and($this->workbench->formattedBlocks)->toBe([])
            ->and($this->workbench->analysis['text'])->toBe('Original text');

        $retry = $this->createMock(OcrTextFormatter::class);
        $retry->method('format')->willReturn([['type' => 'paragraph', 'text' => 'Original text']]);
        $this->workbench->formatText($retry);
        expect($this->workbench->formattingError)->toBeNull()
            ->and($this->workbench->formattedBlocks)->not->toBeEmpty();
    });

    it('does not format before extraction or without OCR permission', function () {
        $formatter = $this->createMock(OcrTextFormatter::class);
        $formatter->expects($this->never())->method('format');
        $this->workbench->formatText($formatter);

        $this->workbench->analysis = ['text' => 'Original text'];
        $this->authorization->deniedAbility = 'ai.ocr.use';
        expect(fn () => $this->workbench->formatText($formatter))->toThrow(HttpException::class);
        $this->http->assertNothingSent();
    });

    it('clears AI output and errors when OCR is replaced cleared or rerun', function (string $action) {
        $this->workbench->analysis = ['text' => 'Old text'];
        $this->workbench->formattedBlocks = [['type' => 'paragraph', 'text' => 'Old formatted text']];
        $this->workbench->formattingError = 'Old failure';

        if ($action === 'analyze') {
            $this->workbench->method('validate')->willReturn([]);
            $this->http->fake(['*' => $this->http->response(['text' => 'New text', 'page_count' => 1])]);
            $this->workbench->analyze($this->client);
        } else {
            $this->workbench->{$action}();
        }

        expect($this->workbench->formattedBlocks)->toBe([])
            ->and($this->workbench->formattingError)->toBeNull();
    })->with(['updatedDocument', 'clearResults', 'analyze']);
});
