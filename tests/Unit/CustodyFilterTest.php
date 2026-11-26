<?php

use App\Livewire\Movements\Custody;
use App\Livewire\Movements\Deliveries;
use App\Livewire\Movements\Pending;
use App\Livewire\Movements\Track;
use App\Livewire\Pdts\Documents\Index as DocumentIndex;
use App\Models\DocumentMovement;
use App\Models\PdtsDocument;
use App\Models\User;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\ViewErrorBag;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;
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
    $guard = $this->createMock(Guard::class);
    $guard->method('id')->willReturn(7);
    $actor = new User;
    $actor->id = 7;
    $guard->method('user')->willReturn($actor);
    Auth::swap($guard);

    $connection = $this->getMockBuilder(Connection::class)->setConstructorArgs([null, 'testing'])->onlyMethods(['select'])->getMock();
    $connection->expects($this->never())->method('select');
    $this->app->instance('db', $connection);
    $resolver = new ConnectionResolver(['testing' => $connection]);
    $resolver->setDefaultConnection('testing');
    Model::setConnectionResolver($resolver);
    $this->panel = new Custody;
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

it('keeps the active view scoped to the current holder and open statuses', function (string $filter) {
    $this->panel->statusFilter = $filter;
    $query = (new ReflectionMethod(Custody::class, 'documentsQuery'))->invoke($this->panel);

    expect($query->getBindings())->toBe([7, 7, 'awaiting_receipt', 'registered', 'awaiting_receipt', 'received'])
        ->and($query->toSql())->toContain('"current_custodian_id" = ?', '"status" in (?, ?, ?)')
        ->toContain('"messenger_id" = ?', '"picked_up_at" is not null');
})->with(['active', 'invalid']);

it('requires completed status and the current users completion audit', function () {
    $this->panel->statusFilter = 'completed';
    $query = (new ReflectionMethod(Custody::class, 'documentsQuery'))->invoke($this->panel);

    expect($query->getBindings())->toBe([7, 7, 'awaiting_receipt', 'completed', 'document_completed', 7])
        ->and($query->toSql())->toContain(
            '"current_custodian_id" = ?',
            '"status" = ?',
            'exists (select * from "pdts_document_audit_logs"',
            '"pdts_documents"."id" = "pdts_document_audit_logs"."pdts_document_id"',
            '"action" = ?',
            '"actor_id" = ?',
        );
});

it('keeps reference and subject searches inside the completed user scope', function () {
    $this->panel->statusFilter = 'completed';
    $this->panel->search = 'Budget';
    $query = (new ReflectionMethod(Custody::class, 'documentsQuery'))->invoke($this->panel);

    expect($query->getBindings())->toBe([7, 7, 'awaiting_receipt', '%budget%', '%budget%', '%budget%', 'completed', 'document_completed', 7])
        ->and($query->toSql())->toContain('and (lower(reference_number) like ? or lower(title) like ? or lower(subject) like ?)');
});

it('resets pagination and closes workflow dialogs without clearing the search', function (string $filter, string $expectedFilter) {
    $this->panel->paginators = ['page' => 3];
    $this->panel->search = 'budget';
    $this->panel->searchInput = 'budget';
    $this->panel->cancellingMovementId = 4;
    $this->panel->returningDocumentId = 9;
    $this->panel->completingDocumentId = 9;
    $this->panel->statusFilter = $filter;
    $this->panel->updatedStatusFilter();

    expect($this->panel->paginators['page'])->toBe(1)
        ->and($this->panel->statusFilter)->toBe($expectedFilter)
        ->and($this->panel->search)->toBe('budget')
        ->and($this->panel->searchInput)->toBe('budget')
        ->and($this->panel->cancellingMovementId)->toBeNull()
        ->and($this->panel->returningDocumentId)->toBeNull()
        ->and($this->panel->completingDocumentId)->toBeNull();
})->with([['completed', 'completed'], ['active', 'active'], ['awaiting_receipt', 'awaiting_receipt'], ['received', 'received'], ['invalid', 'active']]);

