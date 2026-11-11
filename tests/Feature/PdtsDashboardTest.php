<?php

use App\Livewire\Pdts\Dashboard;
use App\Models\PdtsDocument;
use App\Models\User;
use App\Services\Documents\CustodyService;
use Database\Seeders\PdtsAccessSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(PdtsAccessSeeder::class);
    config(['pdts.overdue_after_days' => 3]);

    $this->custody = app(CustodyService::class);
    $this->sender = User::factory()->create(['name' => 'Sam Sender'])->assignRole('Originating Employee');
    $this->recipient = User::factory()->create(['name' => 'Rita Recipient'])->assignRole('Supervisor');
});

function dashboardDocument(User $holder, array $attributes = []): PdtsDocument
{
    return PdtsDocument::factory()->create(['current_custodian_id' => $holder->id, ...$attributes]);
}

test('shows what is waiting for the recipient, oldest first, with overdue marked', function () {
    $old = dashboardDocument($this->sender, ['reference_number' => 'OMTS-2026-0001']);
    $new = dashboardDocument($this->sender, ['reference_number' => 'OMTS-2026-0002']);

    $this->travelTo(now()->subDays(5));
    $this->custody->forward($old, $this->sender, $this->recipient);
    $this->travelBack();
    $this->custody->forward($new, $this->sender, $this->recipient);

    Livewire::actingAs($this->recipient)
        ->test(Dashboard::class)
        ->assertViewHas('mine', fn (array $mine) => $mine['waitingCount'] === 2
            && $mine['waitingOverdue'] === 1
            && $mine['waitingList']->pluck('document.reference_number')->all() === ['OMTS-2026-0001', 'OMTS-2026-0002'])
        ->assertSee('2 documents are waiting for you to acknowledge.')
        ->assertSeeInOrder(['OMTS-2026-0001', 'Overdue', 'OMTS-2026-0002']);
});

test('shows the sender what they hold and what has not been received yet', function () {
    dashboardDocument($this->sender);
    $sent = dashboardDocument($this->sender, ['reference_number' => 'OMTS-2026-0003']);
    $this->custody->forward($sent, $this->sender, $this->recipient);

    Livewire::actingAs($this->sender)
        ->test(Dashboard::class)
        ->assertViewHas('mine', fn (array $mine) => $mine['custodyCount'] === 2
            && $mine['sentCount'] === 1
            && $mine['sentOverdue'] === 0
            && $mine['registeredOpen'] === 2
            && $mine['registeredTotal'] === 2
            && $mine['waitingCount'] === 0)
        ->assertSee('Nothing is waiting for you to acknowledge.')
        ->assertSee('OMTS-2026-0003');
});

test('a received document leaves the waiting and sent lists', function () {
    $document = dashboardDocument($this->sender);
    $this->custody->acknowledge($this->custody->forward($document, $this->sender, $this->recipient), $this->recipient);

    Livewire::actingAs($this->recipient)
        ->test(Dashboard::class)
        ->assertViewHas('mine', fn (array $mine) => $mine['waitingCount'] === 0 && $mine['custodyCount'] === 1);

    Livewire::actingAs($this->sender)
        ->test(Dashboard::class)
        ->assertViewHas('mine', fn (array $mine) => $mine['sentCount'] === 0 && $mine['custodyCount'] === 0);
});

test('employees do not see the organisation section', function () {
    Livewire::actingAs($this->sender)
        ->test(Dashboard::class)
        ->assertViewHas('organisation', null)
        ->assertDontSee('Across the organisation');
});

test('management sees organisation totals and overdue handovers', function () {
    $auditor = User::factory()->create()->assignRole('Management / Auditor');

    $stuck = dashboardDocument($this->sender, ['reference_number' => 'OMTS-2026-0009']);
    $declined = dashboardDocument($this->sender);

    $this->travelTo(now()->subDays(4));
    $this->custody->forward($stuck, $this->sender, $this->recipient);
    $this->travelBack();
    $this->custody->decline($this->custody->forward($declined, $this->sender, $this->recipient), $this->recipient, 'Wrong office');

    Livewire::actingAs($auditor)
        ->test(Dashboard::class)
        ->assertViewHas('organisation', function (array $org) {
            $byStatus = $org['byStatus']->mapWithKeys(fn ($row) => [$row['status']->value => $row['total']]);

            return $org['total'] === 2
                && $org['open'] === 2
                && $byStatus['awaiting_receipt'] === 1
                && $byStatus['registered'] === 1
                && $org['declined30'] === 1
                && $org['overdueCount'] === 1
                && $org['overdueList']->first()->document->reference_number === 'OMTS-2026-0009';
        })
        ->assertSee('Across the organisation')
        ->assertSee('OMTS-2026-0009');
});

test('the dashboard page loads for every role', function (string $role) {
    $this->actingAs(User::factory()->create()->assignRole($role))
        ->get(route('dashboard'))
        ->assertOk();
})->with(['Originating Employee', 'Supervisor', 'Manager', 'Management / Auditor', 'System Administrator']);
