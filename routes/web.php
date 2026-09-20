<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\MessageController;
use App\Http\Controllers\Admin\PermissionController;
use App\Http\Controllers\Admin\RfqCommentController;
use App\Http\Controllers\Admin\RfqController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\LoginController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->name('login.store');
});

Route::post('logout', [LoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

Route::prefix('admin')->name('admin.')->middleware('auth')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    Route::resource('users', UserController::class)->except(['show', 'create', 'edit']);
    Route::patch('roles/{role}/toggle', [RoleController::class, 'toggleStatus'])->name('roles.toggle');
    Route::resource('roles', RoleController::class)->except('show');
    Route::resource('permissions', PermissionController::class)->except('show');
    Route::patch('rfqs/{rfq}/assign', [RfqController::class, 'assign'])->name('rfqs.assign');
    Route::patch('rfqs/{rfq}/assign-operations', [RfqController::class, 'assignOperations'])->name('rfqs.assign-operations');
    Route::patch('rfqs/{rfq}/complete-sourcing', [RfqController::class, 'completeSourcing'])->name('rfqs.complete-sourcing');
    Route::patch('rfqs/{rfq}/return-sourcing', [RfqController::class, 'returnSourcing'])->name('rfqs.return-sourcing');
    Route::patch('rfqs/{rfq}/complete-data-entry', [RfqController::class, 'completeDataEntry'])->name('rfqs.complete-data-entry');
    Route::patch('rfqs/{rfq}/complete-senior-ops-review', [RfqController::class, 'completeSeniorOpsReview'])->name('rfqs.complete-senior-ops-review');
    Route::patch('rfqs/{rfq}/approve-senior-ops-part', [RfqController::class, 'approveSeniorOpsPart'])->name('rfqs.approve-senior-ops-part');
    Route::patch('rfqs/{rfq}/approve-head-of-bd', [RfqController::class, 'approveHeadOfBd'])->name('rfqs.approve-head-of-bd');
    Route::patch('rfqs/{rfq}/approve-head-of-bd-part', [RfqController::class, 'approveHeadOfBdPart'])->name('rfqs.approve-head-of-bd-part');
    Route::patch('rfqs/{rfq}/reject-head-of-bd', [RfqController::class, 'rejectHeadOfBd'])->name('rfqs.reject-head-of-bd');
    Route::patch('rfqs/{rfq}/gm-assistant-details', [RfqController::class, 'submitGmAssistantDetails'])->name('rfqs.gm-assistant-details');
    Route::patch('rfqs/{rfq}/approve-gm', [RfqController::class, 'approveGm'])->name('rfqs.approve-gm');
    Route::patch('rfqs/{rfq}/approve-gm-part', [RfqController::class, 'approveGmPart'])->name('rfqs.approve-gm-part');
    Route::patch('rfqs/{rfq}/close', [RfqController::class, 'close'])->name('rfqs.close');
    Route::patch('rfqs/{rfq}/close-part', [RfqController::class, 'closePart'])->name('rfqs.close-part');
    Route::resource('rfqs', RfqController::class)->except(['create', 'edit']);
    Route::post('rfqs/{rfq}/comments', [RfqCommentController::class, 'store'])->name('rfqs.comments.store');
    Route::get('messages', [MessageController::class, 'index'])->name('messages.index');
    Route::get('messages/{user}', [MessageController::class, 'show'])->name('messages.show');
    Route::post('messages', [MessageController::class, 'store'])->middleware('throttle:30,1')->name('messages.store');
    Route::delete('rfqs/{rfq}/comments/{comment}', [RfqCommentController::class, 'destroy'])->name('rfqs.comments.destroy');
});
