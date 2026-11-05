<?php

use Illuminate\Support\Facades\Route;
use Modules\AccountModule\app\Models\Account;
use Modules\EmployeeModule\app\Models\Employee;
use Modules\HrModule\app\Http\Controllers\Account\HrSettingAccountModuleController;
use Modules\HrModule\app\Http\Controllers\Account\LeaveAccountModuleController;
use Modules\HrModule\app\Http\Controllers\Employee\LeaveEmployeeModuleController;
use Modules\HrModule\app\Http\Controllers\Account\StaffAccountModuleController;
use Modules\HrModule\app\Http\Controllers\Employee\StaffEmployeeModuleController;

/**
 *  Staff files (docs/hr-plan.md H1): the account and its employees with an HR role (same actions, Concerns\StaffActions).
 *  Permissions: hr.view (list + page), hr.manage (add / edit / status), documents.view (tab + download), documents.manage.
 */
$staffRoutes = function ($controller) {
    Route::get('/', [$controller, 'index'])->name('index')->can('hr.view');
    Route::get('data', [$controller, 'data'])->name('data')->can('hr.view');
    Route::get('create', [$controller, 'create'])->name('create')->can('hr.manage');
    Route::post('/', [$controller, 'store'])->name('store')->can('hr.manage');
    Route::get('{id}', [$controller, 'show'])->name('show')->can('hr.view');
    Route::get('{id}/edit', [$controller, 'edit'])->name('edit')->can('hr.manage');
    Route::put('{id}', [$controller, 'update'])->name('update')->can('hr.manage');
    Route::get('{id}/status', [$controller, 'statusForm'])->name('status')->can('hr.manage');
    Route::patch('{id}/status', [$controller, 'status'])->name('status.update')->can('hr.manage');
    Route::get('{id}/documents/create', [$controller, 'documentCreate'])->name('documents.create')->can('documents.manage');
    Route::post('{id}/documents', [$controller, 'documentStore'])->name('documents.store')->can('documents.manage');
    Route::get('{id}/documents/{document}/edit', [$controller, 'documentEdit'])->name('documents.edit')->can('documents.manage');
    Route::put('{id}/documents/{document}', [$controller, 'documentUpdate'])->name('documents.update')->can('documents.manage');
    Route::delete('{id}/documents/{document}', [$controller, 'documentDestroy'])->name('documents.destroy')->can('documents.manage');
    Route::get('{id}/documents/{document}/files/{file}', [$controller, 'documentFile'])->name('documents.file')->can('documents.view');
};

/**
 *  The Leaves tab popups (docs/hr-plan.md H3, hr.manage; Concerns\LeaveActions), names {area}.hr.leaves.*.
 */
$leaveRoutes = function ($controller) {
    Route::get('{id}/leaves/create', [$controller, 'leaveCreate'])->name('create')->can('hr.manage');
    Route::post('{id}/leaves', [$controller, 'leaveStore'])->name('store')->can('hr.manage');
    Route::get('{id}/leaves/{leave}/cancel', [$controller, 'leaveCancelForm'])->name('cancel.form')->can('hr.manage');
    Route::post('{id}/leaves/{leave}/cancel', [$controller, 'leaveCancel'])->name('cancel')->can('hr.manage');
    Route::get('{id}/leaves/{leave}/file', [$controller, 'leaveFile'])->name('file')->can('hr.manage');
    Route::get('{id}/leave-balances/{type}', [$controller, 'balanceForm'])->name('balance')->can('hr.manage');
    Route::put('{id}/leave-balances/{type}', [$controller, 'balanceUpdate'])->name('balance.update')->can('hr.manage');
};

Route::prefix('account/hr/staff')->name('account.hr.leaves.')->middleware(['auth', 'user.active:' . Account::class])
    ->group(fn () => $leaveRoutes(LeaveAccountModuleController::class));

Route::prefix('employee/hr/staff')->name('employee.hr.leaves.')->middleware(['auth', 'user.active:' . Employee::class])
    ->group(fn () => $leaveRoutes(LeaveEmployeeModuleController::class));

