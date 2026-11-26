<?php

use App\Actions\Fortify\CreateNewUser;
use App\Http\Controllers\CoverSheetController;
use App\Http\Controllers\DocumentTemplateDownloadController;
use App\Http\Controllers\DocumentTemplateOriginalController;
use App\Http\Controllers\DocumentVersionController;
use App\Http\Controllers\InternalDocumentWordController;
use App\Http\Controllers\PdtsDocumentAttachmentController;
use App\Http\Controllers\ScanLogoutController;
use App\Http\Controllers\UtilsController;
use App\Http\Middleware\ScanPageAccess;
use App\Livewire\Admin\EmailLogIndex;
use App\Livewire\Notifications\Index;
use App\Livewire\Pdts\Dashboard;
use App\Livewire\Pdts\Documents\Archive;
use App\Livewire\Pdts\Documents\DocumentAiPanel;
use App\Livewire\Pdts\Documents\Index as PdtsDocumentIndex;
use App\Livewire\Pdts\Documents\InternalDocument;
use App\Livewire\Pdts\Documents\Registration as PdtsDocumentRegistration;
use App\Livewire\Pdts\Documents\Show as PdtsDocumentShow;
use App\Livewire\Pdts\OcrWorkbench;
use App\Livewire\Pdts\Organization\Delegations as PdtsDelegations;
use App\Livewire\Pdts\Organization\Departments as PdtsDepartments;
use App\Livewire\Pdts\Organization\Directorates as PdtsDirectorates;
use App\Livewire\Pdts\Organization\DocumentTemplates;
use App\Livewire\Pdts\Organization\DocumentTypes;
use App\Livewire\Pdts\Organization\EmployeeAssignments as PdtsEmployeeAssignments;
use App\Livewire\Pdts\Organization\Offices as PdtsOffices;
use App\Livewire\Pdts\Organization\TemplateBuilder;
use App\Livewire\Pdts\Organization\TemplateQrSetup;
use App\Livewire\Pdts\Organization\TemplateReview;
use App\Livewire\Pdts\Organization\TemplateView;
use App\Livewire\Pdts\ScanPage;
use App\Livewire\Pdts\Signatures\SignatureRequests;
use App\Livewire\Pdts\Signatures\VerificationQueue;
use App\Livewire\Settings\MySignature;
use App\Livewire\Settings\NotificationPreferences;
use App\Livewire\Support\AdminChatCenter;
use App\Livewire\Users\UserCreate;
use App\Livewire\Users\UserEdit;
use App\Livewire\Users\UserIndex;
use App\Livewire\Users\UserShow;
use App\Models\PdtsDocumentTemplate;
use App\Models\UserSignatureImage;
use App\Services\Pdts\Templates\TemplateSetup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Http\Controllers\RegisteredUserController;

Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route('dashboard');
    }

    // Opening the system's home address starts a normal login: forget any
    // document remembered from an earlier QR scan, so login goes to the dashboard.
    session()->forget('url.intended');

    return redirect()->route('login');
})->name('home');
// Fortify registration route
Route::post('/register', [RegisteredUserController::class, 'store'])
    ->name('register');

// AJAX verification for ZESCO employees
Route::post('/register/verify', function (Request $request, CreateNewUser $creator) {
    try {
        $userData = $creator->verifyZesco($request->only('man_no', 'email'));

        return response()->json([
            'success' => true,
            'user' => $userData,
        ]);
    } catch (ValidationException $e) {
        return response()->json([
            'success' => false,
            'message' => $e->errors()['man_no'][0] ?? 'Verification failed',
        ]);
    }
})->name('register.verify');
// Where a printed QR code lands: a phone page with the action that fits the
// person scanning. Login is controlled by pdts.scan_requires_login (see
// ScanPageAccess). Unknown codes get 404.
Route::get('/s/{token}', ScanPage::class)
    ->where('token', '[A-Za-z0-9]{20,64}')
    ->middleware(ScanPageAccess::class)
    ->name('pdts.scan');

