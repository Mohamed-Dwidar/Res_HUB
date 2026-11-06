<?php

use Illuminate\Support\Facades\Route;
use Modules\AccountModule\app\Models\Account;
use Modules\EmployeeModule\app\Models\Employee;
use Modules\ReportModule\app\Http\Controllers\Account\ReportAccountModuleController;
use Modules\ReportModule\app\Http\Controllers\Employee\ReportEmployeeModuleController;

/**
 *  Reports (docs/reports-plan.md): {area}/reports/{report}, names {area}.reports.{report}[.data | .excel | .member];
 *  one permission key per report (the owner passes all). A new report = its routes here + its sidebar items + its key.
 */
$reportRoutes = function ($controller) {
    Route::get('outstanding', [$controller, 'outstanding'])->name('outstanding')->can('reports.outstanding');
    Route::get('outstanding/data', [$controller, 'outstandingData'])->name('outstanding.data')->can('reports.outstanding');
    Route::get('outstanding/excel', [$controller, 'outstandingExcel'])->name('outstanding.excel')->can('reports.outstanding');
    Route::get('outstanding/member/{member}', [$controller, 'outstandingMember'])->name('outstanding.member')->whereNumber('member')->can('reports.outstanding');
};

Route::prefix('account/reports')->name('account.reports.')->middleware(['auth', 'user.active:' . Account::class])->group(function () use ($reportRoutes) {
    $reportRoutes(ReportAccountModuleController::class);
});

Route::prefix('employee/reports')->name('employee.reports.')->middleware(['auth', 'user.active:' . Employee::class])->group(function () use ($reportRoutes) {
    $reportRoutes(ReportEmployeeModuleController::class);
});
