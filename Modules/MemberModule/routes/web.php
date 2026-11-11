<?php

use Illuminate\Support\Facades\Route;
use Modules\AccountModule\app\Models\Account;
use Modules\EmployeeModule\app\Models\Employee;
use Modules\MemberModule\app\Http\Controllers\Account\MemberAccountModuleController;
use Modules\MemberModule\app\Http\Controllers\Employee\MemberEmployeeModuleController;

/**
 *  Members: managed by the account and by its employees (same actions, see Concerns\MemberActions); delete = the account only
 */
$memberRoutes = function ($controller) {
    Route::get('/', [$controller, 'index'])->name('index')->can('members.view');
    Route::get('data', [$controller, 'data'])->name('data')->can('members.view');
    // the Member drop menus of the reservation / package / cash in / withholding forms: search + the quick add duplicate check
    Route::get('search', [$controller, 'search'])->name('search')->can('members.pick');
    Route::get('commitment', [$controller, 'commitment'])->name('commitment')->can('members.pick');
    Route::get('lookup', [$controller, 'lookup'])->name('lookup')->can('members.create');
    Route::get('create', [$controller, 'create'])->name('create')->can('members.create');
    Route::post('/', [$controller, 'store'])->name('store')->can('members.create');
    Route::get('{id}', [$controller, 'show'])->name('show')->can('members.view');
    Route::get('{id}/edit', [$controller, 'edit'])->name('edit')->can('members.edit');
    Route::put('{id}', [$controller, 'update'])->name('update')->can('members.edit');
};

Route::prefix('account/members')->name('account.members.')->middleware(['auth', 'user.active:' . Account::class])->group(function () use ($memberRoutes) {
    $memberRoutes(MemberAccountModuleController::class);
    Route::delete('{id}', [MemberAccountModuleController::class, 'destroy'])->name('destroy');
});

Route::prefix('employee/members')->name('employee.members.')->middleware(['auth', 'user.active:' . Employee::class])
    ->group(fn () => $memberRoutes(MemberEmployeeModuleController::class));
