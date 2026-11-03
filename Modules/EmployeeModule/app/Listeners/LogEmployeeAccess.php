<?php

namespace Modules\EmployeeModule\app\Listeners;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Modules\EmployeeModule\app\Services\AccessLogService;

/**
 * The employees' logins / failed logins / logouts → access_logs (docs/roles-plan.md), from Laravel's auth events (UserModule's
 * login controller is unchanged). The owner and admins: nothing.
 * - Login: refused by loginError() (left, account not active) = a failed login; else "login" + the session marker.
 * - Logout: only a session that logged in (a refused login's logout or the idle timeout's own logout is not a second line).
 */
class LogEmployeeAccess
{
    // the session marker of a logged in employee (read by the logout and the idle timeout)
    const SESSION_KEY = 'employee_session';

    // unix time of his last request (EmployeeIdleTimeout)
    const LAST_SEEN = 'employee_last_seen';

    public function __construct(private AccessLogService $logService)
    {
    }

    public function handleLogin(Login $event): void
    {
        if ($event->guard !== 'web' || !$this->logService->isEmployee($event->user)) {
            return;
        }

        if ($event->user->userable->loginError()) {
            $this->logService->failedLogin($event->user->email);
            return;
        }

        $this->logService->record('login', $event->user);
        session()->put(self::SESSION_KEY, true);
        session()->put(self::LAST_SEEN, time());
    }

    public function handleFailed(Failed $event): void
    {
        if ($event->guard === 'web') {
            $this->logService->failedLogin($event->credentials['email'] ?? null);
        }
    }

    public function handleLogout(Logout $event): void
    {
        if ($event->guard !== 'web' || !session()->pull(self::SESSION_KEY)) {
            return;
        }
        $this->logService->record('logout', $event->user);
    }
}