it('renders completed documents read only and gives the completed view its own empty state', function (bool $hasDocument) {
    $files = new Filesystem;
    $cache = sys_get_temp_dir().'/pdts-custody-filter-'.bin2hex(random_bytes(6));
    $files->makeDirectory($cache);
    try {
        $compiler = new BladeCompiler($files, $cache);
        $engines = new EngineResolver;
        $engines->register('blade', fn () => new CompilerEngine($compiler));
        $views = new Factory($engines, new FileViewFinder($files, [dirname(__DIR__, 2).'/resources/views']), new Dispatcher($this->app));
        $this->app->instance('view', $views);
        $views->share('errors', new ViewErrorBag);
        $routes = new RouteCollection;
        $routes->add((new Route('GET', 'documents/{document}/history', fn () => null))->name('movements.history'));
        $this->app->instance('url', new UrlGenerator($routes, Request::create('http://localhost')));
        $document = new PdtsDocument(['reference_number' => 'DONE-9', 'title' => 'Completed contract', 'status' => 'completed']);
        $document->id = 9;
        $document->setRelation('documentType', null);
        $document->setRelation('pendingMovement', null);
        $document->setRelation('latestMovement', null);
        $documents = new class($hasDocument ? [$document] : [], $hasDocument ? 1 : 0, 10) extends LengthAwarePaginator
        {
            public function links($view = null, $data = []): string
            {
                return '';
            }
        };
        $html = $views->make('livewire.movements.custody', [
            'documents' => $documents, 'search' => '', 'statusFilter' => 'completed',
            'filterCards' => [
                'active' => ['label' => 'Active documents', 'icon' => 'fa-folder-open', 'count' => 12],
                'completed' => ['label' => 'Completed by me', 'icon' => 'fa-check-circle', 'count' => $hasDocument ? 1 : 0],
            ],
            'notice' => null, 'errorMessage' => null, 'returnTargets' => [],
            'cancellingMovement' => null, 'completingDocument' => null, 'returningDocument' => null,
            'deliveringMovement' => null,
        ])->render();

        expect($html)->toContain('$set(\'statusFilter\', \'completed\')', 'aria-pressed="true"', 'pdts-filter-count', 'Active documents', 'Completed by me')
            ->not->toContain('open-forward', 'openComplete', 'openSendBack', 'openCancel', 'movements.forward-document');
        if ($hasDocument) {
            expect($html)->toContain('DONE-9', 'Completed by you', 'View history', '/documents/9/history')
                ->not->toContain('Not yet forwarded.');
        } else {
            expect($html)->toContain('You have not completed any documents.');
        }
    } finally {
        $files->deleteDirectory($cache);
    }
})->with([true, false]);

