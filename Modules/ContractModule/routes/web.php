<?php

use Illuminate\Support\Facades\Route;
use Modules\AccountModule\app\Models\Account;
use Modules\ContractModule\app\Http\Controllers\Account\ContractAccountModuleController;
use Modules\ContractModule\app\Http\Controllers\Account\ContractSettingAccountModuleController;
use Modules\ContractModule\app\Http\Controllers\Employee\ContractEmployeeModuleController;
use Modules\EmployeeModule\app\Models\Employee;

/**
 *  Settings › Contracts: only the account (the lessor data + number start, the templates, each assigned to units).
 *  The contracts themselves (account + employees) come in Phase 2 (docs/contracts-plan.md).
 */
Route::prefix('account/settings/contracts')->name('account.settings.contracts.')->middleware(['auth', 'user.active:' . Account::class])->group(function () {
    Route::get('/', [ContractSettingAccountModuleController::class, 'edit'])->name('edit');
    Route::put('/', [ContractSettingAccountModuleController::class, 'update'])->name('update');
    Route::get('templates/create', [ContractSettingAccountModuleController::class, 'createTemplate'])->name('templates.create');
    Route::post('templates', [ContractSettingAccountModuleController::class, 'storeTemplate'])->name('templates.store');
    Route::get('templates/{id}/edit', [ContractSettingAccountModuleController::class, 'editTemplate'])->name('templates.edit');
    Route::put('templates/{id}', [ContractSettingAccountModuleController::class, 'updateTemplate'])->name('templates.update');
    Route::delete('templates/{id}', [ContractSettingAccountModuleController::class, 'destroyTemplate'])->name('templates.destroy');
});

/**
 *  Contracts (Phase 2): the account and its employees (same actions, see Concerns\ContractActions; who may do what =
 *  ContractService::can(); a draft's wording = its page's editors, the owner AND the employees since 2026-10-31). Made by hand from a reservation (create?reservation_id=).
 */
$contractRoutes = function ($controller) {
    Route::get('/', [$controller, 'index'])->name('index')->can('contracts.view');
    Route::get('data', [$controller, 'data'])->name('data')->can('contracts.view');
    Route::get('create', [$controller, 'create'])->name('create')->can('contracts.draft');
    Route::post('/', [$controller, 'store'])->name('store')->can('contracts.draft');
    Route::get('{id}', [$controller, 'show'])->name('show')->can('contracts.view');
    Route::get('{id}/edit', [$controller, 'edit'])->name('edit')->can('contracts.draft');
    Route::put('{id}', [$controller, 'update'])->name('update')->can('contracts.draft');
    Route::post('{id}/issue', [$controller, 'issue'])->name('issue')->can('contracts.issue');
    Route::get('{id}/pdf', [$controller, 'pdf'])->name('pdf')->can('contracts.pdf');
    Route::get('{id}/signed', [$controller, 'signedForm'])->name('signed')->can('contracts.upload_signed');
    Route::post('{id}/signed', [$controller, 'signed'])->name('signed.store')->can('contracts.upload_signed');
    Route::get('{id}/signed/file', [$controller, 'signedFile'])->name('signed.file')->can('contracts.view');
    Route::delete('{id}', [$controller, 'destroy'])->name('destroy')->can('contracts.delete_own_draft');
};

Route::prefix('account/contracts')->name('account.contracts.')->middleware(['auth', 'user.active:' . Account::class])
    ->group(fn () => $contractRoutes(ContractAccountModuleController::class));

Route::prefix('employee/contracts')->name('employee.contracts.')->middleware(['auth', 'user.active:' . Employee::class])
    ->group(fn () => $contractRoutes(ContractEmployeeModuleController::class));
