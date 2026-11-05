<?php

namespace Modules\HrModule\app\Services;

use Illuminate\Support\Facades\Gate;

/**
 * Staff list columns added by other modules (like StaffTabs), so HrModule never loads their code: PayrollModule adds Basic
 * (H2). A column = [title, can (ability), with (fn ($query) => eager loads), value (fn (Staff) => html), class (td class)].
 * Shown after Hire Date, in the order added; not orderable.
 */
class StaffColumns
{
    private static array $columns = [];

    public static function add(string $key, array $column): void
    {
        self::$columns[$key] = $column;
    }

    // the columns this user may see
    public static function available(): array
    {
        return array_filter(self::$columns, fn ($column) => Gate::allows($column['can']));
    }
}