Route::prefix('account/hr/staff')->name('account.hr.staff.')->middleware(['auth', 'user.active:' . Account::class])->group(function () use ($staffRoutes) {
    $staffRoutes(StaffAccountModuleController::class);
    // a file made by mistake (no documents): the owner only
    Route::delete('{id}', [StaffAccountModuleController::class, 'destroy'])->name('destroy');
});

Route::prefix('employee/hr/staff')->name('employee.hr.staff.')->middleware(['auth', 'user.active:' . Employee::class])
    ->group(fn () => $staffRoutes(StaffEmployeeModuleController::class));

/**
 *  HR setup pages (the account only for now, user 2026-11-05; the sidebar's HR section): Employee Departments, Document Types.
 *  (Employee Positions / Shifts = EmployeeModule's account.employee-positions / employee-shifts.) The old Settings › HR url redirects.
 */
Route::prefix('account')->middleware(['auth', 'user.active:' . Account::class])->group(function () {
    Route::redirect('settings/hr', '/account/hr/departments');

    Route::prefix('hr/departments')->name('account.hr.departments.')->group(function () {
        Route::get('/', [HrSettingAccountModuleController::class, 'departments'])->name('index');
        Route::get('data', [HrSettingAccountModuleController::class, 'departmentsData'])->name('data');
        Route::get('create', [HrSettingAccountModuleController::class, 'departmentCreate'])->name('create');
        Route::post('/', [HrSettingAccountModuleController::class, 'departmentStore'])->name('store');
        Route::get('{id}/edit', [HrSettingAccountModuleController::class, 'departmentEdit'])->name('edit');
        Route::put('{id}', [HrSettingAccountModuleController::class, 'departmentUpdate'])->name('update');
        Route::patch('{id}/active', [HrSettingAccountModuleController::class, 'departmentActive'])->name('active');
        Route::delete('{id}', [HrSettingAccountModuleController::class, 'departmentDestroy'])->name('destroy');
    });

    Route::prefix('hr/leave-types')->name('account.hr.leave-types.')->group(function () {
        Route::get('/', [HrSettingAccountModuleController::class, 'leaveTypes'])->name('index');
        Route::get('data', [HrSettingAccountModuleController::class, 'leaveTypesData'])->name('data');
        Route::get('create', [HrSettingAccountModuleController::class, 'leaveTypeCreate'])->name('create');
        Route::post('/', [HrSettingAccountModuleController::class, 'leaveTypeStore'])->name('store');
        Route::get('{id}/edit', [HrSettingAccountModuleController::class, 'leaveTypeEdit'])->name('edit');
        Route::put('{id}', [HrSettingAccountModuleController::class, 'leaveTypeUpdate'])->name('update');
        Route::patch('{id}/active', [HrSettingAccountModuleController::class, 'leaveTypeActive'])->name('active');
        Route::delete('{id}', [HrSettingAccountModuleController::class, 'leaveTypeDestroy'])->name('destroy');
    });

    Route::prefix('hr/document-types')->name('account.hr.document-types.')->group(function () {
        Route::get('/', [HrSettingAccountModuleController::class, 'documentTypes'])->name('index');
        Route::get('data', [HrSettingAccountModuleController::class, 'typesData'])->name('data');
        Route::get('create', [HrSettingAccountModuleController::class, 'typeCreate'])->name('create');
        Route::post('/', [HrSettingAccountModuleController::class, 'typeStore'])->name('store');
        Route::get('{id}/edit', [HrSettingAccountModuleController::class, 'typeEdit'])->name('edit');
        Route::put('{id}', [HrSettingAccountModuleController::class, 'typeUpdate'])->name('update');
        Route::patch('{id}/active', [HrSettingAccountModuleController::class, 'typeActive'])->name('active');
        Route::delete('{id}', [HrSettingAccountModuleController::class, 'typeDestroy'])->name('destroy');
    });
});