describe('messenger delivery filters', function () {
    it('restricts each view to the signed in messengers assignments and the correct movement status', function (string $filter, string $status, string $ordering) {
        $panel = new Deliveries;
        $panel->statusFilter = $filter;
        $query = (new ReflectionMethod(Deliveries::class, 'deliveriesQuery'))->invoke($panel);

        expect($query->getBindings())->toBe([7, $status])
            ->and($query->toSql())->toContain(
                'where "messenger_id" = ? and exists',
                '"pdts_document_movements"."pdts_document_id" = "pdts_documents"."id"',
                'and "status" = ?',
                $ordering,
            )->not->toContain('current_custodian_id');
    })->with([
        ['active', 'awaiting_receipt', 'order by "forwarded_at" asc'],
        ['completed', 'acknowledged', 'order by "acknowledged_at" desc, "id" desc'],
        ['invalid', 'awaiting_receipt', 'order by "forwarded_at" asc'],
    ]);

    it('resets the page and closes pickup confirmation when switching delivery filters', function (string $filter, string $expectedFilter) {
        $panel = new Deliveries;
        $panel->paginators = ['page' => 3];
        $panel->pickupMovementId = 4;
        $panel->statusFilter = $filter;
        $panel->updatedStatusFilter();

        expect($panel->paginators['page'])->toBe(1)
            ->and($panel->pickupMovementId)->toBeNull()
            ->and($panel->statusFilter)->toBe($expectedFilter);
    })->with([['completed', 'completed'], ['active', 'active'], ['awaiting-pickup', 'awaiting-pickup'], ['in-transit', 'in-transit'], ['invalid', 'active']]);

    it('renders delivery states with pickup controls only for uncollected active deliveries', function (string $filter, bool $hasDelivery, bool $pickedUp) {
        $files = new Filesystem;
        $cache = sys_get_temp_dir().'/pdts-delivery-filter-'.bin2hex(random_bytes(6));
        $files->makeDirectory($cache);
        try {
            $compiler = new BladeCompiler($files, $cache);
            $engines = new EngineResolver;
            $engines->register('blade', fn () => new CompilerEngine($compiler));
            $views = new Factory($engines, new FileViewFinder($files, [dirname(__DIR__, 2).'/resources/views']), new Dispatcher($this->app));
            $this->app->instance('view', $views);
            $routes = new RouteCollection;
            $routes->add((new Route('GET', 'documents/{document}/history', fn () => null))->name('movements.history'));
            $routes->add((new Route('GET', 'movements/custody', fn () => null))->name('movements.custody'));
            $this->app->instance('url', new UrlGenerator($routes, Request::create('http://localhost')));
            $document = new PdtsDocument(['reference_number' => 'DELIVERY-9', 'title' => 'Delivered contract']);
            $document->id = 9;
            $delivery = (new DocumentMovement)->setDateFormat('Y-m-d H:i:s');
            $delivery->fill([
                'status' => $filter === 'completed' ? 'acknowledged' : 'awaiting_receipt',
                'picked_up_at' => $pickedUp ? '2026-11-20 09:00:00' : null,
                'acknowledged_at' => $filter === 'completed' ? '2026-11-20 10:30:00' : null,
            ]);
            $delivery->id = 4;
            $delivery->setRelation('document', $document);
            $delivery->setRelation('sender', new User(['name' => 'Test Sender']));
            $delivery->setRelation('recipient', new User(['name' => 'Test Recipient']));
            $deliveries = new class($hasDelivery ? [$delivery] : [], $hasDelivery ? 1 : 0, 10) extends LengthAwarePaginator
            {
                public function links($view = null, $data = []): string
                {
                    return '';
                }
            };
            $html = $views->make('livewire.movements.deliveries', [
                'deliveries' => $deliveries, 'statusFilter' => $filter,
                'filterCards' => [
                    'active' => ['label' => 'Active deliveries', 'icon' => 'fa-truck', 'count' => 3],
                    'completed' => ['label' => 'Completed deliveries', 'icon' => 'fa-check-circle', 'count' => 9],
                ],
                'pickupNotice' => null, 'pickupError' => null, 'pickupMovement' => null,
            ])->render();

            expect($html)->toContain('$set(\'statusFilter\', \'completed\')', 'pdts-filter-count', 'Active deliveries', 'Completed deliveries');
            if ($hasDelivery) {
                expect($html)->toContain('DELIVERY-9', '/documents/9/history', 'Test Sender', 'Test Recipient');
                if ($filter === 'completed') {
                    expect($html)->toContain('Received', '20 Nov 2026 10:30')->not->toContain('Picked up');
                } elseif ($pickedUp) {
                    expect($html)->toContain('Picked up', '20 Nov 2026 09:00');
                } else {
                    expect($html)->toContain('wire:click="openPickup(4)"', 'Confirm pickup');
                }
            } else {
                expect($html)->toContain($filter === 'completed' ? 'You have no completed deliveries.' : 'No deliveries are currently assigned to you.');
            }
            if ($filter === 'completed' || $pickedUp || ! $hasDelivery) {
                expect($html)->not->toContain('openPickup', 'confirmPickup', 'Confirm pickup');
            }
        } finally {
            $files->deleteDirectory($cache);
        }
    })->with([
        ['active', true, false],
        ['active', true, true],
        ['completed', true, true],
        ['completed', true, false],
        ['completed', false, false],
        ['active', false, false],
    ]);
});

