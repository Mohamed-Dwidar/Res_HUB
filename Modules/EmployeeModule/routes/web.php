<?php

use Illuminate\Support\Facades\Route;
use Modules\AccountModule\app\Models\Account;
use Modules\EmployeeModule\app\Http\Controllers\Account\EmployeeAccountModuleController;
use Modules\EmployeeModule\app\Http\Controllers\Account\EmployeePositionAccountModuleController;
use Modules\EmployeeModule\app\Http\Controllers\Account\EmployeeShiftAccountModuleController;

/**
 *  Logged in account: employees + their positions / shifts lists
 *  (the employee's own pages: LayoutModule employee.dashboard, his password: UserModule user.account.edit)
 */
Route::prefix('account')->name('account.')->middleware(['auth', 'user.active:' . Account::class])->group(function () {
    Route::prefix('employees')->name('employees.')->group(function () {
        Route::get('/', [EmployeeAccountModuleController::class, 'index'])->name('index');
        Route::get('data', [EmployeeAccountModuleController::class, 'data'])->name('data');
        Route::get('create', [EmployeeAccountModuleController::class, 'create'])->name('create');
        Route::post('/', [EmployeeAccountModuleController::class, 'store'])->name('store');
        Route::get('{id}', [EmployeeAccountModuleController::class, 'show'])->name('show');
        Route::get('{id}/edit', [EmployeeAccountModuleController::class, 'edit'])->name('edit');
        Route::put('{id}', [EmployeeAccountModuleController::class, 'update'])->name('update');
        Route::delete('{id}', [EmployeeAccountModuleController::class, 'destroy'])->name('destroy');
        Route::patch('{id}/left', [EmployeeAccountModuleController::class, 'left'])->name('left');
        Route::patch('{id}/left/undo', [EmployeeAccountModuleController::class, 'undoLeft'])->name('left.undo');
        Route::get('{id}/attachments/{attachmentId}', [EmployeeAccountModuleController::class, 'downloadAttachment'])->name('attachments.download');
    });

    Route::prefix('employee-positions')->name('employee-positions.')->group(function () {
        Route::get('/', [EmployeePositionAccountModuleController::class, 'index'])->name('index');
        Route::get('data', [EmployeePositionAccountModuleController::class, 'data'])->name('data');
        Route::get('create', [EmployeePositionAccountModuleController::class, 'create'])->name('create');
        Route::post('/', [EmployeePositionAccountModuleController::class, 'store'])->name('store');
        Route::get('{id}/edit', [EmployeePositionAccountModuleController::class, 'edit'])->name('edit');
        Route::put('{id}', [EmployeePositionAccountModuleController::class, 'update'])->name('update');
        Route::patch('{id}/active', [EmployeePositionAccountModuleController::class, 'toggleActive'])->name('active');
        Route::delete('{id}', [EmployeePositionAccountModuleController::class, 'destroy'])->name('destroy');
    });

    Route::prefix('employee-shifts')->name('employee-shifts.')->group(function () {
        Route::get('/', [EmployeeShiftAccountModuleController::class, 'index'])->name('index');
        Route::get('data', [EmployeeShiftAccountModuleController::class, 'data'])->name('data');
        Route::get('create', [EmployeeShiftAccountModuleController::class, 'create'])->name('create');
        Route::post('/', [EmployeeShiftAccountModuleController::class, 'store'])->name('store');
        Route::get('{id}/edit', [EmployeeShiftAccountModuleController::class, 'edit'])->name('edit');
        Route::put('{id}', [EmployeeShiftAccountModuleController::class, 'update'])->name('update');
        Route::patch('{id}/active', [EmployeeShiftAccountModuleController::class, 'toggleActive'])->name('active');
        Route::delete('{id}', [EmployeeShiftAccountModuleController::class, 'destroy'])->name('destroy');
    });
});
