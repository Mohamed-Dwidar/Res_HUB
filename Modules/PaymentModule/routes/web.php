<?php

use Illuminate\Support\Facades\Route;
use Modules\AccountModule\app\Models\Account;
use Modules\EmployeeModule\app\Models\Employee;
use Modules\PaymentModule\app\Http\Controllers\Account\DailySummaryAccountModuleController;
use Modules\PaymentModule\app\Http\Controllers\Account\ExpenseAccountModuleController;
use Modules\PaymentModule\app\Http\Controllers\Account\ExpenseCategoryAccountModuleController;
use Modules\PaymentModule\app\Http\Controllers\Account\ExtraAccountModuleController;
use Modules\PaymentModule\app\Http\Controllers\Account\IncomeCategoryAccountModuleController;
use Modules\PaymentModule\app\Http\Controllers\Account\MyShiftAccountModuleController;
use Modules\PaymentModule\app\Http\Controllers\Account\PaymentAccountModuleController;
use Modules\PaymentModule\app\Http\Controllers\Account\PaymentMethodAccountModuleController;
use Modules\PaymentModule\app\Http\Controllers\Account\ShiftClosingAccountModuleController;
use Modules\PaymentModule\app\Http\Controllers\Account\WithholdingCertificateAccountModuleController;
use Modules\PaymentModule\app\Http\Controllers\Employee\ExpenseEmployeeModuleController;
use Modules\PaymentModule\app\Http\Controllers\Employee\ExtraEmployeeModuleController;
use Modules\PaymentModule\app\Http\Controllers\Employee\PaymentEmployeeModuleController;
use Modules\PaymentModule\app\Http\Controllers\Employee\ShiftClosingEmployeeModuleController;
use Modules\PaymentModule\app\Http\Middleware\WithholdingEnabled;

/**
 *  Payments: added and viewed by the account and by its employees (same actions, see Concerns\PaymentActions);
 *  refund = both (into the refunder's shift); edit / delete = the account only
 */
$paymentRoutes = function ($controller) {
    Route::get('/', [$controller, 'index'])->name('index');
    Route::get('data', [$controller, 'data'])->name('data');
    // the All / Cash Out tabs: payments + expenses together (2026-10-14)
    Route::get('cash-data', [$controller, 'cashData'])->name('cash-data');
    // the member's reservations / packages drop menu of the form
    Route::get('options/payables', [$controller, 'payables'])->name('options.payables');
    Route::get('create', [$controller, 'create'])->name('create');
    Route::post('/', [$controller, 'store'])->name('store');
    Route::get('{id}', [$controller, 'show'])->name('show');
    Route::get('{id}/receipt', [$controller, 'receipt'])->name('receipt');
    // a refund (reason required) goes into the refunder's own open shift
    Route::get('{id}/refund', [$controller, 'refundForm'])->name('refund');
    Route::post('{id}/refund', [$controller, 'refund'])->name('refund.store');
};

Route::prefix('account/payments')->name('account.payments.')->middleware(['auth', 'user.active:' . Account::class])
    ->group(fn () => $paymentRoutes(PaymentAccountModuleController::class));

// the owner only: edit / delete (a payment of a closed shift: reason required, logged on the shift)
Route::prefix('account/payments')->name('account.payments.')->middleware(['auth', 'user.active:' . Account::class])->group(function () {
    Route::get('{id}/edit', [PaymentAccountModuleController::class, 'edit'])->name('edit');
    Route::put('{id}', [PaymentAccountModuleController::class, 'update'])->name('update');
    Route::get('{id}/delete', [PaymentAccountModuleController::class, 'deleteForm'])->name('delete');
    Route::delete('{id}', [PaymentAccountModuleController::class, 'destroy'])->name('destroy');
});

Route::prefix('employee/payments')->name('employee.payments.')->middleware(['auth', 'user.active:' . Employee::class])
    ->group(fn () => $paymentRoutes(PaymentEmployeeModuleController::class));

/**
 *  Payment settings (payment methods, income categories): only the account manages them
 */
