<?php

namespace Modules\PaymentModule\app\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Modules\EmployeeModule\app\Models\Employee;
use Modules\PaymentModule\app\Services\ShiftClosingService;
use Symfony\Component\HttpFoundation\Response;

/**
 * The shift reminders of a logged in EMPLOYEE (docs/payments-plan.md §2b), never a block (added to the "web" group by
 * PaymentModuleServiceProvider; it does nothing for other users):
 * - a full page: $shiftOpen (their open shift has payments → the logout popup, employee layout) and $shiftReminderUrl
 *   (the open shift is older than config paymentmodule.shift_reminder_hours, no "still working" in the last shift_snooze_hours
 *   → the employee layout opens the "close your shift" popup, custom.js)
 * The account's own shift has no reminders / logout popup (user, 2026-10-13).
 * - an ajax action (not GET): the X-Shift-Reminder header with the popup url (custom.js opens it after the action)
 * The shift pages / popups themselves are left out.
 */
class ShiftReminder
{
    const HEADER = 'X-Shift-Reminder';

    public function __construct(private ShiftClosingService $shiftService)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $employee = $user?->userable;
        $page = !$request->ajax() && $request->isMethod('GET');
        $action = $request->ajax() && !$request->isMethod('GET');
        // a role that doesn't collect money (no shift.has, docs/roles-plan.md): no shift, no reminders
        if (!$employee instanceof Employee || (!$page && !$action) || $request->routeIs('employee.shift.*', 'logout') || !$user->can('shift.has')) {
            return $next($request);
        }

        if ($page) {
            View::share('shiftOpen', $this->shiftService->openPayments($employee->account_id, $user->id)->exists());
            View::share('shiftReminderUrl', $this->due($employee, $user) ? route('employee.shift.reminder') : null);
            return $next($request);
        }

        // after the action (it may have added a payment)
        $response = $next($request);
        if ($response->isSuccessful() && $this->due($employee, $user)) {
            $response->headers->set(self::HEADER, route('employee.shift.reminder'));
        }

        return $response;
    }

    private function due(Employee $employee, $user): bool
    {
        return $employee->account && $this->shiftService->reminderDue($employee->account, $user);
    }
}
