<?php

use Illuminate\Support\Facades\Route;
use Modules\AccountModule\app\Models\Account;
use Modules\EmployeeModule\app\Http\Controllers\Account\AccessLogAccountModuleController;
use Modules\EmployeeModule\app\Http\Controllers\Account\EmployeeAccountModuleController;
use Modules\EmployeeModule\app\Http\Controllers\Account\EmployeePositionAccountModuleController;
use Modules\EmployeeModule\app\Http\Controllers\Account\EmployeeShiftAccountModuleController;
use Modules\EmployeeModule\app\Http\Controllers\Account\RoleAccountModuleController;

/**
 *  Logged in account: the positions / shifts lists (+ the old employees urls → HR › Staff)
 *  (the employee's own pages: LayoutModule employee.dashboard, his password: UserModule user.account.edit)
 */
Route::prefix('account')->name('account.')->middleware(['auth', 'user.active:' . Account::class])->group(function () {
    // the old Users (logins) pages, removed 2026-11-04: HR › Staff (System Access in the staff form); old GET urls redirect there
    Route::prefix('employees')->name('employees.')->group(function () {
        Route::get('/', [EmployeeAccountModuleController::class, 'redirectToStaff'])->name('index');
        Route::get('create', [EmployeeAccountModuleController::class, 'redirectToStaff'])->name('create');
        Route::get('{id}', [EmployeeAccountModuleController::class, 'redirectToStaff'])->name('show');
        Route::get('{id}/edit', [EmployeeAccountModuleController::class, 'redirectToStaff'])->name('edit');
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

    // Management › Access Log (docs/roles-plan.md): the employees' logins / logouts / timeouts / exports, read only
    Route::prefix('access-log')->name('access-log.')->group(function () {
        Route::get('/', [AccessLogAccountModuleController::class, 'index'])->name('index');
        Route::get('data', [AccessLogAccountModuleController::class, 'data'])->name('data');
    });

    // Settings › Roles (docs/roles-plan.md, R0): what each employee may do; the owner only
    Route::prefix('settings/roles')->name('settings.roles.')->group(function () {
        Route::get('/', [RoleAccountModuleController::class, 'index'])->name('index');
        Route::get('data', [RoleAccountModuleController::class, 'data'])->name('data');
        Route::get('create', [RoleAccountModuleController::class, 'create'])->name('create');
        Route::post('/', [RoleAccountModuleController::class, 'store'])->name('store');
        Route::get('{id}/edit', [RoleAccountModuleController::class, 'edit'])->name('edit');
        Route::put('{id}', [RoleAccountModuleController::class, 'update'])->name('update');
        Route::delete('{id}', [RoleAccountModuleController::class, 'destroy'])->name('destroy');
    });
});
