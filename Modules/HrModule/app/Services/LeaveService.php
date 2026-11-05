<?php

namespace Modules\HrModule\app\Services;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\HrModule\app\Models\EmployeeEvent;
use Modules\HrModule\app\Models\EmployeeLeave;
use Modules\HrModule\app\Models\EmployeeLeaveBalance;
use Modules\HrModule\app\Models\LeaveType;
use Modules\HrModule\app\Models\Staff;

/**
 * Leaves of a staff file (docs/hr-plan.md §3.4, H3; the Leaves tab, hr.manage). HR records them — no request / approval.
 * Days = calendar days from – to by default (editable, half days, never above the calendar days). A leave counts in the year of
 * its from_date. Balance of a year per type = the person's days (EmployeeLeaveBalance) or the type's yearly_days (NULL = no
 * balance) − Σ days taken. Warnings (over the balance, a missing file the type asks for, overlapping dates) never block: they
 * come back with the save. "On leave" = computed (currentLeave(): today inside a leave; no status / login change, user 2026-11-06).
 * Cancel = soft delete with the reason (+ the file kept). Every change → employee_events. External staff: 404 (not on payroll).
 */
class LeaveService
{
    public function __construct(private StaffService $staffService)
    {
    }

    // the account's staff file, not External
    public function staff($accountId, $id): Staff
    {
        $staff = $this->staffService->findOne($accountId, $id);
        abort_if($staff->is_external, 404);
        return $staff;
    }

    // the leaves of a year (from_date in it), the cancelled ones too, newest first
    public function list(Staff $staff, int $year): Collection
    {
        return EmployeeLeave::withTrashed()->with('type', 'creatable.userable')->where('account_id', $staff->account_id)
            ->where('employee_id', $staff->id)->whereYear('from_date', $year)->orderByDesc('from_date')->orderByDesc('id')->get();
    }

    public function find(Staff $staff, $id): EmployeeLeave
    {
        return EmployeeLeave::with('type')->where('account_id', $staff->account_id)->where('employee_id', $staff->id)->findOrFail($id);
    }

    // the active types (+ $currentId) [id => name]
    public function typeOptions($accountId): array
    {
        return LeaveType::where('account_id', $accountId)->where('is_active', true)->ordered()->get()->mapWithKeys(fn ($type) => [$type->id => $type->name])->all();
    }

    public function types($accountId): Collection
    {
        return LeaveType::where('account_id', $accountId)->where('is_active', true)->ordered()->get();
    }

    /**
     * The balance cards of a year: [type id => ['type', 'entitled' (null = no balance), 'own' (bool: the person's own days),
     * 'taken', 'left' (null when no balance)]] — the active types + any type with leaves that year.
     */
    public function balances(Staff $staff, int $year, ?Collection $leaves = null): array
    {
        $leaves ??= $this->list($staff, $year);
        $taken = $leaves->whereNull('deleted_at')->groupBy('leave_type_id')->map(fn ($rows) => round((float) $rows->sum('days'), 1));
        $own = EmployeeLeaveBalance::where('employee_id', $staff->id)->where('year', $year)->pluck('days', 'leave_type_id');
        $types = LeaveType::withTrashed()->where('account_id', $staff->account_id)
            ->where(fn ($query) => $query->where(fn ($query) => $query->where('is_active', true)->whereNull('deleted_at'))->orWhereIn('id', $taken->keys()->all() ?: [0]))
            ->ordered()->get();
        $cards = [];
        foreach ($types as $type) {
            $entitled = $own->has($type->id) ? (float) $own[$type->id] : $type->yearly_days;
            $used = (float) ($taken[$type->id] ?? 0);
            $cards[$type->id] = [
                'type' => $type,
                'entitled' => $entitled,
                'own' => $own->has($type->id),
                'taken' => $used,
                'left' => $entitled === null ? null : round($entitled - $used, 1),
            ];
        }
        return $cards;
    }

    /**
     * Record a leave. $data: leave_type_id, from_date, to_date, days (optional), notes, attachment (a file).
     * Returns [leave, warnings].
     */
    public function create(Staff $staff, array $data, Model $user): array
    {
        $type = LeaveType::where('account_id', $staff->account_id)->where('is_active', true)->find((int) ($data['leave_type_id'] ?? 0));
        if (!$type) {
            throw ValidationException::withMessages(['leave_type_id' => 'Choose the leave type.']);
        }
        $from = Carbon::parse($data['from_date'])->startOfDay();
        $to = Carbon::parse($data['to_date'] ?? $data['from_date'])->startOfDay();
        if ($to->lt($from)) {
            throw ValidationException::withMessages(['to_date' => 'The end can\'t be before the start.']);
        }
        $calendar = $from->diffInDays($to) + 1;
        $days = isset($data['days']) && $data['days'] !== '' ? round((float) $data['days'], 1) : (float) $calendar;
        if ($days <= 0 || fmod($days * 2, 1) != 0) {
            throw ValidationException::withMessages(['days' => 'Days in half-day steps (0.5, 1, 1.5 …).']);
        }
        if ($days > $calendar) {
            throw ValidationException::withMessages(['days' => 'Not more than the ' . $calendar . ' calendar day(s) of these dates.']);
        }
        $file = $data['attachment'] ?? null;

        return DB::transaction(function () use ($staff, $type, $from, $to, $days, $data, $file, $user) {
            $leave = new EmployeeLeave([
                'account_id' => $staff->account_id, 'employee_id' => $staff->id, 'leave_type_id' => $type->id,
                'from_date' => $from->toDateString(), 'to_date' => $to->toDateString(), 'days' => $days,
                'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
            ]);
            $leave->creatable()->associate($user);
            if ($file instanceof UploadedFile) {
                $leave->attachment = $this->storeFile($staff, $file);
                $leave->attachment_name = mb_substr($file->getClientOriginalName(), 0, 255);
            }
            $leave->save();
            $this->event($staff, 'leave_added', [
                'leave' => [null, $type->name . ' · ' . $leave->datesText() . ' · ' . $leave->daysText()],
                'file' => $leave->attachment_name ? [null, $leave->attachment_name] : null,
            ], $leave->notes, $user);

            return [$leave, $this->warnings($staff, $leave->load('type'))];
        });
    }