$settingRoutes = function ($controller) {
    Route::get('/', [$controller, 'index'])->name('index');
    Route::get('data', [$controller, 'data'])->name('data');
    Route::get('create', [$controller, 'create'])->name('create');
    Route::post('/', [$controller, 'store'])->name('store');
    Route::get('{id}/edit', [$controller, 'edit'])->name('edit');
    Route::put('{id}', [$controller, 'update'])->name('update');
    Route::patch('{id}/active', [$controller, 'toggleActive'])->name('active');
    Route::delete('{id}', [$controller, 'destroy'])->name('destroy');
};

Route::prefix('account/payment-methods')->name('account.payment-methods.')->middleware(['auth', 'user.active:' . Account::class])
    ->group(fn () => $settingRoutes(PaymentMethodAccountModuleController::class));

Route::prefix('account/income-categories')->name('account.income-categories.')->middleware(['auth', 'user.active:' . Account::class])
    ->group(fn () => $settingRoutes(IncomeCategoryAccountModuleController::class));

Route::prefix('account/expense-categories')->name('account.expense-categories.')->middleware(['auth', 'user.active:' . Account::class])
    ->group(fn () => $settingRoutes(ExpenseCategoryAccountModuleController::class));

/**
 *  Expenses (docs/payments-plan.md "Expenses"): recorded by the account and its employees into their own open shift
 *  (Concerns\ExpenseActions); edit / delete = the account only
 */
$expenseRoutes = function ($controller) {
    Route::get('/', [$controller, 'index'])->name('index');
    Route::get('data', [$controller, 'data'])->name('data');
    Route::get('create', [$controller, 'create'])->name('create');
    Route::post('/', [$controller, 'store'])->name('store');
    Route::get('{id}', [$controller, 'show'])->name('show');
    Route::get('{id}/attachment', [$controller, 'attachment'])->name('attachment');
};

Route::prefix('account/expenses')->name('account.expenses.')->middleware(['auth', 'user.active:' . Account::class])->group(function () use ($expenseRoutes) {
    $expenseRoutes(ExpenseAccountModuleController::class);
    Route::get('{id}/edit', [ExpenseAccountModuleController::class, 'edit'])->name('edit');
    Route::put('{id}', [ExpenseAccountModuleController::class, 'update'])->name('update');
    Route::get('{id}/delete', [ExpenseAccountModuleController::class, 'deleteForm'])->name('delete');
    Route::delete('{id}', [ExpenseAccountModuleController::class, 'destroy'])->name('destroy');
});

Route::prefix('employee/expenses')->name('employee.expenses.')->middleware(['auth', 'user.active:' . Employee::class])
    ->group(fn () => $expenseRoutes(ExpenseEmployeeModuleController::class));

/**
 *  Withholding tax certificates: only the account manages them
 */
// hidden (404) while Settings › General "Withholding tax" is off (WithholdingEnabled)
Route::prefix('account/withholding-certificates')->name('account.withholding-certificates.')->middleware(['auth', 'user.active:' . Account::class, WithholdingEnabled::class])->group(function () {
    Route::get('/', [WithholdingCertificateAccountModuleController::class, 'index'])->name('index');
    Route::get('data', [WithholdingCertificateAccountModuleController::class, 'data'])->name('data');
    Route::get('create', [WithholdingCertificateAccountModuleController::class, 'create'])->name('create');
    Route::post('/', [WithholdingCertificateAccountModuleController::class, 'store'])->name('store');
    Route::get('{id}', [WithholdingCertificateAccountModuleController::class, 'show'])->name('show');
    Route::get('{id}/edit', [WithholdingCertificateAccountModuleController::class, 'edit'])->name('edit');
    Route::put('{id}', [WithholdingCertificateAccountModuleController::class, 'update'])->name('update');
    Route::delete('{id}', [WithholdingCertificateAccountModuleController::class, 'destroy'])->name('destroy');
    Route::get('{id}/attachment', [WithholdingCertificateAccountModuleController::class, 'attachment'])->name('attachment');
});

