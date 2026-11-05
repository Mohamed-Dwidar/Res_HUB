<?php

use Illuminate\Support\Facades\Route;
use Modules\AccountModule\app\Models\Account;
use Modules\EmployeeModule\app\Models\Employee;
use Modules\PayrollModule\app\Http\Controllers\Account\AdvanceAccountModuleController;
use Modules\PayrollModule\app\Http\Controllers\Account\PayrollAccountModuleController;
use Modules\PayrollModule\app\Http\Controllers\Account\PayrollSettingAccountModuleController;
use Modules\PayrollModule\app\Http\Controllers\Account\SalaryAccountModuleController;
use Modules\PayrollModule\app\Http\Controllers\Employee\AdvanceEmployeeModuleController;
use Modules\PayrollModule\app\Http\Controllers\Employee\PayrollEmployeeModuleController;
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

/**
 *  The Advances & Adjustments tab popups (H4, hr.salary; Concerns\AdvanceActions). {kind} = repay | change.
 */
$advanceRoutes = function ($controller) {
    Route::get('{id}/advances/create', [$controller, 'advanceCreate'])->name('advances.create')->can('hr.salary');
    Route::post('{id}/advances', [$controller, 'advanceStore'])->name('advances.store')->can('hr.salary');
    Route::get('{id}/advances/{advance}/cancel', [$controller, 'advanceCancelForm'])->name('advances.cancel.form')->can('hr.salary');
    Route::post('{id}/advances/{advance}/cancel', [$controller, 'advanceCancel'])->name('advances.cancel')->can('hr.salary');
    Route::get('{id}/advances/{advance}/{kind}', [$controller, 'moveForm'])->name('advances.moves.create')->whereIn('kind', ['repay', 'change'])->can('hr.salary');
    Route::post('{id}/advances/{advance}/{kind}', [$controller, 'moveStore'])->name('advances.moves.store')->whereIn('kind', ['repay', 'change'])->can('hr.salary');
    Route::get('{id}/advances/{advance}/moves/{move}/cancel', [$controller, 'moveCancelForm'])->name('advances.moves.cancel.form')->can('hr.salary');
    Route::post('{id}/advances/{advance}/moves/{move}/cancel', [$controller, 'moveCancel'])->name('advances.moves.cancel')->can('hr.salary');
    Route::get('{id}/adjustments/create', [$controller, 'adjustmentCreate'])->name('adjustments.create')->can('hr.salary');
    Route::post('{id}/adjustments', [$controller, 'adjustmentStore'])->name('adjustments.store')->can('hr.salary');
    Route::get('{id}/adjustments/{adjustment}/cancel', [$controller, 'adjustmentCancelForm'])->name('adjustments.cancel.form')->can('hr.salary');
    Route::post('{id}/adjustments/{adjustment}/cancel', [$controller, 'adjustmentCancel'])->name('adjustments.cancel')->can('hr.salary');
};

Route::prefix('account/hr/staff')->name('account.payroll.')->middleware(['auth', 'user.active:' . Account::class])->group(function () use ($salaryRoutes, $advanceRoutes) {
    $salaryRoutes(SalaryAccountModuleController::class);
    $advanceRoutes(AdvanceAccountModuleController::class);
});

Route::prefix('employee/hr/staff')->name('employee.payroll.')->middleware(['auth', 'user.active:' . Employee::class])->group(function () use ($salaryRoutes, $advanceRoutes) {
    $salaryRoutes(SalaryEmployeeModuleController::class);
    $advanceRoutes(AdvanceEmployeeModuleController::class);
});

/**
 *  HR › Payroll (H5; Concerns\PayrollActions): the month page + draft (hr.salary), lock / reopen (payroll.approve), pay (payroll.pay).
 */
$payrollRoutes = function ($controller) {
    Route::get('/', [$controller, 'index'])->name('index')->can('hr.salary');
    Route::post('calculate', [$controller, 'calculate'])->name('calculate')->can('hr.salary');
    Route::get('{run}/lines/{line}', [$controller, 'line'])->name('line')->can('hr.salary');
    Route::get('{run}/lines/{line}/edit', [$controller, 'lineEdit'])->name('line.edit')->can('hr.salary');
    Route::put('{run}/lines/{line}', [$controller, 'lineUpdate'])->name('line.update')->can('hr.salary');
    Route::get('{run}/lines/{line}/payslip', [$controller, 'payslip'])->name('payslip')->can('hr.salary');
    Route::get('{run}/lock', [$controller, 'lockForm'])->name('lock.form')->can('payroll.approve');
    Route::post('{run}/lock', [$controller, 'lock'])->name('lock')->can('payroll.approve');
    Route::get('{run}/reopen', [$controller, 'reopenForm'])->name('reopen.form')->can('payroll.approve');
    Route::post('{run}/reopen', [$controller, 'reopen'])->name('reopen')->can('payroll.approve');
    Route::get('{run}/pay', [$controller, 'payForm'])->name('pay-all.form')->can('payroll.pay');
    Route::post('{run}/pay', [$controller, 'payAll'])->name('pay-all')->can('payroll.pay');
    Route::get('{run}/lines/{line}/pay', [$controller, 'payForm'])->name('pay.form')->can('payroll.pay');
    Route::post('{run}/lines/{line}/pay', [$controller, 'pay'])->name('pay')->can('payroll.pay');
    Route::get('{run}/payments/{payment}/undo', [$controller, 'undoForm'])->name('undo.form')->can('payroll.pay');
    Route::post('{run}/payments/{payment}/undo', [$controller, 'undo'])->name('undo')->can('payroll.pay');
};

Route::prefix('account/hr/payroll')->name('account.payroll.runs.')->middleware(['auth', 'user.active:' . Account::class])
    ->group(fn () => $payrollRoutes(PayrollAccountModuleController::class));

Route::prefix('employee/hr/payroll')->name('employee.payroll.runs.')->middleware(['auth', 'user.active:' . Employee::class])
    ->group(fn () => $payrollRoutes(PayrollEmployeeModuleController::class));

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
    Route::prefix('adjustment-types')->name('adjustment-types.')->group(function () {
        Route::get('/', [PayrollSettingAccountModuleController::class, 'adjustmentTypes'])->name('index');
        Route::get('data', [PayrollSettingAccountModuleController::class, 'adjustmentTypesData'])->name('data');
        Route::get('create', [PayrollSettingAccountModuleController::class, 'adjustmentTypeCreate'])->name('create');
        Route::post('/', [PayrollSettingAccountModuleController::class, 'adjustmentTypeStore'])->name('store');
        Route::get('{id}/edit', [PayrollSettingAccountModuleController::class, 'adjustmentTypeEdit'])->name('edit');
        Route::put('{id}', [PayrollSettingAccountModuleController::class, 'adjustmentTypeUpdate'])->name('update');
        Route::patch('{id}/active', [PayrollSettingAccountModuleController::class, 'adjustmentTypeActive'])->name('active');
        Route::delete('{id}', [PayrollSettingAccountModuleController::class, 'adjustmentTypeDestroy'])->name('destroy');
    });
    Route::get('payroll-settings', [PayrollSettingAccountModuleController::class, 'settingsEdit'])->name('payroll-settings.edit');
    Route::put('payroll-settings', [PayrollSettingAccountModuleController::class, 'settingsUpdate'])->name('payroll-settings.update');
});
