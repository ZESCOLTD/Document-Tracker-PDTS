<?php

use App\Livewire\Pdts\Documents\Show;
use App\Models\PdtsDocument;
use App\Models\User;
use App\Services\Documents\CustodyService;
use Database\Seeders\PdtsAccessSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(PdtsAccessSeeder::class);
    $this->sender = User::factory()->create(['name' => 'Sam Sender'])->assignRole('Originating Employee');
    $this->recipient = User::factory()->create(['name' => 'Rita Recipient'])->assignRole('Supervisor');
    $this->document = PdtsDocument::factory()->create([
        'current_custodian_id' => $this->sender->id,
        'reference_number' => 'OMTS-2026-0042',
        'title' => 'Budget request',
    ]);
    $this->document->auditLogs()->create(['actor_id' => $this->sender->id, 'occurred_at' => now(), 'action' => 'registered', 'details' => []]);
});

test('shows who holds the document and lets the custodian forward it', function () {
    Livewire::actingAs($this->sender)
        ->test(Show::class, ['document' => $this->document])
        ->assertSee('OMTS-2026-0042')
        ->assertSee('Not sent · with you since')
        ->assertViewHas('canForward', true)
        ->assertSeeLivewire('movements.forward-document');
});

test('someone who does not hold it cannot forward it from here', function () {
    // Copied on it: they see it, but it is not theirs to send on.
    $this->document->update(['cc_user_ids' => [$this->recipient->id]]);

    Livewire::actingAs($this->recipient)
        ->test(Show::class, ['document' => $this->document->fresh()])
        ->assertViewHas('canForward', false)
        ->assertDontSeeLivewire('movements.forward-document');
});

test('a pending handover shows where it is going and appears in the activity', function () {
    app(CustodyService::class)->forward($this->document, $this->sender, $this->recipient, 'Please sign');

    Livewire::actingAs($this->sender)
        ->test(Show::class, ['document' => $this->document])
        ->assertSee('On its way to Rita Recipient')
        ->assertSee('You stay responsible until it is acknowledged.')
        ->assertSeeInOrder(['Forwarded', 'to Rita Recipient', 'by Sam Sender', 'Please sign', 'Registered']);
});

test('a declined handover shows its reason', function () {
    $custody = app(CustodyService::class);
    $custody->decline($custody->forward($this->document, $this->sender, $this->recipient), $this->recipient, 'Wrong office');

    Livewire::actingAs($this->sender)
        ->test(Show::class, ['document' => $this->document])
        ->assertSee('With Sam Sender')
        ->assertSee('Declined')
        ->assertSee('Reason: Wrong office');
});

test('the details say each thing once: subject only when it differs, the department alone, who and when on one line', function () {
    $this->document->update([
        'subject' => 'Budget request',
        'originating_office' => 'Registry',
        'originating_department' => 'Finance',
        'originating_directorate' => 'Corporate Services',
        'registered_by' => $this->sender->id,
        'registered_at' => now()->setDate(2026, 11, 25)->setTime(19, 46),
    ]);

    $page = Livewire::actingAs($this->sender)->test(Show::class, ['document' => $this->document->fresh()])
        ->assertSee('Department')->assertSee('Finance')->assertDontSee('Registry')->assertDontSee('Corporate Services')
        ->assertSee('Sam Sender, 25 Nov 2026 19:46')
        ->assertDontSee('Originating directorate')->assertDontSee('Registered on');
    expect(substr_count($page->html(), 'Budget request'))->toBe(1);

    $this->document->update(['subject' => 'Capital budget for 2027']);
    Livewire::actingAs($this->sender)->test(Show::class, ['document' => $this->document->fresh()])
        ->assertSee('Capital budget for 2027');
});