/**
 *  ONE closing level, the shift (docs/payments-plan.md §2b, 2026-10-13): every login closes its own shift (My Shift);
 *  the account also sees the Daily Summary (read only) and views / closes its employees' shifts (on their behalf)
 */
Route::prefix('account/daily-summary')->name('account.daily-summary.')->middleware(['auth', 'user.active:' . Account::class])->group(function () {
    // ?date= (default today)
    Route::get('/', [DailySummaryAccountModuleController::class, 'index'])->name('index');
});

// the account's own shift (MyShiftActions, the same page as the employees')
Route::prefix('account/shift')->name('account.shift.')->middleware(['auth', 'user.active:' . Account::class])->group(function () {
    Route::get('/', [MyShiftAccountModuleController::class, 'index'])->name('index');
    Route::get('data', [MyShiftAccountModuleController::class, 'data'])->name('data');
    Route::get('confirm', [MyShiftAccountModuleController::class, 'confirm'])->name('confirm');
    Route::post('close', [MyShiftAccountModuleController::class, 'close'])->name('close');
});

// any shift of the account (view, history, reopen), an employee's open shift closed on their behalf (?user_id=)
Route::prefix('account/shifts')->name('account.shifts.')->middleware(['auth', 'user.active:' . Account::class])->group(function () {
    Route::get('confirm', [ShiftClosingAccountModuleController::class, 'confirm'])->name('confirm');
    Route::post('close', [ShiftClosingAccountModuleController::class, 'close'])->name('close');
    Route::get('{id}', [ShiftClosingAccountModuleController::class, 'show'])->name('show');
    // reopen = the closing is removed, its rows go back to the open shift (DELETE: the popup does not go back to it)
    Route::get('{id}/reopen', [ShiftClosingAccountModuleController::class, 'reopenForm'])->name('reopen');
    Route::delete('{id}/reopen', [ShiftClosingAccountModuleController::class, 'reopen'])->name('reopen.store');
});

/**
 *  The employee's shift: their open shift (own payments only), close it (confirmation popup), their past closings, the reminders
 */
Route::prefix('employee/shift')->name('employee.shift.')->middleware(['auth', 'user.active:' . Employee::class])->group(function () {
    Route::get('/', [ShiftClosingEmployeeModuleController::class, 'index'])->name('index');
    Route::get('data', [ShiftClosingEmployeeModuleController::class, 'data'])->name('data');
    Route::get('confirm', [ShiftClosingEmployeeModuleController::class, 'confirm'])->name('confirm');
    Route::post('close', [ShiftClosingEmployeeModuleController::class, 'close'])->name('close');
    // the reminders (popups) + the "still working" answer
    Route::get('reminder', [ShiftClosingEmployeeModuleController::class, 'reminder'])->name('reminder');
    Route::get('logout', [ShiftClosingEmployeeModuleController::class, 'logoutPrompt'])->name('logout-prompt');
    Route::post('still-working', [ShiftClosingEmployeeModuleController::class, 'stillWorking'])->name('still-working');
    Route::get('{id}', [ShiftClosingEmployeeModuleController::class, 'show'])->name('show');
});

/**
 *  Package extras / adjustments: the account manages them (from the package view); employees add extras (sales) only
 */
Route::prefix('account/extras')->name('account.extras.')->middleware(['auth', 'user.active:' . Account::class])->group(function () {
    Route::get('create', [ExtraAccountModuleController::class, 'create'])->name('create');
    Route::post('/', [ExtraAccountModuleController::class, 'store'])->name('store');
    Route::get('{id}/edit', [ExtraAccountModuleController::class, 'edit'])->name('edit');
    Route::put('{id}', [ExtraAccountModuleController::class, 'update'])->name('update');
    Route::delete('{id}', [ExtraAccountModuleController::class, 'destroy'])->name('destroy');
});
Route::prefix('employee/extras')->name('employee.extras.')->middleware(['auth', 'user.active:' . Employee::class])->group(function () {
    Route::get('create', [ExtraEmployeeModuleController::class, 'create'])->name('create');
    Route::post('/', [ExtraEmployeeModuleController::class, 'store'])->name('store');
});
