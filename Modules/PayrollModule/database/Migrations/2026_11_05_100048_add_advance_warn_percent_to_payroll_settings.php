<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR H4 (docs/hr-plan.md §3.5): payroll_settings.advance_warn_percent — a yellow warning (never a block) when a person's advance
 * installments of one month go above this % of their salary (the Advances tab + the Give Advance popup). Default 50.
 * Column only (the settings rows get 50).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_settings', function (Blueprint $table) {
            $table->decimal('advance_warn_percent', 5, 2)->default(50)->after('month_days');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_settings', function (Blueprint $table) {
            $table->dropColumn('advance_warn_percent');
        });
    }
};
