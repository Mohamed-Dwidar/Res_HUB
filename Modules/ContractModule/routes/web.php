<?php

use Illuminate\Support\Facades\Route;
use Modules\AccountModule\app\Models\Account;
use Modules\ContractModule\app\Http\Controllers\Account\ContractSettingAccountModuleController;

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
