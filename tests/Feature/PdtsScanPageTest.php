<?php

use App\Livewire\Pdts\ScanPage;
use App\Models\PdtsDocument;
use App\Models\PdtsDocumentType;
use App\Models\User;
use App\Notifications\DocumentReportedNotification;
use App\Services\Documents\CustodyService;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    Notification::fake();

    foreach (['documents.view', 'documents.forward', 'documents.acknowledge', 'documents.register'] as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $this->sender = User::factory()->create(['man_no' => 'SCN-0001', 'name' => 'Teddy Sikazwe']);
    $this->recipient = User::factory()->create(['man_no' => 'SCN-0002', 'name' => 'Benson Ngulube']);
    $this->messenger = User::factory()->create(['man_no' => 'SCN-0003', 'name' => 'Chimuka Moonde']);
    $this->outsider = User::factory()->create(['man_no' => 'SCN-0004', 'name' => 'Sydney Mwandama']);

    foreach ([$this->sender, $this->recipient, $this->messenger, $this->outsider] as $user) {
        $user->givePermissionTo(['documents.view', 'documents.forward', 'documents.acknowledge']);
    }

    $type = PdtsDocumentType::create(['name' => 'Memorandum', 'is_active' => true]);

    $this->document = PdtsDocument::create([
        'reference_number' => 'OMTS-2026-0101',
        'title' => 'Procurement of ICT',
        'subject' => 'Procurement of ICT',
        'document_type_id' => $type->id,
        'originating_office' => 'Director',
        'registered_by' => $this->sender->id,
        'current_custodian_id' => $this->sender->id,
        'status' => 'registered',
        'registered_at' => now(),
    ]);
});

function scanFor(User $user)
{
    return Livewire::actingAs($user)->test(ScanPage::class, ['token' => test()->document->scan_token]);
}

function forwardWithMessenger(): void
{
    app(CustodyService::class)->forward(test()->document, test()->sender, test()->recipient, 'Please review', test()->messenger);
}

test('the QR link opens the phone scan page', function () {
    $this->actingAs($this->sender)->get($this->document->scanUrl())
        ->assertOk()
        ->assertSee('OMTS-2026-0101')
        ->assertSee('Procurement of ICT')
        ->assertDontSee('Registered Documents'); // no sidebar

    $this->actingAs($this->sender)->get(route('pdts.scan', str_repeat('x', 40)))->assertNotFound();
});

test('people not involved see no details, even with documents.view', function () {
    forwardWithMessenger();

    scanFor($this->outsider)
        ->assertSee('not addressed to you')
        ->assertDontSee('Procurement of ICT')
        ->assertDontSee('OMTS-2026-0101')
        ->assertDontSee('Benson Ngulube')
        ->assertDontSee('Open in the full app')
        ->assertDontSee('Journey')
        ->assertSee('Report found / wrong delivery');
});

test('the messenger confirms pickup and delivery, then the recipient confirms receipt', function () {
    forwardWithMessenger();

    scanFor($this->recipient)
        ->assertSee('The messenger must confirm pickup')
        ->assertDontSee('Confirm receipt');

    scanFor($this->messenger)
        ->assertSee('Confirm pickup')
        ->call('confirmPickup')
        ->assertSee('Pickup confirmed');

    // Picked up but not delivered: the recipient cannot confirm receipt yet.
    scanFor($this->recipient)
        ->assertSee('The messenger must confirm delivery')
        ->assertDontSee('Confirm receipt');

    scanFor($this->messenger)
        ->assertSeeHtml('data-test="confirm-delivery"')
        ->call('confirmDelivery')
        ->assertSee('Delivery confirmed');

    scanFor($this->recipient)
        ->assertSee('Confirm receipt')
        ->call('acknowledge')
        ->assertSee('Receipt confirmed');

    $this->document->refresh();
    expect($this->document->status)->toBe('received')
        ->and($this->document->current_custodian_id)->toBe($this->recipient->id);
});

