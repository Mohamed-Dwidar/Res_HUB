<?php

use Illuminate\Support\Facades\Route;
use Modules\AccountModule\app\Models\Account;
use Modules\EmployeeModule\app\Models\Employee;
use Modules\ReservationModule\app\Http\Controllers\Account\ReservationAccountModuleController;
use Modules\ReservationModule\app\Http\Controllers\Account\ReservationStatusAccountModuleController;
use Modules\ReservationModule\app\Http\Controllers\Account\ScheduleAccountModuleController;
use Modules\ReservationModule\app\Http\Controllers\Employee\ReservationEmployeeModuleController;
use Modules\ReservationModule\app\Http\Controllers\Employee\ScheduleEmployeeModuleController;

/**
 *  Reservations: managed by the account and by its employees (same actions, see Concerns\ReservationActions)
 */
$reservationRoutes = function ($controller) {
    Route::get('/', [$controller, 'index'])->name('index');
    Route::get('data', [$controller, 'data'])->name('data');
    // the form's live availability check (unit + dates + times; custom.js reservationAvailability())
    Route::get('availability', [$controller, 'availability'])->name('availability');
    // the double-tax guard: the member's VAT (manual) cash ins near the dates (custom.js reservationTax())
    Route::get('manual-vat', [$controller, 'manualVat'])->name('manual-vat');
    Route::get('create', [$controller, 'create'])->name('create');
    Route::post('/', [$controller, 'store'])->name('store');
    Route::get('{id}', [$controller, 'show'])->name('show');
    Route::get('{id}/edit', [$controller, 'edit'])->name('edit');
    Route::put('{id}', [$controller, 'update'])->name('update');
    Route::patch('{id}/status', [$controller, 'changeStatus'])->name('status');
    // a not counted reservation (cancelled ...) back to the default counted status, after the availability check
    Route::get('{id}/restore', [$controller, 'restoreForm'])->name('restore');
    Route::patch('{id}/restore', [$controller, 'restore'])->name('restore.store');
    // a not renewed subscription period: marked "left" (off the Schedule page's Not renewed list) / undone
    Route::patch('{id}/left', [$controller, 'markLeft'])->name('left');
    Route::patch('{id}/left/undo', [$controller, 'undoLeft'])->name('left.undo');
    Route::delete('{id}', [$controller, 'destroy'])->name('destroy');
};

Route::prefix('account/reservations')->name('account.reservations.')->middleware(['auth', 'user.active:' . Account::class])
    ->group(fn () => $reservationRoutes(ReservationAccountModuleController::class));

Route::prefix('employee/reservations')->name('employee.reservations.')->middleware(['auth', 'user.active:' . Employee::class])
    ->group(fn () => $reservationRoutes(ReservationEmployeeModuleController::class));

/**
 *  The Schedule page (ScheduleActions): the same for the account (under Dashboard) and its employees (their home page);
 *  renamed from Today 2026-10-16: the old /today URLs redirect (bookmarks)
 */
$scheduleRoutes = function ($controller) {
    Route::get('/', [$controller, 'index'])->name('index');
    Route::get('sections', [$controller, 'sections'])->name('sections');
    Route::get('cancelled', [$controller, 'cancelled'])->name('cancelled');
};

Route::prefix('account/schedule')->name('account.schedule.')->middleware(['auth', 'user.active:' . Account::class])
    ->group(fn () => $scheduleRoutes(ScheduleAccountModuleController::class));

Route::prefix('employee/schedule')->name('employee.schedule.')->middleware(['auth', 'user.active:' . Employee::class])
    ->group(fn () => $scheduleRoutes(ScheduleEmployeeModuleController::class));

Route::redirect('account/today', '/account/schedule');
Route::redirect('employee/today', '/employee/schedule');

/**
 *  Reservation statuses: only the account manages them
 */
Route::prefix('account/reservation-statuses')->name('account.reservation-statuses.')->middleware(['auth', 'user.active:' . Account::class])->group(function () {
    Route::get('/', [ReservationStatusAccountModuleController::class, 'index'])->name('index');
    Route::get('data', [ReservationStatusAccountModuleController::class, 'data'])->name('data');
    Route::get('create', [ReservationStatusAccountModuleController::class, 'create'])->name('create');
    Route::post('/', [ReservationStatusAccountModuleController::class, 'store'])->name('store');
    Route::get('{id}/edit', [ReservationStatusAccountModuleController::class, 'edit'])->name('edit');
    Route::put('{id}', [ReservationStatusAccountModuleController::class, 'update'])->name('update');
    Route::patch('{id}/active', [ReservationStatusAccountModuleController::class, 'toggleActive'])->name('active');
    Route::patch('{id}/default', [ReservationStatusAccountModuleController::class, 'toggleDefault'])->name('default');
    Route::delete('{id}', [ReservationStatusAccountModuleController::class, 'destroy'])->name('destroy');
});
