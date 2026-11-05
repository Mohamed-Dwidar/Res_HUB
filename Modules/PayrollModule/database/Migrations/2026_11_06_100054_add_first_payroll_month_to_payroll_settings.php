<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR H5 (docs/hr-plan.md §6): payroll_settings.first_payroll_month — the first month paid through Payroll (the first day of it).
 * Earlier months were paid by hand: Calculate / Lock / Pay are refused for them (no month can be paid twice). NULL = not set
 * (a warning on the Payroll page). Column only; the go-live checklist sets it (2026-11-01 for account 1) from HR › Payroll Settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_settings', function (Blueprint $table) {
            $table->date('first_payroll_month')->nullable()->after('advance_warn_percent');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_settings', function (Blueprint $table) {
            $table->dropColumn('first_payroll_month');
        });
    }
};
