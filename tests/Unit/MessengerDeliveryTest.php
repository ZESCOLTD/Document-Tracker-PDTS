<?php

use App\Enums\MovementStatus;
use App\Models\DocumentMovement;
use App\Models\PdtsDocument;
use App\Models\PdtsDocumentAuditLog;
use App\Models\User;
use App\Notifications\DocumentHandoverNotification;
use App\Services\Documents\CustodyException;
use App\Services\Documents\CustodyService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Events\Dispatcher as EventDispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\ViewErrorBag;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;

beforeEach(function () {
    $this->previousContainer = Container::getInstance();
    $this->previousFacadeApp = Facade::getFacadeApplication();
    $this->previousResolver = Model::getConnectionResolver();
    Model::unsetConnectionResolver();
    $this->app = new Application(dirname(__DIR__, 2));
    $this->app->instance('config', new Repository);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($this->app);
    $database = Mockery::mock(DatabaseManager::class);
    $database->shouldReceive('transaction')->andReturnUsing(fn ($callback) => $callback());
    DB::swap($database);

    $this->sent = [];
    $notifications = $this->createMock(Dispatcher::class);
    $notifications->method('send')->willReturnCallback(function ($users, $notification) {
        $this->sent[] = ['users' => array_map(fn ($user) => $user->id, $users), 'event' => $notification->event];
    });
    Notification::swap($notifications);

    $this->messenger = $this->getMockBuilder(User::class)->onlyMethods(['can', 'officeName'])->getMock();
    $this->messenger->id = 7;
    $this->messenger->method('can')->willReturnCallback(fn ($permission) => $permission === 'deliveries.view');
    $this->messenger->method('officeName')->willReturn('Messenger office');
    $this->recipient = $this->getMockBuilder(User::class)->onlyMethods(['can', 'officeName'])->getMock();
    $this->recipient->id = 2;
    $this->recipient->method('can')->willReturn(true);
    $this->recipient->method('officeName')->willReturn('Receiving office');
    $this->sender = new User;
    $this->sender->id = 1;

    $this->document = $this->getMockBuilder(PdtsDocument::class)->onlyMethods(['update', 'auditLogs', 'movements'])->getMock();
    $this->document->setDateFormat('Y-m-d H:i:s');
    $this->document->id = 9;
    $this->document->fill(['status' => 'awaiting_receipt', 'current_custodian_id' => 1]);
    $this->document->method('update')->willReturnCallback(function (array $attributes) {
        $this->document->fill($attributes);

        return true;
    });
    $this->audits = [];
    $audits = Mockery::mock(HasMany::class);
    $audits->shouldReceive('create')->andReturnUsing(function (array $attributes) {
        $this->audits[] = $attributes;

        return new PdtsDocumentAuditLog;
    });
    $this->document->method('auditLogs')->willReturn($audits);
    $this->returnAttributes = null;
    $movements = Mockery::mock(HasMany::class);
    $movements->shouldReceive('where')->andReturnSelf();
    $movements->shouldReceive('exists')->andReturn(false);
    $movements->shouldReceive('create')->andReturnUsing(function (array $attributes) {
        $this->returnAttributes = $attributes;
        $return = (new DocumentMovement)->setDateFormat('Y-m-d H:i:s');
        $return->fill($attributes);
        $return->id = 5;
        $return->setRelation('messenger', $this->messenger);
        $this->document->setRelation('pendingMovement', $return);

        return $return;
    });
    $this->document->method('movements')->willReturn($movements);

    $this->movement = $this->getMockBuilder(DocumentMovement::class)->onlyMethods(['update'])->getMock();
    $this->movement->setDateFormat('Y-m-d H:i:s');
    $this->movement->id = 4;
    $this->movement->fill(['pdts_document_id' => 9, 'sender_id' => 1, 'recipient_id' => 2, 'messenger_id' => 7, 'status' => 'awaiting_receipt', 'open_document_id' => 9]);
    $this->movement->setRelation('sender', $this->sender);
    $this->movement->setRelation('recipient', $this->recipient);
    $this->movement->setRelation('messenger', $this->messenger);
    $this->movement->setRelation('document', $this->document);
    $this->movement->method('update')->willReturnCallback(function (array $attributes) {
        $this->movement->fill($attributes);

        return true;
    });
    $this->eligible = true;
    $eligible = $this->createMock(Builder::class);
    $eligible->method('whereKey')->willReturnSelf();
    $eligible->method('__call')->willReturnCallback(fn ($method) => $method === 'exists' ? $this->eligible : null);
    $this->custody = $this->getMockBuilder(CustodyService::class)->onlyMethods(['lockPending', 'activeDelegates', 'eligibleMessengers'])->getMock();
    $this->custody->method('lockPending')->willReturnCallback(function () {
        if (! $this->movement->isPending()) {
            throw new CustodyException('This handover is no longer awaiting receipt.');
        }

        return [$this->document, $this->movement];
    });
    $this->custody->method('activeDelegates')->willReturn(collect());
    $this->custody->method('eligibleMessengers')->willReturn($eligible);
});

