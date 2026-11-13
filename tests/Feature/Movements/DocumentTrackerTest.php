<?php

use App\Livewire\Movements\History;
use App\Livewire\Movements\Track;
use App\Models\PdtsDepartment;
use App\Models\PdtsDocument;
use App\Models\PdtsOffice;
use App\Models\User;
use App\Services\Documents\CustodyService;
use App\Services\Documents\DocumentTracker;
use Database\Seeders\PdtsAccessSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(PdtsAccessSeeder::class);

    $this->custody = app(CustodyService::class);
    $this->tracker = app(DocumentTracker::class);

    $this->employee = trackerUser('Originating Employee', 'Eve Employee', 'Accounts');
    $this->supervisor = trackerUser('Supervisor', 'Sid Supervisor', 'Procurement');
    $this->manager = trackerUser('Manager', 'Mia Manager', 'Finance');

    $this->document = PdtsDocument::factory()->create([
        'current_custodian_id' => $this->employee->id,
        'reference_number' => 'PDTS-2026-0100',
        'title' => 'Budget request',
        'originating_office' => 'Accounts',
    ]);
    $this->document->auditLogs()->create(['actor_id' => $this->employee->id, 'action' => 'registered', 'occurred_at' => now()]);
});

function trackerUser(string $role, string $name, ?string $section = null, ?string $directorate = null): User
{
    return User::factory()
        ->create(['name' => $name, 'functional_section' => $section, 'directorate' => $directorate])
        ->assignRole($role);
}

function tracked(PdtsDocument $document): array
{
    return app(DocumentTracker::class)->for($document->fresh());
}

test('history includes messenger pickup and final completion without assigning custody to the messenger', function () {
    $messenger = User::factory()->create(['name' => 'Assigned Messenger']);
    $movement = $this->custody->forward($this->document, $this->employee, $this->supervisor, null, $messenger);
    $this->custody->confirmPickup($movement, $messenger);
    $this->custody->acknowledge($movement, $this->supervisor);
    $this->custody->complete($this->document, $this->supervisor, 'signed', 'Original signed');

    $track = tracked($this->document);

    expect($track['headline'])->toBe('Completed')
        ->and($track['detail'])->toContain('Sid Supervisor is the final holder')
        ->and(collect($track['events'])->pluck('type')->all())->toContain('completed', 'picked_up')
        ->and($track['stops'])->toHaveCount(2)
        ->and($track['stops'][1]['state'])->toBe('ended');

    Livewire::actingAs($messenger)->test(History::class, ['document' => $this->document])
        ->assertSee('Picked up')->assertSee('Original signed')->assertSee('Completed');

    $this->actingAs($messenger)->get(route('movements.history', $this->document))
        ->assertOk()->assertSee('Original signed');
});

describe('stops', function () {
    test('a new document has one current stop at the registering office', function () {
        $track = tracked($this->document);

        expect($track['stops'])->toHaveCount(1)
            ->and($track['stops'][0]['office'])->toBe('Accounts')
            ->and($track['stops'][0]['label'])->toBe('Registered')
            ->and($track['stops'][0]['person'])->toBe('Eve Employee')
            ->and($track['stops'][0]['state'])->toBe('current')
            ->and($track['headline'])->toBe('With Accounts');
    });

    test('a forwarded document shows the recipient office as pending and the sender as responsible', function () {
        $this->custody->forward($this->document, $this->employee, $this->supervisor);

        $track = tracked($this->document);

        expect($track['stops'])->toHaveCount(2)
            ->and($track['stops'][0]['state'])->toBe('current')
            ->and($track['stops'][1]['state'])->toBe('pending')
            ->and($track['stops'][1]['office'])->toBe('Procurement')
            ->and($track['headline'])->toBe('On its way to Procurement')
            ->and($track['detail'])->toContain('Eve Employee remains responsible');
    });

    test('it moves from office to office with timestamps', function () {
        $first = $this->custody->forward($this->document, $this->employee, $this->supervisor);
        $this->custody->acknowledge($first, $this->supervisor);
        $second = $this->custody->forward($this->document->fresh(), $this->supervisor, $this->manager);
        $this->custody->acknowledge($second, $this->manager);

        $track = tracked($this->document);

        expect(array_column($track['stops'], 'office'))->toBe(['Accounts', 'Procurement', 'Finance'])
            ->and(array_column($track['stops'], 'state'))->toBe(['done', 'done', 'current'])
            ->and($track['stops'][1]['at'])->not->toBeNull()
            ->and($track['headline'])->toBe('With Finance');
    });

    test('declined and cancelled handovers are not stops', function () {
        $this->custody->decline($this->custody->forward($this->document, $this->employee, $this->supervisor), $this->supervisor, 'Wrong office');
        $this->custody->cancel($this->custody->forward($this->document->fresh(), $this->employee, $this->manager), $this->employee, 'Sent in error');

        $track = tracked($this->document);

        expect($track['stops'])->toHaveCount(1)
            ->and($track['headline'])->toBe('With Accounts')
            ->and(array_column($track['events'], 'type'))->toContain('declined', 'cancelled');
    });

    test('it does not invent future stops', function () {
        $this->custody->forward($this->document, $this->employee, $this->supervisor);

        expect(array_column(tracked($this->document)['stops'], 'office'))->toBe(['Accounts', 'Procurement']);
    });
});

