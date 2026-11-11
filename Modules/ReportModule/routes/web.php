<?php

use Illuminate\Support\Facades\Route;
use Modules\AccountModule\app\Models\Account;
use Modules\EmployeeModule\app\Models\Employee;
use Modules\ReportModule\app\Http\Controllers\Account\ReportAccountModuleController;
use Modules\ReportModule\app\Http\Controllers\Employee\ReportEmployeeModuleController;

/**
 *  Reports (docs/reports-plan.md): {area}/reports/{report}, names {area}.reports.{report}[.data | .excel | .pdf | .member | .members];
 *  one permission key per report (the owner passes all). A new report = its routes here + its sidebar items + its key.
 */
$reportRoutes = function ($controller) {
    Route::get('outstanding', [$controller, 'outstanding'])->name('outstanding')->can('reports.outstanding');
    Route::get('outstanding/data', [$controller, 'outstandingData'])->name('outstanding.data')->can('reports.outstanding');
    Route::get('outstanding/excel', [$controller, 'outstandingExcel'])->name('outstanding.excel')->can('reports.outstanding');
    Route::get('outstanding/member/{member}', [$controller, 'outstandingMember'])->name('outstanding.member')->whereNumber('member')->can('reports.outstanding');
    // 2. Member statement (the member search is the report's own: a reports role may not have members.pick)
    Route::get('member-statement', [$controller, 'memberStatement'])->name('member-statement')->can('reports.member_statement');
    Route::get('member-statement/data', [$controller, 'memberStatementData'])->name('member-statement.data')->can('reports.member_statement');
    Route::get('member-statement/members', [$controller, 'memberStatementMembers'])->name('member-statement.members')->can('reports.member_statement');
    Route::get('member-statement/pdf', [$controller, 'memberStatementPdf'])->name('member-statement.pdf')->can('reports.member_statement');
    Route::get('member-statement/excel', [$controller, 'memberStatementExcel'])->name('member-statement.excel')->can('reports.member_statement');
    // 3. Income
    Route::get('income', [$controller, 'income'])->name('income')->can('reports.income');
    Route::get('income/data', [$controller, 'incomeData'])->name('income.data')->can('reports.income');
    Route::get('income/group', [$controller, 'incomeGroup'])->name('income.group')->can('reports.income');
    Route::get('income/excel', [$controller, 'incomeExcel'])->name('income.excel')->can('reports.income');
    // 4. Expenses (two views: by category | details)
    Route::get('expenses', [$controller, 'expenses'])->name('expenses')->can('reports.expenses');
    Route::get('expenses/categories', [$controller, 'expensesCategories'])->name('expenses.categories')->can('reports.expenses');
    Route::get('expenses/details', [$controller, 'expensesDetails'])->name('expenses.details')->can('reports.expenses');
    // 5. Subscriptions
    Route::get('subscriptions', [$controller, 'subscriptions'])->name('subscriptions')->can('reports.subscriptions');
    Route::get('subscriptions/data', [$controller, 'subscriptionsData'])->name('subscriptions.data')->can('reports.subscriptions');
    Route::get('subscriptions/excel', [$controller, 'subscriptionsExcel'])->name('subscriptions.excel')->can('reports.subscriptions');
    // 6. Occupancy
    Route::get('occupancy', [$controller, 'occupancy'])->name('occupancy')->can('reports.occupancy');
    Route::get('occupancy/data', [$controller, 'occupancyData'])->name('occupancy.data')->can('reports.occupancy');
    Route::get('occupancy/excel', [$controller, 'occupancyExcel'])->name('occupancy.excel')->can('reports.occupancy');
    // 7. Profit & Loss
    Route::get('pnl', [$controller, 'pnl'])->name('pnl')->can('reports.pnl');
    Route::get('pnl/data', [$controller, 'pnlData'])->name('pnl.data')->can('reports.pnl');
    Route::get('pnl/excel', [$controller, 'pnlExcel'])->name('pnl.excel')->can('reports.pnl');
    // 8. Members
    Route::get('members', [$controller, 'members'])->name('members')->can('reports.members');
    Route::get('members/data', [$controller, 'membersData'])->name('members.data')->can('reports.members');
    Route::get('members/occupations', [$controller, 'membersOccupations'])->name('members.occupations')->can('reports.members');
    Route::get('members/excel', [$controller, 'membersExcel'])->name('members.excel')->can('reports.members');
    Route::get('expenses/excel', [$controller, 'expensesExcel'])->name('expenses.excel')->can('reports.expenses');
};

Route::prefix('account/reports')->name('account.reports.')->middleware(['auth', 'user.active:' . Account::class])->group(function () use ($reportRoutes) {
    $reportRoutes(ReportAccountModuleController::class);
});

Route::prefix('employee/reports')->name('employee.reports.')->middleware(['auth', 'user.active:' . Employee::class])->group(function () use ($reportRoutes) {
    $reportRoutes(ReportEmployeeModuleController::class);
});
