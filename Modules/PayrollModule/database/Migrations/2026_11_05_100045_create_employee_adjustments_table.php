<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR H4 (docs/hr-plan.md §3.5): a one-off bonus / deduction of a person for a month. amount OR days (days × the daily rate =
 * salary ÷ payroll_settings.month_days, turned into money by payroll H5). Nothing is paid / deducted until payroll: H5 reads the
 * month's rows with payroll_line_id 0 and stamps them when it locks. Cancel = soft delete with delete_reason + deleted_by
 * (refused once payroll_line_id is set). New table only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_adjustments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->index();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('adjustment_type_id')->index();
            $table->date('period'); // the first day of the month
            $table->decimal('amount', 12, 2)->nullable();
            $table->decimal('days', 5, 2)->nullable();
            $table->string('reason');
            $table->unsignedBigInteger('payroll_line_id')->default(0);
            $table->nullableMorphs('creatable');
            $table->string('delete_reason')->nullable();
            $table->unsignedBigInteger('deleted_by')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['employee_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_adjustments');
    }
};