test('the recipient can decline with a reason', function () {
    app(CustodyService::class)->forward($this->document, $this->sender, $this->recipient);

    scanFor($this->recipient)
        ->call('openForm', 'decline')
        ->call('decline')
        ->assertHasErrors('reason')
        ->set('reason', 'Not my department')
        ->call('decline')
        ->assertSee('You declined the document');

    expect($this->document->refresh()->status)->toBe('registered');
});

test('the sender sees cancel while the handover is waiting', function () {
    app(CustodyService::class)->forward($this->document, $this->sender, $this->recipient);

    scanFor($this->sender)
        ->assertSee('On its way to Benson Ngulube')
        ->assertSee('Cancel handover')
        ->assertDontSee('Confirm receipt');
});

test('the holder of a received document can send it back or complete it', function () {
    $custody = app(CustodyService::class);
    $custody->acknowledge($custody->forward($this->document, $this->sender, $this->recipient), $this->recipient);

    scanFor($this->recipient)
        ->assertSee('With you')
        ->assertSee('Send back for correction')
        ->call('openForm', 'complete')
        ->set('completionAction', 'signed')
        ->set('reason', 'Signed and filed')
        ->call('complete')
        ->assertSee('The document is completed');

    expect($this->document->refresh()->status)->toBe('completed');
});

test('a past holder sees where the document is but has no buttons', function () {
    $custody = app(CustodyService::class);
    $custody->acknowledge($custody->forward($this->document, $this->sender, $this->recipient), $this->recipient);

    scanFor($this->sender)
        ->assertSee('With Benson Ngulube')
        ->assertDontSee('Confirm receipt')
        ->assertDontSee('Send back for correction')
        ->assertSee('Journey')
        ->assertSee('Registered: Director')
        ->assertSee('Open in the full app');
});

test('the scan page has a log out button and keeps the journey on the page', function () {
    forwardWithMessenger();

    $this->actingAs($this->sender)->get($this->document->scanUrl())
        ->assertSee('Log out')
        ->assertSee(route('pdts.scan.logout', $this->document->scan_token), false)
        ->assertSee('Journey')
        ->assertSee('Please review')
        ->assertDontSee(route('movements.history', $this->document), false);
});

test('someone not involved can report the document once, and the people responsible are told', function () {
    forwardWithMessenger();

    scanFor($this->outsider)
        ->call('openForm', 'report')
        ->set('note', 'Left at Procurement reception')
        ->call('reportFound')
        ->assertSee('The people responsible have been told');

    $log = $this->document->auditLogs()->where('action', 'reported_found')->sole();
    expect($log->actor_id)->toBe($this->outsider->id)
        ->and($log->details['note'])->toBe('Left at Procurement reception')
        ->and($this->document->refresh()->current_custodian_id)->toBe($this->sender->id);

    Notification::assertSentTo([$this->sender, $this->recipient], DocumentReportedNotification::class);
    Notification::assertNotSentTo($this->outsider, DocumentReportedNotification::class);

    scanFor($this->outsider)
        ->assertSee('You have already reported this document')
        ->assertDontSee('Report found / wrong delivery');
});

test('logging out from the scan page comes back to the same document after the next login', function () {
    $url = $this->document->scanUrl();

    $this->actingAs($this->sender)
        ->post(route('pdts.scan.logout', $this->document->scan_token))
        ->assertRedirect($url);
    $this->assertGuest();

    $this->get($url)->assertRedirect(route('login'));

    $this->post(route('login.store'), ['man_no' => $this->recipient->man_no, 'password' => 'password'])
        ->assertRedirect($url);
});

test('with the login setting off, anyone with the code can track the document', function () {
    config(['pdts.scan_requires_login' => false]);
    forwardWithMessenger();

    $this->get($this->document->scanUrl())
        ->assertOk()
        ->assertSee('OMTS-2026-0101')
        ->assertSee('On its way to Benson Ngulube')
        ->assertSee('Sent by Teddy Sikazwe')
        ->assertSee('Journey')
        ->assertDontSee('Confirm pickup')
        ->assertDontSee('Confirm receipt')
        ->assertDontSee('Decline')
        ->assertDontSee('Cancel handover');
});

