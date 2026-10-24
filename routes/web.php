<?php

use App\Http\Controllers\Admin\AttendanceController;
use App\Http\Controllers\Admin\BackupController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\Admin\JobCategoryController;
use App\Http\Controllers\Admin\LivePulseController;
use App\Http\Controllers\Admin\LogController;
use App\Http\Controllers\Admin\MessageController;
use App\Http\Controllers\Admin\NotificationController;
use App\Http\Controllers\Admin\PerformanceReportController;
use App\Http\Controllers\Admin\PermissionController;
use App\Http\Controllers\Admin\RfqCommentController;
use App\Http\Controllers\Admin\RfqController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\TimeSpentReportController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\UserPhotoController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\SetPasswordController;
use App\Http\Middleware\CaptureLiveVersion;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureRfqIsVisible;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->name('login.store');

    // A new account's welcome email links here — see App\Notifications\AccountCreated.
    Route::get('set-password/{token}', [SetPasswordController::class, 'create'])->name('password.set');
    Route::post('set-password', [SetPasswordController::class, 'store'])->middleware('throttle:10,1')->name('password.set.store');
});

Route::post('logout', [LoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

Route::prefix('admin')->name('admin.')->middleware(['auth', EnsureAccountIsActive::class, CaptureLiveVersion::class, EnsureRfqIsVisible::class])->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('live', LivePulseController::class)->name('live');
    Route::get('critical-monitor', [DashboardController::class, 'critical'])->name('critical-monitor');

    Route::get('settings', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::patch('settings/profile', [SettingsController::class, 'updateProfile'])->name('settings.profile');
    Route::put('settings/password', [SettingsController::class, 'updatePassword'])->name('settings.password');
    Route::patch('settings/preferences', [SettingsController::class, 'updatePreferences'])->name('settings.preferences');
    Route::patch('settings/application', [SettingsController::class, 'updateApplication'])->name('settings.application');
    Route::patch('settings/working-hours', [SettingsController::class, 'updateWorkingHours'])->name('settings.working-hours');
    Route::patch('settings/sourcing-targets', [SettingsController::class, 'updateSourcingTargets'])->name('settings.sourcing-targets');
    Route::patch('settings/data-entry-targets', [SettingsController::class, 'updateDataEntryTargets'])->name('settings.data-entry-targets');
    Route::patch('settings/finalize-targets', [SettingsController::class, 'updateFinalizeTargets'])->name('settings.finalize-targets');
    Route::patch('settings/gm-assistant-targets', [SettingsController::class, 'updateGmAssistantTargets'])->name('settings.gm-assistant-targets');
    Route::patch('settings/gm-assistant-per-item', [SettingsController::class, 'updateGmAssistantPerItem'])->name('settings.gm-assistant-per-item');
    Route::patch('settings/data-entry-per-item', [SettingsController::class, 'updateDataEntryPerItem'])->name('settings.data-entry-per-item');
    Route::patch('settings/rfq-numbers', [SettingsController::class, 'updateRfqNumbers'])->name('settings.rfq-numbers');
    Route::delete('settings/rfq-data', [SettingsController::class, 'clearRfqData'])->middleware('throttle:5,1')->name('settings.rfq-data.clear');
    Route::patch('settings/idle-alert', [SettingsController::class, 'updateIdleAlert'])->name('settings.idle-alert');
    Route::patch('settings/gm-assistant-idle-alert', [SettingsController::class, 'updateGmAssistantIdleAlert'])->name('settings.gm-assistant-idle-alert');
    Route::patch('settings/wc-validation', [SettingsController::class, 'updateWcValidation'])->name('settings.wc-validation');
    Route::post('settings/extra-hours', [SettingsController::class, 'storeExtraHours'])->name('settings.extra-hours.store');
    Route::delete('settings/extra-hours/{extraHours}', [SettingsController::class, 'destroyExtraHours'])->name('settings.extra-hours.destroy');
    Route::patch('settings/half-day', [SettingsController::class, 'updateHalfDay'])->name('settings.half-day');
    Route::put('settings/role-hours/{role}', [SettingsController::class, 'updateRoleHours'])->name('settings.role-hours.update');
    Route::delete('settings/role-hours/{role}', [SettingsController::class, 'destroyRoleHours'])->name('settings.role-hours.destroy');
    Route::put('settings/user-hours/{user}', [SettingsController::class, 'updateUserHours'])->name('settings.user-hours.update');
    Route::delete('settings/user-hours/{user}', [SettingsController::class, 'destroyUserHours'])->name('settings.user-hours.destroy');

    Route::get('reports/time-spent', TimeSpentReportController::class)->name('reports.time-spent');
    Route::get('reports/sourcing-daily', [PerformanceReportController::class, 'sourcingDaily'])->name('reports.sourcing-daily');
    Route::get('reports/data-entry-daily', [PerformanceReportController::class, 'dataEntryDaily'])->name('reports.data-entry-daily');
    Route::get('reports/sourcing-monthly', [PerformanceReportController::class, 'sourcingMonthly'])->name('reports.sourcing-monthly');
    Route::get('reports/rfq-tracking', [PerformanceReportController::class, 'rfqTracking'])->name('reports.rfq-tracking');

    Route::get('attendance', [AttendanceController::class, 'index'])->name('attendance.index');
    Route::post('attendance', [AttendanceController::class, 'store'])->name('attendance.store');
    Route::get('attendance/{sheet}', [AttendanceController::class, 'show'])->name('attendance.show');
    Route::put('attendance/{sheet}', [AttendanceController::class, 'update'])->name('attendance.update');
    Route::patch('attendance/{sheet}/approve', [AttendanceController::class, 'approve'])->name('attendance.approve');
    Route::patch('attendance/{sheet}/return', [AttendanceController::class, 'sendBack'])->name('attendance.return');

    // The job categories (JobCategory) the Assign Sourcing wizard offers — Admin only, see JobCategoryController.
    Route::get('categories', [JobCategoryController::class, 'index'])->name('categories.index');
    Route::post('categories', [JobCategoryController::class, 'store'])->name('categories.store');
    Route::put('categories/{category}', [JobCategoryController::class, 'update'])->name('categories.update');
    Route::delete('categories/{category}', [JobCategoryController::class, 'destroy'])->name('categories.destroy');

    // Backups (App\BackupManager) — Admin only, see BackupController.
    Route::get('backups', [BackupController::class, 'index'])->name('backups.index');
    Route::post('backups', [BackupController::class, 'store'])->middleware('throttle:6,1')->name('backups.store');
    Route::post('backups/upload', [BackupController::class, 'upload'])->name('backups.upload');
    Route::patch('backups/schedule', [BackupController::class, 'updateSchedule'])->name('backups.schedule');
    Route::get('backups/{backup}/download', [BackupController::class, 'download'])->where('backup', '[A-Za-z0-9_-]+\.zip')->name('backups.download');
    Route::post('backups/{backup}/restore', [BackupController::class, 'restore'])->where('backup', '[A-Za-z0-9_-]+\.zip')->middleware('throttle:5,1')->name('backups.restore');
    Route::delete('backups/{backup}', [BackupController::class, 'destroy'])->where('backup', '[A-Za-z0-9_-]+\.zip')->name('backups.destroy');

    Route::get('logs', [LogController::class, 'index'])->name('logs.index');
    Route::get('logs/download', [LogController::class, 'download'])->name('logs.download');
    Route::resource('users', UserController::class)->except(['create', 'edit']);
    Route::post('users/{user}/password-link', [UserController::class, 'sendPasswordLink'])->middleware('throttle:10,1')->name('users.password-link');
    Route::patch('users/{user}/default-data-entry', [UserController::class, 'updateDefaultDataEntry'])->name('users.default-data-entry');
    Route::patch('users/{user}/deactivate', [UserController::class, 'deactivate'])->name('users.deactivate');
    Route::patch('users/{user}/activate', [UserController::class, 'activate'])->name('users.activate');
    // An Admin signing in as someone else, and back — see App\Impersonation.
    Route::post('users/{user}/impersonate', [ImpersonationController::class, 'store'])->name('users.impersonate');
    Route::post('impersonation/leave', [ImpersonationController::class, 'destroy'])->name('impersonation.leave');

    // Profile photos: seen by anyone signed in; changed by the person, or whoever keeps people's details.
    Route::get('users/{user}/photo', [UserPhotoController::class, 'show'])->name('users.photo');
    Route::post('users/{user}/photo', [UserPhotoController::class, 'update'])->name('users.photo.update');
    Route::delete('users/{user}/photo', [UserPhotoController::class, 'destroy'])->name('users.photo.destroy');
    Route::patch('roles/{role}/toggle', [RoleController::class, 'toggleStatus'])->name('roles.toggle');
    Route::resource('roles', RoleController::class)->except('show');
    Route::resource('permissions', PermissionController::class)->except('show');
    Route::patch('rfqs/{rfq}/assign', [RfqController::class, 'assign'])->name('rfqs.assign');
    Route::patch('rfqs/{rfq}/assign-operations', [RfqController::class, 'assignOperations'])->name('rfqs.assign-operations');
    Route::patch('rfqs/{rfq}/request-details', [RfqController::class, 'requestDetails'])->name('rfqs.request-details');
    Route::patch('rfqs/{rfq}/status', [RfqController::class, 'changeStatus'])->name('rfqs.change-status');
    Route::patch('rfqs/{rfq}/complete-sourcing', [RfqController::class, 'completeSourcing'])->name('rfqs.complete-sourcing');
    Route::patch('rfqs/{rfq}/return-sourcing', [RfqController::class, 'returnSourcing'])->name('rfqs.return-sourcing');
    Route::patch('rfqs/{rfq}/complete-data-entry', [RfqController::class, 'completeDataEntry'])->name('rfqs.complete-data-entry');
    Route::patch('rfqs/{rfq}/finalize', [RfqController::class, 'finalize'])->name('rfqs.finalize');
    Route::patch('rfqs/{rfq}/return-data-entry', [RfqController::class, 'returnDataEntry'])->name('rfqs.return-data-entry');
    Route::patch('rfqs/{rfq}/complete-senior-ops-review', [RfqController::class, 'completeSeniorOpsReview'])->name('rfqs.complete-senior-ops-review');
    Route::patch('rfqs/{rfq}/approve-senior-ops-part', [RfqController::class, 'approveSeniorOpsPart'])->name('rfqs.approve-senior-ops-part');
    Route::patch('rfqs/{rfq}/reject-senior-ops', [RfqController::class, 'rejectSeniorOps'])->name('rfqs.reject-senior-ops');
    Route::patch('rfqs/{rfq}/approve-head-of-bd', [RfqController::class, 'approveHeadOfBd'])->name('rfqs.approve-head-of-bd');
    Route::patch('rfqs/{rfq}/approve-head-of-bd-part', [RfqController::class, 'approveHeadOfBdPart'])->name('rfqs.approve-head-of-bd-part');
    Route::patch('rfqs/{rfq}/reject-head-of-bd', [RfqController::class, 'rejectHeadOfBd'])->name('rfqs.reject-head-of-bd');
    Route::patch('rfqs/{rfq}/gm-assistant-details', [RfqController::class, 'submitGmAssistantDetails'])->name('rfqs.gm-assistant-details');
    Route::patch('rfqs/{rfq}/approve-gm', [RfqController::class, 'approveGm'])->name('rfqs.approve-gm');
    Route::patch('rfqs/{rfq}/approve-gm-part', [RfqController::class, 'approveGmPart'])->name('rfqs.approve-gm-part');
    Route::patch('rfqs/{rfq}/reject-gm', [RfqController::class, 'rejectGm'])->name('rfqs.reject-gm');
    Route::patch('rfqs/{rfq}/reject-gm-assistant', [RfqController::class, 'rejectGmAssistant'])->name('rfqs.reject-gm-assistant');
    Route::patch('rfqs/{rfq}/reject-bd', [RfqController::class, 'rejectBd'])->name('rfqs.reject-bd');
    Route::patch('rfqs/{rfq}/return-senior-ops', [RfqController::class, 'returnSeniorOps'])->name('rfqs.return-senior-ops');
    Route::patch('rfqs/{rfq}/pass-back-sourcing', [RfqController::class, 'passBackToSourcing'])->name('rfqs.pass-back-sourcing');
    Route::patch('rfqs/{rfq}/forward-back', [RfqController::class, 'forwardBack'])->name('rfqs.forward-back');
    Route::patch('rfqs/{rfq}/reassign-sourcing', [RfqController::class, 'reassignSourcing'])->name('rfqs.reassign-sourcing');
    Route::patch('rfqs/{rfq}/pause-data-entry', [RfqController::class, 'pauseDataEntry'])->name('rfqs.pause-data-entry');
    Route::patch('rfqs/{rfq}/resume-data-entry', [RfqController::class, 'resumeDataEntry'])->name('rfqs.resume-data-entry');
    Route::patch('rfqs/{rfq}/start-data-entry', [RfqController::class, 'startDataEntry'])->name('rfqs.start-data-entry');
    Route::patch('rfqs/{rfq}/start-gm-assistant', [RfqController::class, 'startGmAssistant'])->name('rfqs.start-gm-assistant');
    Route::patch('rfqs/{rfq}/pause-gm-assistant', [RfqController::class, 'pauseGmAssistant'])->name('rfqs.pause-gm-assistant');
    Route::patch('rfqs/{rfq}/resume-gm-assistant', [RfqController::class, 'resumeGmAssistant'])->name('rfqs.resume-gm-assistant');
    Route::patch('rfqs/{rfq}/close', [RfqController::class, 'close'])->name('rfqs.close');
    Route::patch('rfqs/{rfq}/close-part', [RfqController::class, 'closePart'])->name('rfqs.close-part');
    // Before the resource, so "suggest" isn't taken for an RFQ.
    Route::get('rfqs/suggest', [RfqController::class, 'suggest'])->name('rfqs.suggest');
    Route::resource('rfqs', RfqController::class)->except(['create', 'edit', 'destroy']);
    Route::post('rfqs/{rfq}/comments', [RfqCommentController::class, 'store'])->name('rfqs.comments.store');
    Route::get('notifications/{notification}', [NotificationController::class, 'open'])->name('notifications.open');
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::get('messages', [MessageController::class, 'index'])->name('messages.index');
    Route::get('messages/{user}', [MessageController::class, 'show'])->name('messages.show');
    Route::post('messages', [MessageController::class, 'store'])->middleware('throttle:30,1')->name('messages.store');
    Route::delete('rfqs/{rfq}/comments/{comment}', [RfqCommentController::class, 'destroy'])->name('rfqs.comments.destroy');
    Route::get('rfq-attachments/{attachment}', [RfqCommentController::class, 'attachment'])->name('rfqs.attachments.show');
});
