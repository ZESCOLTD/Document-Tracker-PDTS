<?php

use App\Livewire\Movements\Pending;
use App\Models\DocumentMovement;
use App\Models\PdtsDocument;
use App\Models\User;
use App\Services\Documents\CustodyException;
use App\Services\Documents\CustodyService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\ViewErrorBag;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;
use Livewire\Attributes\Locked;
use Livewire\Mechanisms\DataStore;

beforeEach(function () {
    $this->previousContainer = Container::getInstance();
    $this->previousFacadeApp = Facade::getFacadeApplication();
    $this->previousResolver = Model::getConnectionResolver();
    $this->app = new Application(dirname(__DIR__, 2));
    $this->app->instance('config', new Repository);
    $this->app->singleton(DataStore::class);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($this->app);
    $this->actor = $this->getMockBuilder(User::class)->onlyMethods(['can'])->getMock();
    $this->actor->id = 7;
    $this->actor->method('can')->willReturnCallback(fn ($permission) => $permission === 'documents.acknowledge');
    $guard = $this->createMock(Guard::class);
    $guard->method('user')->willReturn($this->actor);
    $guard->method('id')->willReturn(7);
    Auth::swap($guard);

    $this->movementAvailable = true;
    $this->connection = $this->getMockBuilder(Connection::class)->setConstructorArgs([null, 'testing'])->onlyMethods(['select'])->getMock();
    $this->connection->method('select')->willReturnCallback(function (string $query) {
        if (str_contains($query, 'document_movements')) {
            return $this->movementAvailable ? [(object) ['id' => 4, 'pdts_document_id' => 9, 'recipient_id' => 7, 'status' => 'awaiting_receipt']] : [];
        }
        if (str_contains($query, 'pdts_documents')) {
            return [(object) ['id' => 9, 'reference_number' => 'TEST-9', 'title' => 'Test document']];
        }
        throw new LogicException('Unexpected query in isolated acknowledgement test.');
    });
    $resolver = new ConnectionResolver(['testing' => $this->connection]);
    $resolver->setDefaultConnection('testing');
    Model::setConnectionResolver($resolver);
    $this->panel = new Pending;
    $this->custody = $this->createMock(CustodyService::class);
});

afterEach(function () {
    if ($this->previousResolver) {
        Model::setConnectionResolver($this->previousResolver);
    } else {
        Model::unsetConnectionResolver();
    }
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($this->previousFacadeApp);
    Container::setInstance($this->previousContainer);
});

it('acknowledges through the custody service without AI access or a scan upload', function () {
    expect($this->actor->can('ai.ocr.use'))->toBeFalse();
    $this->custody->expects($this->once())->method('acknowledge')
        ->with($this->callback(fn ($movement) => $movement->id === 4), $this->actor)
        ->willReturnArgument(0);

    $this->panel->openAcknowledge(4);
    $this->panel->acknowledge($this->custody);

    expect($this->panel->notice)->toBe('You are now the custodian of TEST-9.')
        ->and($this->panel->errorMessage)->toBeNull()
        ->and($this->panel->dialogMovementId)->toBeNull()
        ->and(property_exists($this->panel, 'receivedCopy'))->toBeFalse();
});

it('displays custody safeguards rather than reporting a successful receipt', function (string $message) {
    $this->custody->expects($this->once())->method('acknowledge')->willThrowException(new CustodyException($message));
    $this->panel->openAcknowledge(4);
    $this->panel->acknowledge($this->custody);

    expect($this->panel->notice)->toBeNull()->and($this->panel->errorMessage)->toBe($message);
})->with([
    'You do not have permission to acknowledge documents.',
    'Only the intended recipient or an authorized delegate can acknowledge this document.',
    'The messenger must confirm pickup before receipt can be acknowledged.',
    'This handover is no longer available.',
]);

it('does not acknowledge a missing handover', function () {
    $this->movementAvailable = false;
    $this->custody->expects($this->never())->method('acknowledge');
    $this->panel->openAcknowledge(4);
    $this->panel->acknowledge($this->custody);

    expect($this->panel->errorMessage)->toBe('This handover is no longer available.');
});

it('opens and cancels confirmation without querying or changing custody', function () {
    $this->connection->expects($this->never())->method('select');
    $this->custody->expects($this->never())->method('acknowledge');
    $this->panel->openAcknowledge(4);
    expect($this->panel->dialogMode)->toBe('acknowledge')->and($this->panel->dialogMovementId)->toBe(4);
    $this->panel->closeDialog();
    expect($this->panel->dialogMovementId)->toBeNull()->and($this->panel->dialogMode)->toBeNull();
});

it('keeps dialog identifiers locked against client edits', function (string $property) {
    expect((new ReflectionProperty(Pending::class, $property))->getAttributes(Locked::class))->toHaveCount(1);
})->with(['dialogMovementId', 'dialogMode']);

it('renders physical receipt confirmation without upload or comparison controls', function (int $recipientId) {
    $files = new Filesystem;
    $cache = sys_get_temp_dir().'/pdts-manual-receipt-'.bin2hex(random_bytes(6));
    $files->makeDirectory($cache);
    try {
        $compiler = new BladeCompiler($files, $cache);
        $engines = new EngineResolver;
        $engines->register('blade', fn () => new CompilerEngine($compiler));
        $views = new Factory($engines, new FileViewFinder($files, [dirname(__DIR__, 2).'/resources/views']), new Dispatcher($this->app));
        $this->app->instance('view', $views);
        $views->share('errors', new ViewErrorBag);
        $document = new PdtsDocument(['reference_number' => 'TEST-9', 'title' => '<script>example</script>']);
        $movement = new DocumentMovement(['recipient_id' => $recipientId]);
        $movement->setRelation('document', $document);
        $movement->setRelation('sender', new User(['name' => 'Test Sender']));
        $movement->setRelation('recipient', new User(['name' => 'Test Recipient']));
        $movements = new class extends Collection
        {
            public function links(): string
            {
                return '';
            }
        };
        $html = $views->make('livewire.movements.pending', [
            'movements' => $movements, 'dialogMovement' => $movement, 'dialogMode' => 'acknowledge',
            'statusFilter' => 'all',
            'filterCards' => ['all' => ['label' => 'Awaiting receipt', 'icon' => 'fa-inbox', 'count' => 0]],
            'notice' => null, 'errorMessage' => null,
        ])->render();

        expect($html)->toContain('Yes, I have it', 'Not yet', 'wire:click="acknowledge"', 'I confirm I physically have this document', '&lt;script&gt;')
            ->not->toContain('type="file"', 'Compare documents', 'receivedCopy', '<script>');
        if ($recipientId !== 7) {
            expect($html)->toContain('as an authorized delegate for Test Recipient');
        }
    } finally {
        $files->deleteDirectory($cache);
    }
})->with([7, 8]);
