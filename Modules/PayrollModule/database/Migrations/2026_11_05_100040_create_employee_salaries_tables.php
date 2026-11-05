<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR H2 (docs/hr-plan.md §3.3): salary history per person. A raise = a new row (effective_from), the old one kept.
 * employee_salary_items = its allowances (one row per allowance type; user 2026-11-05: a child table, not JSON).
 *
 * Backfill: every employee with an old employees.salary > 0 (not deleted, no salary row yet) gets a first row = that amount
 * as the basic, effective from the join date (else 2026-11-01), reason "Opening salary". employees.salary is NOT changed or
 * dropped here (its own migration later, after the user checks). Idempotent; down() drops the two new tables only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_salaries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->index();
            $table->unsignedBigInteger('employee_id');
            $table->date('effective_from');
            $table->decimal('basic', 12, 2)->default(0);
            $table->string('reason')->nullable();
            $table->nullableMorphs('creatable');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['employee_id', 'effective_from']);
        });

        Schema::create('employee_salary_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('salary_id')->index();
            $table->unsignedBigInteger('allowance_type_id')->index();
            $table->decimal('amount', 12, 2)->default(0);
            $table->timestamps();
        });

        $now = now();
        $rows = DB::table('employees')->whereNull('deleted_at')->where('salary', '>', 0)
            ->whereNotIn('id', DB::table('employee_salaries')->select('employee_id'))
            ->get(['id', 'account_id', 'salary', 'join_date']);
        foreach ($rows as $row) {
            DB::table('employee_salaries')->insert([
                'account_id' => $row->account_id,
                'employee_id' => $row->id,
                'effective_from' => $row->join_date ?: '2026-11-01',
                'basic' => $row->salary,
                'reason' => 'Opening salary (the old Salary field)',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_salary_items');
        Schema::dropIfExists('employee_salaries');
    }
};
