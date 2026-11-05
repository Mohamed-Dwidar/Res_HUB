<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Department → Job Title (user 2026-11-05): a job title may belong to a department (employee_positions.department_id, 0 = none);
 * an employee's department = his job title's department, so employees.department_id (added by 100034, checked empty: 0 rows
 * with a department, 0 departments) is dropped. Down: back as it was (the employees column empty, the titles' column gone).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('employee_positions', 'department_id')) {
            Schema::table('employee_positions', function (Blueprint $table) {
                $table->unsignedBigInteger('department_id')->default(0)->after('account_id')->index();
            });
        }
        if (Schema::hasColumn('employees', 'department_id')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->dropColumn('department_id');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('employees', 'department_id')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->unsignedBigInteger('department_id')->default(0)->after('position_id');
            });
        }
        if (Schema::hasColumn('employee_positions', 'department_id')) {
            Schema::table('employee_positions', function (Blueprint $table) {
                $table->dropIndex(['department_id']);
                $table->dropColumn('department_id');
            });
        }
    }
};
