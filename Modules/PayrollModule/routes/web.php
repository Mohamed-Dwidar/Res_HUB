<?php

use Illuminate\Support\Facades\Route;
use Modules\AccountModule\app\Models\Account;
use Modules\EmployeeModule\app\Models\Employee;
use Modules\PayrollModule\app\Http\Controllers\Account\PayrollSettingAccountModuleController;
use Modules\PayrollModule\app\Http\Controllers\Account\SalaryAccountModuleController;
use Modules\PayrollModule\app\Http\Controllers\Employee\SalaryEmployeeModuleController;

/**
 *  The staff page's Salary tab popups (docs/hr-plan.md H2): the account and its employees with hr.salary (Concerns\SalaryActions).
 *  Under the staff urls ({area}/hr/staff/{id}/…), names {area}.payroll.*.
 */
$salaryRoutes = function ($controller) {
    Route::get('{id}/salaries/create', [$controller, 'salaryCreate'])->name('salaries.create')->can('hr.salary');
    Route::post('{id}/salaries', [$controller, 'salaryStore'])->name('salaries.store')->can('hr.salary');
    Route::get('{id}/salaries/{salary}/edit', [$controller, 'salaryEdit'])->name('salaries.edit')->can('hr.salary');
    Route::put('{id}/salaries/{salary}', [$controller, 'salaryUpdate'])->name('salaries.update')->can('hr.salary');
    Route::delete('{id}/salaries/{salary}', [$controller, 'salaryDestroy'])->name('salaries.destroy')->can('hr.salary');
    Route::get('{id}/insurance/edit', [$controller, 'insuranceEdit'])->name('insurance.edit')->can('hr.salary');
    Route::put('{id}/insurance', [$controller, 'insuranceUpdate'])->name('insurance.update')->can('hr.salary');
};

Route::prefix('account/hr/staff')->name('account.payroll.')->middleware(['auth', 'user.active:' . Account::class])
    ->group(fn () => $salaryRoutes(SalaryAccountModuleController::class));

Route::prefix('employee/hr/staff')->name('employee.payroll.')->middleware(['auth', 'user.active:' . Employee::class])
    ->group(fn () => $salaryRoutes(SalaryEmployeeModuleController::class));

/**
 *  Payroll setup (the account only, the sidebar's HR section): HR › Allowance Types, HR › Payroll Settings.
 */
Route::prefix('account/hr')->name('account.hr.')->middleware(['auth', 'user.active:' . Account::class])->group(function () {
    Route::prefix('allowance-types')->name('allowance-types.')->group(function () {
        Route::get('/', [PayrollSettingAccountModuleController::class, 'allowanceTypes'])->name('index');
        Route::get('data', [PayrollSettingAccountModuleController::class, 'typesData'])->name('data');
        Route::get('create', [PayrollSettingAccountModuleController::class, 'typeCreate'])->name('create');
        Route::post('/', [PayrollSettingAccountModuleController::class, 'typeStore'])->name('store');
        Route::get('{id}/edit', [PayrollSettingAccountModuleController::class, 'typeEdit'])->name('edit');
        Route::put('{id}', [PayrollSettingAccountModuleController::class, 'typeUpdate'])->name('update');
        Route::patch('{id}/active', [PayrollSettingAccountModuleController::class, 'typeActive'])->name('active');
        Route::delete('{id}', [PayrollSettingAccountModuleController::class, 'typeDestroy'])->name('destroy');
    });
    Route::get('payroll-settings', [PayrollSettingAccountModuleController::class, 'settingsEdit'])->name('payroll-settings.edit');
    Route::put('payroll-settings', [PayrollSettingAccountModuleController::class, 'settingsUpdate'])->name('payroll-settings.update');
});
