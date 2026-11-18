<?php

use App\Livewire\Pdts\ScanPage;
use App\Models\PdtsDocument;
use App\Models\PdtsDocumentType;
use App\Models\PdtsSmsLog;
use App\Models\User;
use App\Services\Documents\CustodyService;
use App\Services\Sms\DocumentHistorySms;
use App\Services\Sms\PhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();
    RateLimiter::clear('pdts-scan-sms:device:127.0.0.1');
    config(['services.sms.driver' => 'log']);

    foreach (['documents.view', 'documents.forward', 'documents.acknowledge'] as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $this->sender = User::factory()->create(['man_no' => 'SMS-0001', 'name' => 'TEDDY SIKAZWE']);
    $this->recipient = User::factory()->create(['man_no' => 'SMS-0002', 'name' => 'BENSON NGULUBE']);
    foreach ([$this->sender, $this->recipient] as $user) {
        $user->givePermissionTo(['documents.view', 'documents.forward', 'documents.acknowledge']);
    }

    $type = PdtsDocumentType::create(['name' => 'Memorandum', 'is_active' => true]);
    $this->document = PdtsDocument::create([
        'reference_number' => 'OMTS-2026-0301',
        'title' => 'Procurement of ICT',
        'subject' => 'Procurement of ICT',
        'document_type_id' => $type->id,
        'originating_office' => 'Director',
        'registered_by' => $this->sender->id,
        'current_custodian_id' => $this->sender->id,
        'status' => 'registered',
        'registered_at' => now(),
    ]);

    app(CustodyService::class)->forward($this->document, $this->sender, $this->recipient, 'Please review');
});

test('mobile numbers are normalised to +260 form', function () {
    expect(PhoneNumber::normalize('0977 123 456'))->toBe('+260977123456')
        ->and(PhoneNumber::normalize('977123456'))->toBe('+260977123456')
        ->and(PhoneNumber::normalize('+260 96 123 4567'))->toBe('+260961234567')
        ->and(PhoneNumber::normalize('260761234567'))->toBe('+260761234567')
        ->and(PhoneNumber::normalize('0211 123456'))->toBeNull()
        ->and(PhoneNumber::normalize('12345'))->toBeNull()
        ->and(PhoneNumber::masked('+260977123456'))->toBe('+260 97 *** 3456');
});

test('the history SMS says where the document is and its journey, kept short', function () {
    $text = app(DocumentHistorySms::class)->for($this->document->fresh());

    expect($text)->toStartWith('PDTS OMTS-2026-0301: Procurement of ICT')
        ->toContain('Now: on its way to B. Ngulube')
        ->toContain('History:')
        ->toContain('Registered - T. Sikazwe')
        ->and(mb_strlen($text))->toBeLessThanOrEqual(DocumentHistorySms::MAX_LENGTH);
});

test('someone who scans without logging in can have the history sent to their phone', function () {
    $this->get($this->document->scanUrl())->assertSee('Send this history to my phone');

    Livewire::test(ScanPage::class, ['token' => $this->document->scan_token])
        ->set('smsPhone', '0977 123 456')
        ->call('sendHistorySms')
        ->assertHasNoErrors()
        ->assertSee('History sent by SMS to +260 97 *** 3456')
        ->assertSee('Test mode');

    $log = PdtsSmsLog::sole();
    expect($log->to)->toBe('+260977123456')
        ->and($log->purpose)->toBe('scan_history')
        ->and($log->pdts_document_id)->toBe($this->document->id)
        ->and($log->status)->toBe('sent')
        ->and($log->driver)->toBe('log')
        ->and($log->message)->toContain('OMTS-2026-0301');
});

test('a wrong number is rejected and nothing is sent', function () {
    Livewire::test(ScanPage::class, ['token' => $this->document->scan_token])
        ->set('smsPhone', '12345')
        ->call('sendHistorySms')
        ->assertHasErrors('smsPhone');

    expect(PdtsSmsLog::count())->toBe(0);
});

test('the same number can get at most three messages an hour', function () {
    RateLimiter::clear('pdts-scan-sms:number:+260977000111');

    foreach (range(1, 3) as $i) {
        Livewire::test(ScanPage::class, ['token' => $this->document->scan_token])
            ->set('smsPhone', '0977000111')->call('sendHistorySms')->assertHasNoErrors();
    }

    Livewire::test(ScanPage::class, ['token' => $this->document->scan_token])
        ->set('smsPhone', '0977000111')->call('sendHistorySms')
        ->assertHasErrors('smsPhone');

    expect(PdtsSmsLog::count())->toBe(3);
});

test('a logged-in person not involved cannot have the history sent', function () {
    $outsider = User::factory()->create(['man_no' => 'SMS-0003']);
    $outsider->givePermissionTo('documents.view');

    Livewire::actingAs($outsider)->test(ScanPage::class, ['token' => $this->document->scan_token])
        ->assertDontSee('Send this history to my phone')
        ->set('smsPhone', '0977123456')
        ->call('sendHistorySms')
        ->assertForbidden();
});

test('a logged-in user gets their own mobile number filled in', function () {
    $this->recipient->forceFill(['mobile_no' => '0966555444'])->save();

    Livewire::actingAs($this->recipient)->test(ScanPage::class, ['token' => $this->document->scan_token])
        ->assertSet('smsPhone', '0966555444');
});

test('the http driver posts the message to the configured gateway', function () {
    config(['services.sms.driver' => 'http', 'services.sms.url' => 'https://sms.example.test/send', 'services.sms.key' => 'secret']);
    Http::fake(['sms.example.test/*' => Http::response(['ok' => true])]);

    Livewire::test(ScanPage::class, ['token' => $this->document->scan_token])
        ->set('smsPhone', '0955123123')
        ->call('sendHistorySms')
        ->assertSee('History sent by SMS')
        ->assertDontSee('Test mode');

    Http::assertSent(fn ($request) => $request->url() === 'https://sms.example.test/send'
        && $request['to'] === '+260955123123'
        && str_contains($request['message'], 'OMTS-2026-0301')
        && $request->hasHeader('Authorization', 'Bearer secret'));
});

test('a gateway failure is logged and the person is told', function () {
    config(['services.sms.driver' => 'http', 'services.sms.url' => 'https://sms.example.test/send']);
    Http::fake(['sms.example.test/*' => Http::response('down', 500)]);

    Livewire::test(ScanPage::class, ['token' => $this->document->scan_token])
        ->set('smsPhone', '0955123124')
        ->call('sendHistorySms')
        ->assertSee('The SMS could not be sent');

    expect(PdtsSmsLog::sole()->status)->toBe('failed');
});