afterEach(function () {
    if ($this->previousResolver) {
        Model::setConnectionResolver($this->previousResolver);
    }
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($this->previousFacadeApp);
    Container::setInstance($this->previousContainer);
});

it('moves pickup into messenger custody without marking it delivered or notifying the recipient', function () {
    $this->custody->confirmPickup($this->movement, $this->messenger);

    expect($this->document->current_custodian_id)->toBe(7)
        ->and($this->movement->picked_up_at)->not->toBeNull()
        ->and($this->movement->delivered_at)->toBeNull()
        ->and($this->movement->status)->toBe(MovementStatus::AwaitingReceipt)
        ->and($this->sent)->toBe([['users' => [1], 'event' => DocumentHandoverNotification::PICKED_UP]])
        ->and($this->audits[0]['action'])->toBe('handover_picked_up');
});

it('records delivery separately and transfers custody only after recipient acknowledgement', function () {
    $this->custody->confirmPickup($this->movement, $this->messenger);
    $this->custody->confirmDelivery($this->movement, $this->messenger);

    expect($this->document->current_custodian_id)->toBe(7)
        ->and($this->movement->delivered_at)->not->toBeNull()
        ->and($this->movement->acknowledged_at)->toBeNull()
        ->and($this->sent[1])->toBe(['users' => [2], 'event' => DocumentHandoverNotification::DELIVERED])
        ->and($this->audits[1]['action'])->toBe('handover_delivered');

    $this->custody->acknowledge($this->movement, $this->recipient);
    expect($this->document->current_custodian_id)->toBe(2)
        ->and($this->document->status)->toBe('received')
        ->and($this->movement->status)->toBe(MovementStatus::Acknowledged)
        ->and($this->movement->open_document_id)->toBeNull();
});

it('blocks acknowledgement until explicit delivery', function () {
    $this->custody->confirmPickup($this->movement, $this->messenger);
    expect(fn () => $this->custody->acknowledge($this->movement, $this->recipient))
        ->toThrow(CustodyException::class, 'must confirm delivery');
    expect($this->document->current_custodian_id)->toBe(7)->and($this->movement->acknowledged_at)->toBeNull();
});

it('rejects invalid delivery attempts without changing custody', function (string $scenario, string $message) {
    $this->movement->picked_up_at = now();
    $this->document->current_custodian_id = 7;
    match ($scenario) {
        'wrong-user' => $this->movement->messenger_id = 8,
        'inactive' => $this->eligible = false,
        'uncollected' => $this->movement->picked_up_at = null,
        'duplicate' => $this->movement->delivered_at = now(),
        'closed' => $this->movement->status = MovementStatus::Cancelled,
        'other-holder' => $this->document->current_custodian_id = 11,
    };
    $holder = $this->document->current_custodian_id;
    expect(fn () => $this->custody->confirmDelivery($this->movement, $this->messenger))->toThrow(CustodyException::class, $message);
    expect($this->document->current_custodian_id)->toBe($holder)->and($this->sent)->toBe([])->and($this->audits)->toBe([]);
})->with([
    ['wrong-user', 'Only the assigned active messenger'], ['inactive', 'Only the assigned active messenger'],
    ['uncollected', 'Confirm pickup'], ['duplicate', 'already been confirmed'],
    ['closed', 'no longer awaiting receipt'], ['other-holder', 'no longer in this delivery workflow'],
]);

it('supports delivery of a previously picked up assignment without rewriting its history', function () {
    $this->movement->picked_up_at = now();
    $this->custody->confirmDelivery($this->movement, $this->messenger);
    expect($this->document->current_custodian_id)->toBe(7)
        ->and(array_column($this->audits, 'action'))->toBe(['handover_delivered']);
});

