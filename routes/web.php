<?php

use App\Actions\Fortify\CreateNewUser;
use App\Http\Controllers\PdtsDocumentAttachmentController;
use App\Http\Controllers\UtilsController;
use App\Livewire\Admin\EmailLogIndex;
use App\Livewire\Notifications\Index;
use App\Livewire\Pdts\Dashboard;
use App\Livewire\Pdts\Documents\Index as PdtsDocumentIndex;
use App\Livewire\Pdts\Documents\Registration as PdtsDocumentRegistration;
use App\Livewire\Pdts\Documents\Show as PdtsDocumentShow;
use App\Livewire\Pdts\OcrWorkbench;
use App\Livewire\Pdts\Organization\Delegations as PdtsDelegations;
use App\Livewire\Pdts\Organization\Departments as PdtsDepartments;
use App\Livewire\Pdts\Organization\EmployeeAssignments as PdtsEmployeeAssignments;
use App\Livewire\Pdts\Organization\Offices as PdtsOffices;
use App\Livewire\Settings\NotificationPreferences;
use App\Livewire\Support\AdminChatCenter;
use App\Livewire\Users\UserCreate;
use App\Livewire\Users\UserEdit;
use App\Livewire\Users\UserIndex;
use App\Livewire\Users\UserShow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Http\Controllers\RegisteredUserController;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
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
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', Dashboard::class)->name('dashboard');
    Route::get('/documents/register', PdtsDocumentRegistration::class)
        ->middleware('can:documents.register')
        ->name('pdts.documents.register');
    Route::get('/documents', PdtsDocumentIndex::class)
        ->middleware('can:documents.view')
        ->name('pdts.documents.index');
    Route::get('/documents/{document}', PdtsDocumentShow::class)
        ->whereNumber('document')
        ->middleware('can:documents.view')
        ->name('pdts.documents.show');
    Route::get('/documents/{document}/attachments/{attachment}', PdtsDocumentAttachmentController::class)
        ->whereNumber('document')
        ->whereNumber('attachment')
        ->middleware('can:documents.view')
        ->name('pdts.documents.attachments.download');
    Route::get('/ai/ocr', OcrWorkbench::class)
        ->middleware('can:ai.ocr.use')
        ->name('pdts.ai.ocr');
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
    Route::get('/organization/document-types', \App\Livewire\Pdts\Organization\DocumentTypes::class)
        ->middleware('can:document_types.manage')
        ->name('pdts.organization.document-types');
    Route::get('/notifications', Index::class)->name('notifications.index');
    Route::get('/settings/notifications', NotificationPreferences::class)->name('settings.notifications');
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

Route::get('/getManNumbers', [UtilsController::class, 'getManNumbers'])->name('getManNumbers');
Route::get('/getManNumber', [UtilsController::class, 'getManNumber'])->name('getManNumber');
Route::get('/getEmployee', [UtilsController::class, 'getEmployee'])->name('getEmployee');
Route::get('/getManNumbersFiltered', [UtilsController::class, 'getManNumbers'])->name('getManNumbersFiltered');

require __DIR__.'/settings.php';
require __DIR__.'/movements.php';
