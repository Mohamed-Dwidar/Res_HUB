<?php

namespace Modules\EmployeeModule\app\Services;

use Illuminate\Http\Request;
use Modules\EmployeeModule\app\Models\AccessLog;
use Modules\EmployeeModule\app\Models\Employee;
use Modules\UserModule\app\Models\User;

/**
 * The Access Log (docs/roles-plan.md): the employees' logins, failed logins, logouts, idle timeouts (R0), exports and sensitive
 * actions (R1+). Only employees' logins are written (the owner's are not); nothing is ever edited.
 */
class AccessLogService
{
    public function __construct(private Request $request)
    {
    }

    public function isEmployee(?User $user): bool
    {
        return $user?->userable instanceof Employee;
    }

    // an event of a logged in employee (another user type = nothing); $extra: page, format, filters, rows, subject (a model)
    public function record(string $event, ?User $user, array $extra = []): ?AccessLog
    {
        if (!$this->isEmployee($user)) {
            return null;
        }

        return $this->write($event, $user->userable->ownerAccountId(), $user->id, $user->email, $extra);
    }

    // a wrong password typed for an employee's email (other emails are not logged: the account would be unknown)
    public function failedLogin(?string $email): ?AccessLog
    {
        $user = $email ? User::where('email', $email)->first() : null;
        if (!$this->isEmployee($user)) {
            return null;
        }

        return $this->write('login_failed', $user->userable->ownerAccountId(), $user->id, $email, []);
    }

    // the owner's Access Log list (paged / ordered by DataTables)
    public function listQuery($accountId)
    {
        return AccessLog::with('user.userable')->where('account_id', $accountId);
    }

    // the newest lines of one login (the owner's employee view); $event: only that event
    public function latestOf($userId, int $limit = 10, ?string $event = null)
    {
        return AccessLog::where('user_id', $userId)->when($event, fn ($query) => $query->where('event', $event))
            ->orderByDesc('id')->limit($limit)->get();
    }

    // the Access Log "Employee" filter: users.id => name (left employees too: their lines stay)
    public function loginOptions($accountId): array
    {
        return Employee::with(['user' => fn ($query) => $query->select('id', 'userable_type', 'userable_id')])
            ->where('account_id', $accountId)->orderBy('name')->get()
            ->filter(fn ($employee) => $employee->user)
            ->mapWithKeys(fn ($employee) => [$employee->user->id => $employee->name])
            ->all();
    }

    private function write(string $event, $accountId, $userId, ?string $email, array $extra): AccessLog
    {
        $subject = $extra['subject'] ?? null;

        return AccessLog::create([
            'account_id' => $accountId,
            'user_id' => $userId,
            'email' => $email,
            'event' => $event,
            'page' => $extra['page'] ?? null,
            'format' => $extra['format'] ?? null,
            'filters' => $extra['filters'] ?? null,
            'rows' => $extra['rows'] ?? null,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'ip' => $this->request->ip(),
            'user_agent' => mb_substr((string) $this->request->userAgent(), 0, 255) ?: null,
        ]);
    }
}