describe('offices', function () {
    test('the office stays as it was at forwarding time', function () {
        $movement = $this->custody->forward($this->document, $this->employee, $this->supervisor);
        $this->custody->acknowledge($movement, $this->supervisor);

        $this->supervisor->update(['functional_section' => 'Somewhere New']);
        $this->employee->update(['functional_section' => 'Also New']);

        expect(array_column(tracked($this->document)['stops'], 'office'))->toBe(['Accounts', 'Procurement']);
    });

    test('uses the current office assignment before the HR section', function () {
        $department = PdtsDepartment::create(['name' => 'Procurement Department', 'is_active' => true]);
        $office = PdtsOffice::create(['name' => 'Tender Office', 'department_id' => $department->id, 'is_active' => true]);
        $this->supervisor->pdtsOfficeAssignments()->create(['office_id' => $office->id, 'starts_at' => now(), 'is_primary' => true]);

        $this->custody->forward($this->document, $this->employee, $this->supervisor->fresh());

        expect(array_column(tracked($this->document)['stops'], 'office'))->toBe(['Accounts', 'Tender Office']);
    });

    test('falls back to the directorate, then says it is not recorded', function () {
        $directorate = trackerUser('Manager', 'Dee Director', null, 'Finance Directorate');
        $unknown = trackerUser('Manager', 'Nia Noffice');

        expect($directorate->officeName())->toBe('Finance Directorate')
            ->and($unknown->officeName())->toBeNull();

        $this->custody->forward($this->document, $this->employee, $unknown);

        $track = tracked($this->document);

        expect($track['stops'][1]['office'])->toBe(DocumentTracker::NO_OFFICE)
            ->and($track['headline'])->toBe('On its way to Nia Noffice');
    });
});

describe('timeline', function () {
    test('lists the newest event first, with who, when, how long and remarks', function () {
        $this->travelTo(now()->subHours(6));
        $this->document->update(['registered_at' => now()]);
        $movement = $this->custody->forward($this->document, $this->employee, $this->supervisor, 'Please review');
        $this->travel(2)->hours();
        $this->custody->acknowledge($movement, $this->supervisor);
        $this->travelBack();

        $events = tracked($this->document)['events'];

        expect(array_column($events, 'type'))->toBe(['received', 'registered'])
            ->and($events[0]['office'])->toBe('Procurement')
            ->and($events[0]['remarks'])->toBe('Please review')
            ->and($events[0]['details'])->toContain('Acknowledged by Sid Supervisor')
            ->and(collect($events[0]['details'])->last())->toContain('so far')
            ->and($events[1]['details'][0])->toContain('Held for 2');
    });

    test('a declined handover shows its reason and that the document did not move', function () {
        $this->custody->decline($this->custody->forward($this->document, $this->employee, $this->supervisor), $this->supervisor, 'Wrong office');

        $declined = collect(tracked($this->document)['events'])->firstWhere('type', 'declined');

        expect($declined['details'])->toContain('Reason: Wrong office', 'The document did not move');
    });
});

