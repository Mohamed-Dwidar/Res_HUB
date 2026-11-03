<?php

namespace Modules\EmployeeModule\app\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Modules\EmployeeModule\app\Listeners\LogEmployeeAccess;
use Modules\EmployeeModule\app\Models\Employee;
use Modules\EmployeeModule\app\Services\AccessLogService;
use Symfony\Component\HttpFoundation\Response;

/**
 * An employee is logged out after his role's idle minutes without a request (docs/roles-plan.md; Employee::idleMinutes(), null =
 * no limit, as today), logged as "timeout". Appended to the "web" group by EmployeeModuleServiceProvider; nothing for other users.
 * The answer: ajax / json = 401 {message} (+ the message flashed: custom.js reloads → the login page shows it), a page = the
 * login page with the message.
 * "Remember me": a session restored from the cookie starts a new idle period (the role setting is meant for shared computers;
 * R0 may drop the cookie for roles with a limit).
 */
class EmployeeIdleTimeout
{
    public function __construct(private AccessLogService $logService)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $employee = $user?->userable;
        $minutes = $employee instanceof Employee ? $employee->idleMinutes() : null;
        if (!$minutes) {
            return $next($request);
        }

        $session = $request->session();
        $lastSeen = (int) $session->get(LogEmployeeAccess::LAST_SEEN);
        if ($lastSeen && time() - $lastSeen > $minutes * 60) {
            $this->logService->record('timeout', $user);
            $session->forget(LogEmployeeAccess::SESSION_KEY);   // not a second "logout" line
            Auth::guard('web')->logout();
            $session->invalidate();
            $session->regenerateToken();

            $hours = intdiv($minutes, 60);
            $message = 'You were logged out after ' . ($hours === 1 ? '1 hour' : $hours . ' hours') . ' without activity. Please log in again.';
            if ($request->expectsJson()) {
                // custom.js reloads the page on a 401 → the login page shows the message
                $session->flash('errors', (new ViewErrorBag())->put('default', new MessageBag(['email' => $message])));
                return response()->json(['message' => $message], 401);
            }
            return redirect()->route('login')->withErrors(['email' => $message]);
        }

        $session->put(LogEmployeeAccess::LAST_SEEN, time());
        return $next($request);
    }
}
