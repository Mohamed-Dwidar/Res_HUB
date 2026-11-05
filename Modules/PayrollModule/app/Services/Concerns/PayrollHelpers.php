<?php

namespace Modules\PayrollModule\app\Services\Concerns;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Modules\HrModule\app\Models\EmployeeEvent;
use Modules\HrModule\app\Models\Staff;

// shared by the H4 services: the staff history line + the month list of the forms
trait PayrollHelpers
{
    private function event(Staff $staff, string $type, array $changes, ?string $reason, Model $user): void
    {
        $changes = array_filter($changes, fn ($change) => $change !== null);
        $event = new EmployeeEvent(['account_id' => $staff->account_id, 'employee_id' => $staff->id, 'type' => $type, 'changes' => $changes ?: null, 'reason' => $reason]);
        $event->creatable()->associate($user);
        $event->save();
    }

    // [Y-m-01 => "November 2026"]: 3 months back … 12 ahead (+ $keep if outside)
    public function monthOptions(?string $keep = null): array
    {
        $months = [];
        $start = Carbon::today()->startOfMonth()->subMonths(3);
        for ($i = 0; $i <= 15; $i++) {
            $month = $start->copy()->addMonths($i);
            $months[$month->toDateString()] = $month->format('F Y');
        }
        if ($keep && !isset($months[$keep])) {
            $months = [$keep => Carbon::parse($keep)->format('F Y')] + $months;
        }
        return $months;
    }

    // a "Y-m-01" of the list (else null)
    private function month($value): ?Carbon
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-01$/', $value)) {
            return null;
        }
        return Carbon::parse($value)->startOfMonth();
    }

    private function money($value): string
    {
        return number_format((float) $value, 2);
    }
}
