<?php

use App\Enums\DocumentStatus;
use App\Enums\MovementStatus;
use App\Livewire\Movements\Pending;
use App\Livewire\Pdts\Organization\Delegations;
use App\Models\PdtsDocument;
use App\Models\PdtsDocumentType;
use App\Models\PdtsUserDelegation;
use App\Models\User;
use App\Services\Documents\CustodyException;
use App\Services\Documents\CustodyService;
use Database\Seeders\PdtsAccessSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(PdtsAccessSeeder::class);

    $this->admin = User::factory()->create(['man_no' => 'DLG-ADMIN']);
    $this->admin->assignRole('System Administrator');

    $this->sender = User::factory()->create(['man_no' => 'DLG-SENDER']);
    $this->sender->assignRole('Originating Employee');

    $this->recipient = User::factory()->create(['man_no' => 'DLG-RECIPIENT']);
    $this->recipient->assignRole('Manager');

    $this->delegate = User::factory()->create(['man_no' => 'DLG-DELEGATE']);
    $this->delegate->assignRole('Supervisor');

    $type = PdtsDocumentType::firstOrCreate(['name' => 'Memorandum'], ['is_active' => true]);
    $this->document = PdtsDocument::create([
        'reference_number' => 'OMTS-2026-0099',
        'title' => 'Delegation test document',
        'subject' => 'Test acknowledgement delegation',
        'document_type_id' => $type->id,
        'originating_department' => 'Operations',
        'originating_office' => 'Main Office',
        'registered_by' => $this->sender->id,
        'current_custodian_id' => $this->sender->id,
        'status' => DocumentStatus::Registered->value,
        'registered_at' => now(),
    ]);
});

test('administrator can create and revoke a time-bounded acknowledgement delegation', function () {
    Livewire::actingAs($this->admin)
        ->test(Delegations::class)
        ->set('principalUserId', (string) $this->recipient->id)
        ->set('delegateUserId', (string) $this->delegate->id)
        ->set('startsAt', now()->subMinute()->format('Y-m-d\TH:i'))
        ->set('expiresAt', now()->addDay()->format('Y-m-d\TH:i'))
        ->set('reason', 'Recipient is on leave')
        ->call('grant')
        ->assertHasNoErrors();

    $delegation = PdtsUserDelegation::query()->sole();
    expect($delegation->principal_user_id)->toBe($this->recipient->id)
        ->and($delegation->delegate_user_id)->toBe($this->delegate->id)
        ->and($delegation->granted_by)->toBe($this->admin->id);

    Livewire::actingAs($this->admin)
        ->test(Delegations::class)
        ->call('revoke', $delegation->id)
        ->assertHasNoErrors();

    expect($delegation->fresh()->revoked_at)->not->toBeNull();
});

test('active delegate can acknowledge and becomes the recorded custodian', function () {
    PdtsUserDelegation::create([
        'principal_user_id' => $this->recipient->id,
        'delegate_user_id' => $this->delegate->id,
        'granted_by' => $this->admin->id,
        'starts_at' => now()->subMinute(),
        'expires_at' => now()->addDay(),
        'reason' => 'Acting on behalf of recipient',
    ]);

    $movement = app(CustodyService::class)->forward($this->document, $this->sender, $this->recipient);

    Livewire::actingAs($this->delegate)
        ->test(Pending::class)
        ->assertSee($this->document->reference_number)
        ->assertSee('For '.$this->recipient->name)
        ->call('openAcknowledge', $movement->id)
        ->call('acknowledge')
        ->assertHasNoErrors();

    $this->assertSame($this->delegate->id, $this->document->fresh()->current_custodian_id);
    expect($movement->fresh()->recipient_id)->toBe($this->recipient->id)
        ->and($movement->fresh()->acknowledged_by)->toBe($this->delegate->id)
        ->and($movement->fresh()->new_custodian_id)->toBe($this->delegate->id)
        ->and($movement->fresh()->status)->toBe(MovementStatus::Acknowledged);
});

test('unassigned delegate cannot acknowledge for the intended recipient', function () {
    $unauthorized = User::factory()->create(['man_no' => 'DLG-UNAUTH']);
    $unauthorized->assignRole('Supervisor');
    $movement = app(CustodyService::class)->forward($this->document, $this->sender, $this->recipient);

    expect(fn () => app(CustodyService::class)->acknowledge($movement, $unauthorized))
        ->toThrow(CustodyException::class, 'authorized delegate');

    $this->assertSame($this->sender->id, $this->document->fresh()->current_custodian_id);
});
