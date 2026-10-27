<?php

use Illuminate\Support\Facades\Route;
use Modules\AccountModule\app\Http\Controllers\Account\AccountAccountModuleController;
use Modules\AccountModule\app\Http\Controllers\Account\GeneralSettingAccountModuleController;
use Modules\AccountModule\app\Http\Controllers\Admin\AccountAdminModuleController;
use Modules\AccountModule\app\Http\Controllers\Guest\AccountGuestModuleController;
use Modules\AccountModule\app\Models\Account;

/**
 *  Guest registration
 */
Route::prefix('account')->name('account.')->middleware('guest')->group(function () {
    Route::get('register', [AccountGuestModuleController::class, 'register'])->name('register');
    Route::post('register', [AccountGuestModuleController::class, 'registerPost'])->name('registerPost');
});

/**
 *  Logged in account
 */
Route::prefix('account')->name('account.')->middleware(['auth', 'user.active:' . Account::class])->group(function () {
    Route::get('profile', [AccountAccountModuleController::class, 'editProfile'])->name('profile.edit');
    Route::put('profile', [AccountAccountModuleController::class, 'updateProfile'])->name('profile.update');
    // Settings › General: withholding tax / VAT switches
    Route::get('settings/general', [GeneralSettingAccountModuleController::class, 'edit'])->name('settings.general.edit');
    Route::put('settings/general', [GeneralSettingAccountModuleController::class, 'update'])->name('settings.general.update');
    Route::get('settings/general/tax-count', [GeneralSettingAccountModuleController::class, 'taxCount'])->name('settings.general.tax-count');
});

/**
 *  Admin
 */
Route::prefix('admin/accounts')->name('admin.accounts.')->middleware('auth:admin')->group(function () {
    Route::get('/', [AccountAdminModuleController::class, 'index'])->name('index');
    Route::get('data', [AccountAdminModuleController::class, 'data'])->name('data');
    Route::get('create', [AccountAdminModuleController::class, 'create'])->name('create');
    Route::post('/', [AccountAdminModuleController::class, 'store'])->name('store');
    Route::get('{id}', [AccountAdminModuleController::class, 'show'])->name('show');
    Route::get('{id}/edit', [AccountAdminModuleController::class, 'edit'])->name('edit');
    Route::put('{id}', [AccountAdminModuleController::class, 'update'])->name('update');
    Route::patch('{id}/status', [AccountAdminModuleController::class, 'changeStatus'])->name('status');
    Route::delete('{id}', [AccountAdminModuleController::class, 'destroy'])->name('destroy');
    Route::patch('{id}/restore', [AccountAdminModuleController::class, 'restore'])->name('restore');
});
