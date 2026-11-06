<?php

use App\Actions\Fortify\CreateNewUser;
use App\Http\Controllers\UtilsController;
use App\Livewire\Admin\EmailLogIndex;
use App\Livewire\Pdts\Dashboard;
use App\Livewire\Settings\NotificationPreferences;
use App\Livewire\Support\AdminChatCenter;
use App\Livewire\Users\UserCreate;
use App\Livewire\Users\UserEdit;
use App\Livewire\Users\UserIndex;
use App\Livewire\Users\UserShow;
use App\Livewire\Workflows\WorkflowApprovers;
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
    Route::get('/notifications', App\Livewire\Notifications\Index::class)->name('notifications.index');
    Route::get('/settings/notifications', NotificationPreferences::class)->name('settings.notifications');
    Route::get('/admin/email-log', EmailLogIndex::class)->name('admin.email_log');

    Route::get('/workflow-approvers', WorkflowApprovers::class)
        ->middleware('can:workflows.manage')
        ->name('workflow.approvers');

    Route::get('/configurations/roles/index', App\Livewire\Configurations\Roles\Index::class)
        ->middleware('can:settings.manage')
        ->name('configurations.roles.index');

    Route::get('/users/index', UserIndex::class)->middleware('can:users.manage')->name('users.index');
    Route::get('/users/create', UserCreate::class)->middleware('can:users.manage')->name('users.create');
    Route::get('/users/{user}/edit', UserEdit::class)->middleware('can:users.manage')->name('users.edit');
    Route::get('/users/{user}/show', UserShow::class)->middleware('can:users.manage')->name('users.show');

    Route::get('/configurations/workflows/workflow-definitions/index', App\Livewire\Configurations\Workflows\WorkflowDefinition\Index::class)
        ->middleware('can:workflows.manage')
        ->name('configurations.workflows.workflow_definitions.index');
    Route::get('/configurations/workflows/workflow-definitions/{id}/show', App\Livewire\Configurations\Workflows\WorkflowDefinition\Show::class)
        ->middleware('can:workflows.manage')
        ->name('configurations.workflows.workflow_definitions.show');

    Route::middleware(['auth'])->get('/support-center', AdminChatCenter::class)->name('support.center');
    });

Route::get('/getManNumbers', [UtilsController::class, 'getManNumbers'])->name('getManNumbers');
Route::get('/getManNumber', [UtilsController::class, 'getManNumber'])->name('getManNumber');
Route::get('/getEmployee', [UtilsController::class, 'getEmployee'])->name('getEmployee');
Route::get('/getManNumbersFiltered', [UtilsController::class, 'getManNumbers'])->name('getManNumbersFiltered');

require __DIR__.'/settings.php';
