<?php

namespace Modules\HrModule\app\Services;

use Illuminate\Support\Facades\Gate;
use Modules\HrModule\app\Models\Staff;

/**
 * Staff page tabs added by other modules (docs/hr-plan.md §B: ONE staff page, a phase adds its tab) so HrModule never loads
 * their code: PayrollModule adds Salary (H2), later Advances & Adjustments. A tab = [label, can (ability), payroll (true = not
 * for External staff), view, data (fn (Staff) => array of view data)]. Shown in the order added, after Profile / Documents.
 */
class StaffTabs
{
    private static array $tabs = [];

    public static function add(string $key, array $tab): void
    {
        self::$tabs[$key] = $tab;
    }

    // the tabs this user may open on this file
    public static function available(Staff $staff): array
    {
        return array_filter(self::$tabs, fn ($tab) => Gate::allows($tab['can']) && !(($tab['payroll'] ?? false) && $staff->is_external));
    }
}
