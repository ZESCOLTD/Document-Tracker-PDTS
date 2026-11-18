<?php

use App\Models\PdtsDocument;
use App\Models\User;
use App\Services\Documents\CustodyService;
use App\Services\Documents\WorkCounts;
use Database\Seeders\PdtsAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PdtsAccessSeeder::class);

    $this->custody = app(CustodyService::class);
    $this->sender = User::factory()->create()->assignRole('Originating Employee');
    $this->recipient = User::factory()->create()->assignRole('Supervisor');
});

test('counts what waits on each user and matches their pages', function () {
    $messenger = User::factory()->create();
    foreach (range(1, 2) as $i) {
        $document = PdtsDocument::factory()->create(['current_custodian_id' => $this->sender->id]);
        $this->custody->forward($document, $this->sender, $this->recipient, null, $messenger);
    }
    PdtsDocument::factory()->create(['current_custodian_id' => $this->sender->id]);

    $counts = new WorkCounts;

    expect($counts->for($this->recipient))->toBe(['pending' => 2, 'custody' => 0, 'deliveries' => 0])
        ->and($counts->for($this->sender))->toBe(['pending' => 0, 'custody' => 3, 'deliveries' => 0])
        ->and($counts->for($messenger))->toBe(['pending' => 0, 'custody' => 0, 'deliveries' => 2]);
});

test('the sidebar shows a count bubble only when something is waiting', function () {
    $document = PdtsDocument::factory()->create(['current_custodian_id' => $this->sender->id]);
    $this->custody->forward($document, $this->sender, $this->recipient);

    $this->actingAs($this->recipient)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('class="pdts-count" data-count="1"', false)
        ->assertSee('<span class="sr-only"> waiting for you</span>', false);

    $this->custody->acknowledge($document->pendingMovement()->first(), $this->recipient);
    app()->forgetScopedInstances(); // a real visit starts each request fresh

    $this->actingAs($this->recipient)->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('<span class="sr-only"> waiting for you</span>', false)
        ->assertSee('<span class="sr-only"> in your custody</span>', false);
});
