<?php

use Illuminate\Support\Facades\Route;
use Modules\AccountModule\app\Models\Account;
use Modules\EmployeeModule\app\Models\Employee;
use Modules\MessagingModule\app\Http\Controllers\Account\MessageAccountModuleController;
use Modules\MessagingModule\app\Http\Controllers\Account\MessageSettingAccountModuleController;
use Modules\MessagingModule\app\Http\Controllers\Employee\MessageEmployeeModuleController;

/**
 *  WhatsApp messages: the popup + Send, used by the account and by its employees (same actions, see Concerns\MessageActions);
 *  {kind} = what the message is about (reservation now; package / payment later)
 */
$messageRoutes = function ($controller) {
    Route::get('{kind}/{id}', [$controller, 'show'])->name('show')->where('kind', 'reservation');
    Route::post('{kind}/{id}', [$controller, 'send'])->name('send')->where('kind', 'reservation');
};

Route::prefix('account/messages')->name('account.messages.')->middleware(['auth', 'user.active:' . Account::class])
    ->group(fn () => $messageRoutes(MessageAccountModuleController::class));

Route::prefix('employee/messages')->name('employee.messages.')->middleware(['auth', 'user.active:' . Employee::class])
    ->group(fn () => $messageRoutes(MessageEmployeeModuleController::class));

/**
 *  Settings › WhatsApp Messages: only the account (settings + the texts: a default per type, space versions)
 */
Route::prefix('account/settings/messages')->name('account.settings.messages.')->middleware(['auth', 'user.active:' . Account::class])->group(function () {
    Route::get('/', [MessageSettingAccountModuleController::class, 'edit'])->name('edit');
    Route::put('/', [MessageSettingAccountModuleController::class, 'update'])->name('update');
    Route::get('templates/create', [MessageSettingAccountModuleController::class, 'createTemplate'])->name('templates.create');
    Route::post('templates', [MessageSettingAccountModuleController::class, 'storeTemplate'])->name('templates.store');
    Route::get('templates/{id}/edit', [MessageSettingAccountModuleController::class, 'editTemplate'])->name('templates.edit');
    Route::put('templates/{id}', [MessageSettingAccountModuleController::class, 'updateTemplate'])->name('templates.update');
    Route::delete('templates/{id}', [MessageSettingAccountModuleController::class, 'destroyTemplate'])->name('templates.destroy');
});