describe('tracking page', function () {
    test('draws the stops and timeline', function () {
        $movement = $this->custody->forward($this->document, $this->employee, $this->supervisor, 'Please review');
        $this->custody->acknowledge($movement, $this->supervisor);
        $this->custody->forward($this->document->fresh(), $this->supervisor, $this->manager);

        Livewire::actingAs($this->employee)
            ->test(History::class, ['document' => $this->document])
            ->assertSee('On its way to Finance')
            ->assertSeeInOrder(['Accounts', 'Procurement', 'Finance'])
            ->assertSee('is-pending')
            ->assertSee('Please review')
            ->assertSee('Movement history');
    });

    test('the initiator can still see it after the document has moved on', function () {
        $first = $this->custody->forward($this->document, $this->employee, $this->supervisor);
        $this->custody->acknowledge($first, $this->supervisor);
        $second = $this->custody->forward($this->document->fresh(), $this->supervisor, $this->manager);
        $this->custody->acknowledge($second, $this->manager);

        $this->actingAs($this->employee)
            ->get(route('movements.history', $this->document))
            ->assertOk()
            ->assertSee('With Finance');
    });

    test('is refused for someone not involved', function () {
        $this->actingAs(trackerUser('Supervisor', 'Other Person'))
            ->get(route('movements.history', $this->document))
            ->assertForbidden();
    });
});

describe('track my documents', function () {
    test('lists documents the user registered, even after they moved on', function () {
        $movement = $this->custody->forward($this->document, $this->employee, $this->supervisor);
        $this->custody->acknowledge($movement, $this->supervisor);

        Livewire::actingAs($this->employee)->test(Track::class)
            ->assertSee('PDTS-2026-0100')
            ->assertSee('Received by Sid Supervisor on')
            ->assertSee('Procurement')
            ->assertSeeHtml('data-progress-step="3"');
    });

    test('says where each document is in one consistent set of sentences', function () {
        $sent = PdtsDocument::factory()->create(['current_custodian_id' => $this->employee->id, 'reference_number' => 'PDTS-2026-0101']);
        $sent->auditLogs()->create(['actor_id' => $this->employee->id, 'action' => 'registered', 'occurred_at' => now()]);
        $this->custody->forward($sent, $this->employee, $this->manager);

        Livewire::actingAs($this->employee)->test(Track::class)
            ->assertSee('Not sent yet')
            ->assertSee('On its way to Mia Manager, waiting for receipt')
            ->assertSee('Finance')
            ->assertSeeHtml('data-progress-step="1"')
            ->assertSeeHtml('data-progress-step="2"');
    });

    test('shows how long ago, flags overdue handovers and lists them first', function () {
        config(['pdts.overdue_after_days' => 3]);

        $this->travelTo(now()->subDays(5));
        $stuck = PdtsDocument::factory()->create(['current_custodian_id' => $this->employee->id, 'reference_number' => 'PDTS-2026-0001']);
        $stuck->auditLogs()->create(['actor_id' => $this->employee->id, 'action' => 'registered', 'occurred_at' => now()]);
        $this->custody->forward($stuck, $this->employee, $this->supervisor);
        $this->travelBack();

        Livewire::actingAs($this->employee)->test(Track::class)
            ->assertSeeInOrder(['PDTS-2026-0001', '5 days ago', 'Overdue', 'PDTS-2026-0100'])
            ->assertSee('Not received within 3 days');
    });

    test('tabs show all and in-progress documents only', function () {
        Livewire::actingAs($this->employee)->test(Track::class)
            ->assertSeeHtml('data-tab-count="all">1<')
            ->assertSeeHtml('data-tab-count="in-progress">1<')
            ->assertSee('PDTS-2026-0100')
            ->call('setTab', 'nonsense')
            ->assertSet('tab', 'all');
    });

    test('leaves out documents the user only received', function () {
        $movement = $this->custody->forward($this->document, $this->employee, $this->supervisor);
        $this->custody->acknowledge($movement, $this->supervisor);

        Livewire::actingAs($this->supervisor)->test(Track::class)
            ->assertDontSee('PDTS-2026-0100');
    });

    test('searches by reference and subject', function (string $term) {
        Livewire::actingAs($this->employee)->test(Track::class)
            ->set('search', $term)
            ->assertSee('PDTS-2026-0100');
    })->with(['pdts-2026', 'BUDGET']);

    test('says when nothing matches', function () {
        Livewire::actingAs($this->employee)->test(Track::class)
            ->set('search', 'nothing like this')
            ->assertDontSee('PDTS-2026-0100')
            ->assertSee('No documents match');
    });

    test('the page and sidebar link need the register permission', function () {
        $this->actingAs($this->employee)->get(route('movements.track'))
            ->assertOk()
            ->assertSee('Track My Documents');

        $this->actingAs(trackerUser('Management / Auditor', 'Ann Auditor'))
            ->get(route('movements.track'))
            ->assertForbidden();
    });
});