it('does not cancel documents already in messenger custody', function () {
    $this->custody->confirmPickup($this->movement, $this->messenger);
    expect($this->custody->canCancel($this->movement, $this->sender))->toBeFalse();
    expect(fn () => $this->custody->cancel($this->movement, $this->sender, 'Changed destination'))->toThrow(CustodyException::class, 'cannot be cancelled');
    expect($this->document->current_custodian_id)->toBe(7)->and($this->movement->status)->toBe(MovementStatus::AwaitingReceipt);
});

it('keeps declined delivered documents in messenger custody and creates an explicit return assignment', function () {
    $this->custody->confirmPickup($this->movement, $this->messenger);
    $this->custody->confirmDelivery($this->movement, $this->messenger);
    $this->custody->decline($this->movement, $this->recipient, 'Wrong document');

    expect($this->document->current_custodian_id)->toBe(7)
        ->and($this->document->status)->toBe('awaiting_receipt')
        ->and($this->movement->status)->toBe(MovementStatus::Declined)
        ->and($this->returnAttributes['recipient_id'])->toBe(1)
        ->and($this->returnAttributes['messenger_id'])->toBe(7)
        ->and($this->returnAttributes['open_document_id'])->toBe(9)
        ->and($this->document->pendingMovement->delivered_at)->toBeNull();
});

it('preserves direct handovers without a messenger', function () {
    $this->movement->messenger_id = null;
    $this->custody->acknowledge($this->movement, $this->recipient);
    expect($this->document->current_custodian_id)->toBe(2)->and($this->movement->status)->toBe(MovementStatus::Acknowledged);
});

it('lets a messenger view custody without granting forwarding permission', function () {
    expect($this->messenger->can('documents.forward'))->toBeFalse()
        ->and($this->custody->canViewCustody($this->messenger))->toBeTrue();
});

it('shows delivery only in messenger custody and waits for acknowledgement after delivery', function (string $stage) {
    $guard = $this->createMock(Guard::class);
    $guard->method('id')->willReturn(7);
    $guard->method('user')->willReturn($this->messenger);
    Auth::swap($guard);
    $this->movement->picked_up_at = now();
    $this->movement->delivered_at = $stage === 'delivered' ? now() : null;
    $this->document->current_custodian_id = 7;
    $this->document->reference_number = 'PDTS-TEST-9';
    $this->document->setRelation('documentType', null);
    $this->document->setRelation('latestMovement', $this->movement);
    $this->document->setRelation('pendingMovement', $this->movement);
    $documents = new class([$this->document], 1, 10) extends LengthAwarePaginator
    {
        public function links($view = null, $data = []): string
        {
            return '';
        }
    };
    $files = new Filesystem;
    $cache = sys_get_temp_dir().'/pdts-delivery-dialog-'.bin2hex(random_bytes(6));
    $files->makeDirectory($cache);
    try {
        $compiler = new BladeCompiler($files, $cache);
        $engines = new EngineResolver;
        $engines->register('blade', fn () => new CompilerEngine($compiler));
        $views = new Factory($engines, new FileViewFinder($files, [dirname(__DIR__, 2).'/resources/views']), new EventDispatcher($this->app));
        $this->app->instance('view', $views);
        $views->share('errors', new ViewErrorBag);
        $html = $views->make('livewire.movements.custody', [
            'documents' => $documents, 'search' => '', 'statusFilter' => 'active',
            'filterCards' => ['active' => ['label' => 'Active documents', 'icon' => 'fa-folder-open', 'count' => 1]],
            'notice' => null, 'errorMessage' => null, 'returnTargets' => [],
            'cancellingMovement' => null, 'completingDocument' => null, 'returningDocument' => null,
            'deliveringMovement' => $stage === 'confirmation' ? $this->movement : null,
        ])->render();

        expect($html)->toContain('PDTS-TEST-9')->not->toContain('openCancel', 'openComplete', 'open-forward');
        if ($stage === 'delivered') {
            expect($html)->toContain('Awaiting recipient acknowledgement')->not->toContain('openDelivery');
        } else {
            expect($html)->toContain('wire:click="openDelivery(4)"', 'Picked up; not delivered');
        }
        if ($stage === 'confirmation') {
            expect($html)->toContain('wire:submit="deliver"', 'Have you physically delivered this document', 'You remain responsible until the recipient acknowledges receipt.');
        }
    } finally {
        $files->deleteDirectory($cache);
    }
})->with(['picked-up', 'delivered', 'confirmation']);