// Log out from a scan page and return to the same document. Outside the auth
// group so a phone whose session already expired still lands on the login page.
Route::post('/s/{token}/logout', ScanLogoutController::class)
    ->where('token', '[A-Za-z0-9]{20,64}')
    ->name('pdts.scan.logout');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', Dashboard::class)->name('dashboard');
    Route::get('/documents/register', PdtsDocumentRegistration::class)
        ->middleware('can:documents.register')
        ->name('pdts.documents.register');
    Route::get('/documents/internal/create', InternalDocument::class)
        ->middleware('can:documents.register')
        ->name('pdts.documents.internal.create');
    // Access follows the author and custody, so the controller checks it.
    Route::get('/documents/{document}/word', InternalDocumentWordController::class)
        ->whereNumber('document')
        ->name('pdts.documents.word');
    // The A4 QR cover sheet stapled to a paper document. Registrar or holder only.
    Route::get('/documents/{document}/cover-sheet', CoverSheetController::class)
        ->whereNumber('document')
        ->name('pdts.documents.cover-sheet');
    Route::get('/documents', PdtsDocumentIndex::class)
        ->middleware('can:documents.view')
        ->name('pdts.documents.index');
    Route::get('/documents/{document}', PdtsDocumentShow::class)
        ->whereNumber('document')
        ->middleware('can:documents.view')
        ->name('pdts.documents.show');
    Route::get('/documents/{document}/analysis', DocumentAiPanel::class)
        ->whereNumber('document')
        ->middleware(['can:documents.view', 'can:ai.ocr.use'])
        ->name('pdts.documents.analysis');
    Route::get('/documents/{document}/attachments/{attachment}', PdtsDocumentAttachmentController::class)
        ->whereNumber('document')
        ->whereNumber('attachment')
        ->middleware('can:documents.view')
        ->name('pdts.documents.attachments.download');
    Route::get('/ai/ocr', OcrWorkbench::class)
        ->middleware('can:ai.ocr.use')
        ->name('pdts.ai.ocr');
    Route::get('/organization/directorates', PdtsDirectorates::class)
        ->middleware('can:directorates.manage')
        ->name('pdts.organization.directorates');
    Route::get('/organization/departments', PdtsDepartments::class)
        ->middleware('can:departments.manage')
        ->name('pdts.organization.departments');
    Route::get('/organization/offices', PdtsOffices::class)
        ->middleware('can:offices.manage')
        ->name('pdts.organization.offices');
    Route::get('/organization/assignments', PdtsEmployeeAssignments::class)
        ->middleware('can:employee_assignments.manage')
        ->name('pdts.organization.assignments');
    Route::get('/organization/delegations', PdtsDelegations::class)
        ->middleware('can:delegations.manage')
        ->name('pdts.organization.delegations');
    Route::get('/organization/document-types', DocumentTypes::class)
        ->middleware('can:document_types.manage')
        ->name('pdts.organization.document-types');
    Route::get('/organization/document-templates', DocumentTemplates::class)
        ->middleware('can:document_types.manage')
        ->name('pdts.organization.document-templates');
    Route::get('/organization/document-templates/{template}/download', DocumentTemplateDownloadController::class)
        ->whereNumber('template')
        ->middleware('can:document_types.manage')
        ->name('pdts.organization.document-templates.download');
    // The Template Builder: design a template in PDTS, like a form.
    Route::get('/organization/document-templates/build', TemplateBuilder::class)
        ->middleware('can:document_types.manage')
        ->name('pdts.organization.document-templates.build');
    Route::get('/organization/document-templates/{template}/build', TemplateBuilder::class)
        ->whereNumber('template')
        ->middleware('can:document_types.manage')
        ->name('pdts.organization.document-templates.edit-design');
    // Place the QR code on a preview of the uploaded template, then save it.
    Route::get('/organization/document-templates/{template}/qr', TemplateQrSetup::class)
        ->whereNumber('template')
        ->middleware('can:document_types.manage')
        ->name('pdts.organization.document-templates.qr');
    // The template with its QR code where it prints, read-only.
    Route::get('/organization/document-templates/{template}', TemplateView::class)
        ->whereNumber('template')
        ->middleware('can:document_types.manage')
        ->name('pdts.organization.document-templates.view');
    // After saving: what PDTS found, how the form looks, and "Try it".
    Route::get('/organization/document-templates/{template}/review', TemplateReview::class)
        ->whereNumber('template')
        ->middleware('can:document_types.manage')
        ->name('pdts.organization.document-templates.review');
    Route::get('/organization/document-templates/{template}/sample.pdf', function (PdtsDocumentTemplate $template, TemplateSetup $setup) {
        $pdf = $setup->samplePdf($template);
        abort_if($pdf === null, 404);

        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store']);
    })->whereNumber('template')->middleware('can:document_types.manage')->name('pdts.organization.document-templates.sample-pdf');
    // The builder's "Preview" of a design not yet saved: kept briefly, for the person who made it.
    Route::get('/organization/document-templates/preview/{token}.pdf', function (string $token) {
        $preview = Cache::get('pdts.builder-preview.'.$token);
        abort_unless(is_array($preview) && (int) $preview['user'] === (int) auth()->id(), 404);

        return response(base64_decode($preview['pdf']), 200, ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store']);
    })->where('token', '[A-Za-z0-9]{40}')->middleware('can:document_types.manage')->name('pdts.organization.document-templates.builder-preview');
    // How to prepare a Word template that PDTS reads well.
    Route::get('/organization/document-templates/guide', fn () => view('layouts.master', [
        'title' => 'Preparing a template',
        'slot' => new HtmlString(view('pdts.template-guide')->render()),
    ]))
        ->middleware('can:document_types.manage')
        ->name('pdts.organization.document-templates.guide');
    // The file exactly as uploaded, shown in that preview.
    Route::get('/organization/document-templates/{template}/original', DocumentTemplateOriginalController::class)
        ->whereNumber('template')
        ->middleware('can:document_types.manage')
        ->name('pdts.organization.document-templates.original');
    Route::get('/notifications', Index::class)->name('notifications.index');
    Route::get('/settings/notifications', NotificationPreferences::class)->name('settings.notifications');
    // Prepare on behalf of: editing a draft (by its preparer), and the signature lists.
    Route::get('/documents/{document}/edit-draft', InternalDocument::class)
        ->whereNumber('document')
        ->middleware('can:documents.register')
        ->name('pdts.documents.internal.edit');
    Route::get('/signatures', SignatureRequests::class)->name('pdts.signatures');
    // Closed documents: completed (filed, kept until), due for review, cancelled.
    Route::get('/documents/archive', Archive::class)
        ->middleware('can:documents.view')
        ->name('pdts.documents.archive');
    // Internal documents sent to me in PDTS: decisions, for me, copies.
    // ICT verifies uploaded signatures before they can be used.
    Route::get('/signatures/verification', VerificationQueue::class)
        ->middleware('can:signatures.verify')
        ->name('pdts.signatures.verification');
    Route::get('/signatures/verification/images/{image}', function (UserSignatureImage $image) {
        abort_unless(Storage::disk('private')->exists($image->storage_path), 404);

        return response(Storage::disk('private')->get($image->storage_path), 200, [
            'Content-Type' => str_ends_with($image->storage_path, '.png') ? 'image/png' : 'image/jpeg',
            'Cache-Control' => 'private, no-store',
        ]);
    })->whereNumber('image')->middleware('can:signatures.verify')->name('pdts.signatures.verification.image');
    Route::get('/documents/{document}/versions/{version}', DocumentVersionController::class)
        ->whereNumber(['document', 'version'])
        ->name('pdts.documents.versions.download');
    // Each employee's own signature and signature PIN; the picture is shown only to its owner.
    Route::get('/settings/signature', MySignature::class)->name('settings.signature');
    Route::get('/settings/signature/image', function () {
        // The signature in use, or (?pending=1) the one waiting for ICT; only ever the user's own.
        $path = request()->boolean('pending') ? auth()->user()->pendingSignatureImage()?->storage_path : auth()->user()->signature_path;
        abort_unless($path && Storage::disk('private')->exists($path), 404);

        return response(Storage::disk('private')->get($path), 200, [
            'Content-Type' => str_ends_with($path, '.png') ? 'image/png' : 'image/jpeg',
            'Cache-Control' => 'private, no-store',
        ]);
    })->name('settings.signature.image');
    Route::get('/admin/email-log', EmailLogIndex::class)->name('admin.email_log');

    Route::get('/configurations/roles/index', App\Livewire\Configurations\Roles\Index::class)
        ->middleware('can:settings.manage')
        ->name('configurations.roles.index');

    Route::get('/users/index', UserIndex::class)->middleware('can:users.manage')->name('users.index');
    Route::get('/users/create', UserCreate::class)->middleware('can:users.manage')->name('users.create');
    Route::get('/users/{user}/edit', UserEdit::class)->middleware('can:users.manage')->name('users.edit');
    Route::get('/users/{user}/show', UserShow::class)->middleware('can:users.manage')->name('users.show');

    Route::middleware(['auth'])->get('/support-center', AdminChatCenter::class)->name('support.center');
});

Route::middleware('auth')->group(function () {
    Route::get('/getManNumbers', [UtilsController::class, 'getManNumbers'])->name('getManNumbers');
    Route::get('/getManNumber', [UtilsController::class, 'getManNumber'])->name('getManNumber');
    Route::get('/getEmployee', [UtilsController::class, 'getEmployee'])->name('getEmployee');
    Route::get('/getManNumbersFiltered', [UtilsController::class, 'getManNumbers'])->name('getManNumbersFiltered');
});

require __DIR__.'/settings.php';
require __DIR__.'/movements.php';
