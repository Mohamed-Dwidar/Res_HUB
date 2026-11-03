<?php

use Illuminate\Support\Facades\Route;
use Modules\AccountModule\app\Models\Account;
use Modules\EmployeeModule\app\Models\Employee;
use Modules\PackageModule\app\Http\Controllers\Account\PackageAccountModuleController;
use Modules\PackageModule\app\Http\Controllers\Employee\PackageEmployeeModuleController;

/**
 *  Packages: managed by the account and by its employees (same actions, see Concerns\PackageActions)
 */
$packageRoutes = function ($controller) {
    Route::get('/', [$controller, 'index'])->name('index')->can('packages.view');
    Route::get('data', [$controller, 'data'])->name('data')->can('packages.view');
    Route::get('create', [$controller, 'create'])->name('create')->can('packages.create');
    // Add Package: "<member> - <m-Y>[ - N]" for the chosen member
    Route::get('suggest-name', [$controller, 'suggestName'])->name('suggest-name')->can('packages.create');
    Route::post('/', [$controller, 'store'])->name('store')->can('packages.create');
    Route::get('{id}', [$controller, 'show'])->name('show')->can('packages.view');
    // the customer package statement (PDF)
    Route::get('{id}/statement', [$controller, 'statement'])->name('statement')->can('packages.statement');
    Route::get('{id}/edit', [$controller, 'edit'])->name('edit')->can('packages.edit');
    Route::put('{id}', [$controller, 'update'])->name('update')->can('packages.edit');
    Route::delete('{id}', [$controller, 'destroy'])->name('destroy')->can('packages.delete');
};

Route::prefix('account/packages')->name('account.packages.')->middleware(['auth', 'user.active:' . Account::class])
    ->group(fn () => $packageRoutes(PackageAccountModuleController::class));

Route::prefix('employee/packages')->name('employee.packages.')->middleware(['auth', 'user.active:' . Employee::class])
    ->group(fn () => $packageRoutes(PackageEmployeeModuleController::class));