test('without a login, scanning is view-only and cannot confirm', function () {
    forwardWithMessenger();
    $token = $this->document->scan_token;

    Livewire::test(ScanPage::class, ['token' => $token])->call('confirmPickup')->assertForbidden();
    Livewire::test(ScanPage::class, ['token' => $token])->call('acknowledge')->assertForbidden();

    $movement = $this->document->pendingMovement()->first();
    expect($movement->picked_up_at)->toBeNull()
        ->and($this->document->refresh()->status)->toBe('awaiting_receipt');
});

test('after a document is received, a scan shows who has it', function () {
    config(['pdts.scan_requires_login' => false]);
    $custody = app(CustodyService::class);
    $custody->acknowledge($custody->forward($this->document, $this->sender, $this->recipient), $this->recipient);

    $this->get($this->document->scanUrl())
        ->assertOk()
        ->assertSee('OMTS-2026-0101')
        ->assertSee('With Benson Ngulube');
});

test('without a login, other actions are refused', function () {
    app(CustodyService::class)->forward($this->document, $this->sender, $this->recipient);

    Livewire::test(ScanPage::class, ['token' => $this->document->scan_token])
        ->set('reason', 'Not mine')
        ->call('decline')
        ->assertForbidden();

    expect($this->document->refresh()->status)->toBe('awaiting_receipt');
});

test('by default a scan requires a login', function () {
    app(CustodyService::class)->forward($this->document, $this->sender, $this->recipient);

    $this->get($this->document->scanUrl())->assertRedirect(route('login'));

    Livewire::test(ScanPage::class, ['token' => $this->document->scan_token])
        ->call('acknowledge')
        ->assertForbidden();

    expect($this->document->refresh()->status)->toBe('awaiting_receipt');
});

test('people involved cannot file a found report', function () {
    scanFor($this->sender)->call('reportFound')->assertForbidden();
});

test('on a phone: scan, log in, confirm, log out, and the next person lands on the same document without scanning again', function () {
    $this->withHeader('User-Agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1');
    app(CustodyService::class)->forward($this->document, $this->sender, $this->recipient, 'Please review');
    $url = $this->document->scanUrl();

    // 1. Benson scans the code: not logged in, so the normal login opens.
    $this->get($url)->assertRedirect(route('login'));
    $this->get(route('login'))->assertOk();

    // 2. He logs in and comes straight back to the document, with Confirm receipt.
    $this->post(route('login.store'), ['man_no' => $this->recipient->man_no, 'password' => 'password'])
        ->assertRedirect($url);
    $this->get($url)->assertOk()->assertSee('OMTS-2026-0101')->assertSee('Confirm receipt');

    // 3. He confirms receipt.
    scanFor($this->recipient)->call('acknowledge')->assertHasNoErrors();
    expect($this->document->refresh()->status)->toBe('received')
        ->and((int) $this->document->current_custodian_id)->toBe($this->recipient->id);

    // 4. He taps Log out on the scan page: back to the login, remembering the document.
    $this->post(route('pdts.scan.logout', $this->document->scan_token))->assertRedirect($url);
    $this->assertGuest();
    $this->get($url)->assertRedirect(route('login'));
    $this->get(route('login'))->assertOk();

    // 5. The next person logs in on the same phone and lands on the same document, no second scan.
    $this->post(route('login.store'), ['man_no' => $this->outsider->man_no, 'password' => 'password'])
        ->assertRedirect($url);
    $this->get($url)->assertOk()
        ->assertSee('This document is tracked in PDTS and is not addressed to you.')
        ->assertSee('Report found / wrong delivery');
});

test('opening the home address after an unfinished scan logs in to the dashboard, not the document', function () {
    $this->get($this->document->scanUrl())->assertRedirect(route('login'));

    $this->get('/')->assertRedirect(route('login'));

    $this->post(route('login.store'), ['man_no' => $this->recipient->man_no, 'password' => 'password'])
        ->assertRedirect(route('dashboard'));
});