describe('workflow counter cards', function () {
    it('uses the requested card scope for counts independently of the selected card', function (string $component, string $method, string $property, string $selected, string $filter, array $bindings, string $condition) {
        $panel = new $component;
        $panel->{$property} = $selected;
        $query = (new ReflectionMethod($component, $method))->invoke($panel, $filter);

        expect($query->getBindings())->toBe($bindings)
            ->and($query->toSql())->toContain($condition)
            ->and($panel->{$property})->toBe($selected);
    })->with([
        [Custody::class, 'documentsQuery', 'statusFilter', 'completed', 'active', [7, 7, 'awaiting_receipt', 'registered', 'awaiting_receipt', 'received'], '"current_custodian_id" = ?'],
        [Custody::class, 'documentsQuery', 'statusFilter', 'active', 'awaiting_receipt', [7, 7, 'awaiting_receipt', 'awaiting_receipt'], '"status" = ?'],
        [Custody::class, 'documentsQuery', 'statusFilter', 'active', 'received', [7, 7, 'awaiting_receipt', 'received'], '"status" = ?'],
        [Custody::class, 'documentsQuery', 'statusFilter', 'active', 'completed', [7, 7, 'awaiting_receipt', 'completed', 'document_completed', 7], '"actor_id" = ?'],
        [Custody::class, 'documentsQuery', 'statusFilter', 'active', 'to-deliver', [7, 7, 'awaiting_receipt', 7, 'awaiting_receipt'], '"delivered_at" is null'],
        [Custody::class, 'documentsQuery', 'statusFilter', 'active', 'delivered', [7, 7, 'awaiting_receipt', 7, 'awaiting_receipt'], '"delivered_at" is not null'],
        [Deliveries::class, 'deliveriesQuery', 'statusFilter', 'completed', 'active', [7, 'awaiting_receipt'], '"messenger_id" = ?'],
        [Deliveries::class, 'deliveriesQuery', 'statusFilter', 'active', 'awaiting-pickup', [7, 'awaiting_receipt'], '"picked_up_at" is null'],
        [Deliveries::class, 'deliveriesQuery', 'statusFilter', 'active', 'in-transit', [7, 'awaiting_receipt'], '"picked_up_at" is not null'],
        [Deliveries::class, 'deliveriesQuery', 'statusFilter', 'active', 'awaiting-receipt', [7, 'awaiting_receipt'], '"delivered_at" is not null'],
        [Deliveries::class, 'deliveriesQuery', 'statusFilter', 'active', 'completed', [7, 'acknowledged'], '"status" = ?'],
        [Track::class, 'documentsQuery', 'tab', 'completed', 'all', [7], '"registered_by" = ?'],
        [Track::class, 'documentsQuery', 'tab', 'all', 'in-progress', [7, 'registered', 'awaiting_receipt', 'received'], '"status" in (?, ?, ?)'],
        [Track::class, 'documentsQuery', 'tab', 'all', 'completed', [7, 'completed'], '"status" = ?'],
        [DocumentIndex::class, 'documentsQuery', 'statusFilter', 'completed', 'all', [], 'select * from "pdts_documents"'],
        [DocumentIndex::class, 'documentsQuery', 'statusFilter', 'all', 'registered', ['registered'], '"status" = ?'],
        [DocumentIndex::class, 'documentsQuery', 'statusFilter', 'all', 'awaiting_receipt', ['awaiting_receipt'], '"status" = ?'],
        [DocumentIndex::class, 'documentsQuery', 'statusFilter', 'all', 'received', ['received'], '"status" = ?'],
        [DocumentIndex::class, 'documentsQuery', 'statusFilter', 'all', 'completed', ['completed'], '"status" = ?'],
        [DocumentIndex::class, 'documentsQuery', 'statusFilter', 'completed', 'invalid', [], 'select * from "pdts_documents"'],
    ]);

    it('applies search to both document rows and counter queries', function (string $component) {
        $panel = new $component;
        $panel->search = 'budget';
        $query = (new ReflectionMethod($component, 'documentsQuery'))->invoke($panel, 'completed');

        expect($query->getBindings())->toContain('%budget%', 'completed')
            ->and($query->toSql())->toContain(') and "status" = ?');
        if ($component === Track::class) {
            expect($query->getBindings())->toContain(7)
                ->and($query->toSql())->toContain('"registered_by" = ?');
        }
    })->with([[Track::class], [DocumentIndex::class]]);

    it('keeps acknowledgement counters within recipient and active delegation permissions', function (string $filter, ?string $condition) {
        $panel = new Pending;
        $panel->statusFilter = 'ready';
        $query = (new ReflectionMethod(Pending::class, 'movementsQuery'))->invoke($panel, $filter);

        expect($query->toSql())->toContain(
            '("recipient_id" = ? or "recipient_id" in (select "principal_user_id"',
            '"delegate_user_id" = ?',
            '"revoked_at" is null',
            '"starts_at" <= ?',
            '"expires_at" > ?',
            'and "status" = ?',
            'and ("messenger_id" is null or "delivered_at" is not null)',
            'exists (select * from "pdts_documents"',
        )->and($query->getBindings())->toContain(7, 'awaiting_receipt');
        if ($condition) {
            expect($query->toSql())->toContain($condition);
        } else {
            expect($query->toSql())->not->toContain('"picked_up_at"');
        }
    })->with([
        ['all', null],
        ['invalid', null],
        ['ready', 'and "messenger_id" is null'],
        ['delivered', 'and "messenger_id" is not null and "delivered_at" is not null'],
    ]);

    it('resets acknowledgement pagination and closes stale dialogs', function (string $filter, string $expectedFilter) {
        $panel = new Pending;
        $panel->paginators = ['page' => 3];
        $panel->dialogMovementId = 4;
        $panel->dialogMode = 'acknowledge';
        $panel->statusFilter = $filter;
        $panel->updatedStatusFilter();

        expect($panel->paginators['page'])->toBe(1)
            ->and($panel->statusFilter)->toBe($expectedFilter)
            ->and($panel->dialogMovementId)->toBeNull()
            ->and($panel->dialogMode)->toBeNull();
    })->with([['ready', 'ready'], ['delivered', 'delivered'], ['all', 'all'], ['invalid', 'all']]);

    it('resets register and tracking pagination while retaining the search', function (string $component, string $property, string $hook, string $filter, string $expectedFilter) {
        $panel = new $component;
        $panel->paginators = ['page' => 3];
        $panel->search = 'budget';
        $panel->{$property} = $filter;
        $panel->{$hook}();

        expect($panel->paginators['page'])->toBe(1)
            ->and($panel->search)->toBe('budget')
            ->and($panel->{$property})->toBe($expectedFilter);
    })->with([
        [Track::class, 'tab', 'updatedTab', 'completed', 'completed'],
        [Track::class, 'tab', 'updatedTab', 'invalid', 'all'],
        [DocumentIndex::class, 'statusFilter', 'updatedStatusFilter', 'received', 'received'],
        [DocumentIndex::class, 'statusFilter', 'updatedStatusFilter', 'invalid', 'all'],
    ]);

    it('renders formatted counts and exactly one selected accessible card', function (string $selected, string $property) {
        $files = new Filesystem;
        $cache = sys_get_temp_dir().'/pdts-counter-cards-'.bin2hex(random_bytes(6));
        $files->makeDirectory($cache);
        try {
            $compiler = new BladeCompiler($files, $cache);
            $engines = new EngineResolver;
            $engines->register('blade', fn () => new CompilerEngine($compiler));
            $views = new Factory($engines, new FileViewFinder($files, [dirname(__DIR__, 2).'/resources/views']), new Dispatcher($this->app));
            $this->app->instance('view', $views);
            $html = $views->make('livewire.movements.partials.filter-cards', [
                'statusFilter' => $selected, 'filterProperty' => $property,
                'filterCards' => [
                    'all' => ['label' => 'All documents', 'icon' => 'fa-layer-group', 'count' => 12345],
                    'completed' => ['label' => 'Completed', 'icon' => 'fa-check-circle', 'count' => 0],
                ],
            ])->render();
            $document = new DOMDocument;
            $document->loadHTML($html);
            $xpath = new DOMXPath($document);
            $selectedButtons = $xpath->query('//button[@aria-pressed="true"]');

            expect($selectedButtons->length)->toBe(1)
                ->and(trim($xpath->query('//*[@data-filter-count="all"]')->item(0)->textContent))->toBe('12,345')
                ->and(trim($xpath->query('//*[@data-filter-count="completed"]')->item(0)->textContent))->toBe('0')
                ->and($selectedButtons->item(0)->textContent)->toContain($selected === 'completed' ? 'Completed' : 'All documents')
                ->and($html)->toContain('$set(\''.$property.'\', \'completed\')', 'role="group"', 'type="button"');
        } finally {
            $files->deleteDirectory($cache);
        }
    })->with([['completed', 'statusFilter'], ['all', 'tab'], ['invalid', 'statusFilter']]);
});
