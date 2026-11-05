<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Table names like the rest (user 2026-11-05): emp_positions → employee_positions, emp_shifts → employee_shifts. Columns that
 * point to them keep their names (employees.position_id / shift_id). No foreign keys reference them; the old index names
 * (emp_*_index) stay as they are (names only). Down renames back. Safe twice (guards).
 */
return new class extends Migration
{
    const RENAMES = ['emp_positions' => 'employee_positions', 'emp_shifts' => 'employee_shifts'];

    public function up(): void
    {
        foreach (self::RENAMES as $from => $to) {
            if (Schema::hasTable($from) && !Schema::hasTable($to)) {
                Schema::rename($from, $to);
            }
        }
    }

    public function down(): void
    {
        foreach (self::RENAMES as $from => $to) {
            if (Schema::hasTable($to) && !Schema::hasTable($from)) {
                Schema::rename($to, $from);
            }
        }
    }
};
