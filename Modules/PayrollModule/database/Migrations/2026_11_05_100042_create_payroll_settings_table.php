<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR H2 (docs/hr-plan.md §3.3): the account's payroll settings (HR › Payroll Settings, owner), one row per account, made on the
 * first save. Insurance % = NULL until the owner sets them (a warning; no legal number in the code). month_days = the daily
 * rate's divisor (salary ÷ month_days: "days of salary" deductions, unpaid leave). New table only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->unique();
            $table->decimal('insurance_employee_percent', 5, 2)->nullable();
            $table->decimal('insurance_company_percent', 5, 2)->nullable();
            $table->unsignedTinyInteger('month_days')->default(30);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_settings');
    }
};