    /**
     * The leave a person is on today (not cancelled, from ≤ today ≤ to), null when none — the automatic "On leave" badge of the
     * Staff list and the staff page header. ONE query per account per request (all the account's leaves covering today).
     */
    public static function currentLeave(Staff $staff): ?EmployeeLeave
    {
        // memo of THIS request (keyed by the request object: never stale across requests in one process)
        static $today = [];
        $key = spl_object_id(request()) . '-' . $staff->account_id;
        $today[$key] ??= EmployeeLeave::with('type')->where('account_id', $staff->account_id)
            ->where('from_date', '<=', today()->toDateString())->where('to_date', '>=', today()->toDateString())
            ->orderBy('to_date')->get()->keyBy('employee_id');
        return $today[$key][$staff->id] ?? null;
    }

    // why it may be wrong (never a block): over the balance, no file the type asks for, dates overlapping another leave
    public function warnings(Staff $staff, EmployeeLeave $leave): array
    {
        $warnings = [];
        $card = $this->balances($staff, $leave->from_date->year)[$leave->leave_type_id] ?? null;
        if ($card && $card['left'] !== null && $card['left'] < 0) {
            $warnings[] = $leave->type->name . ' ' . $leave->from_date->year . ': ' . abs($card['left']) . ' day(s) over the balance.';
        }
        if ($leave->type?->needs_attachment && !$leave->attachment) {
            $warnings[] = $leave->type->name . ' usually needs a file (e.g. a sick note).';
        }
        $overlap = EmployeeLeave::where('employee_id', $staff->id)->whereKeyNot($leave->id)
            ->where('from_date', '<=', $leave->to_date)->where('to_date', '>=', $leave->from_date)->exists();
        if ($overlap) {
            $warnings[] = 'These dates overlap another leave.';
        }
        return $warnings;
    }

    public function cancel(Staff $staff, $id, string $reason, Model $user): EmployeeLeave
    {
        $leave = $this->find($staff, $id);
        return DB::transaction(function () use ($staff, $leave, $reason, $user) {
            $leave->update(['delete_reason' => $reason, 'deleted_by' => $user->getKey()]);
            $leave->delete();
            $this->event($staff, 'leave_cancelled', ['leave' => [$leave->type?->name . ' · ' . $leave->datesText() . ' · ' . $leave->daysText(), null]], $reason, $user);
            return $leave;
        });
    }

    // the person's own yearly days of a type (empty = back to the type's default)
    public function setBalance(Staff $staff, $typeId, int $year, $days, Model $user): void
    {
        $type = LeaveType::withTrashed()->where('account_id', $staff->account_id)->findOrFail($typeId);
        $before = $this->balances($staff, $year)[$type->id]['entitled'] ?? $type->yearly_days;
        DB::transaction(function () use ($staff, $type, $year, $days, $before, $user) {
            if ($days === null || $days === '') {
                EmployeeLeaveBalance::where('employee_id', $staff->id)->where('leave_type_id', $type->id)->where('year', $year)->delete();
                $after = $type->yearly_days;
            } else {
                $after = round((float) $days, 1);
                EmployeeLeaveBalance::updateOrCreate(
                    ['employee_id' => $staff->id, 'leave_type_id' => $type->id, 'year' => $year],
                    ['account_id' => $staff->account_id, 'days' => $after]
                );
            }
            $show = fn ($value) => $value === null ? 'no balance' : rtrim(rtrim(number_format($value, 1), '0'), '.');
            if ($show($before) !== $show($after)) {
                $this->event($staff, 'leave_balance_changed', [$type->name . ' ' . $year => [$show($before), $show($after)]], null, $user);
            }
        });
    }

    // [storage path, name] of a leave's file (404 when none / missing)
    public function file(Staff $staff, EmployeeLeave $leave): array
    {
        $path = $leave->attachment ? 'hr/' . $staff->account_id . '/' . $staff->id . '/' . $leave->attachment : null;
        abort_unless($path && Storage::disk('local')->exists($path), 404);
        return [$path, $leave->attachment_name ?: 'leave-' . $leave->id . '.' . pathinfo($path, PATHINFO_EXTENSION)];
    }

    private function storeFile(Staff $staff, UploadedFile $upload): string
    {
        $name = Str::uuid() . '.' . strtolower($upload->extension() ?: $upload->getClientOriginalExtension());
        Storage::disk('local')->putFileAs('hr/' . $staff->account_id . '/' . $staff->id, $upload, $name);
        return $name;
    }

    private function event(Staff $staff, string $type, array $changes, ?string $reason, Model $user): void
    {
        $changes = array_filter($changes, fn ($change) => $change !== null);
        $event = new EmployeeEvent(['account_id' => $staff->account_id, 'employee_id' => $staff->id, 'type' => $type, 'changes' => $changes ?: null, 'reason' => $reason]);
        $event->creatable()->associate($user);
        $event->save();
    }
}
