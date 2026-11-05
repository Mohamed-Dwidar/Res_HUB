<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR H5 (docs/hr-plan.md §3.6): a payroll's lines (one per person) and what each is made of. A draft's lines are rebuilt on every
 * Recalculate (the typed income_tax + notes kept); a locked payroll's lines never change. snapshot = the person's name, no., job
 * title, insurance no. at calculation time (payslips read it). New tables only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->index();
            $table->unsignedBigInteger('run_id');
            $table->unsignedBigInteger('employee_id')->index();
            $table->json('snapshot')->nullable();
            $table->decimal('days_in_service', 5, 2)->default(0);
            $table->unsignedTinyInteger('month_days')->default(30);
            $table->decimal('basic', 12, 2)->default(0);
            $table->decimal('allowances', 12, 2)->default(0);
            $table->decimal('gross', 12, 2)->default(0);
            $table->decimal('bonuses', 12, 2)->default(0);
            $table->decimal('deductions', 12, 2)->default(0);
            $table->decimal('unpaid_leave_days', 5, 1)->default(0);
            $table->decimal('unpaid_leave', 12, 2)->default(0);
            $table->decimal('advance_installment', 12, 2)->default(0);
            $table->decimal('insurance_employee', 12, 2)->default(0);
            $table->decimal('insurance_company', 12, 2)->default(0);
            $table->decimal('income_tax', 12, 2)->default(0);
            $table->decimal('net', 12, 2)->default(0);
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->json('warnings')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['run_id', 'employee_id']);
        });

        Schema::create('payroll_line_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('line_id')->index();
            $table->string('kind', 10); // earning | deduction | info
            $table->string('source', 20); // salary | allowance | adjustment | unpaid_leave | advance | insurance | tax
            $table->unsignedBigInteger('source_id')->default(0);
            $table->string('label');
            $table->decimal('quantity', 8, 2)->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_line_items');
        Schema::dropIfExists('payroll_lines');
    }
};
