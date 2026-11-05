<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR H5 (docs/hr-plan.md §3.6): the salary payments of a LOCKED payroll's lines (part payments allowed). Each = one company Cash Out
 * "Salaries" (expense_id, outside every shift, ExpenseService::createCompany()); undo = cancelled_* + its Cash Out voided.
 * New table only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->index();
            $table->unsignedBigInteger('run_id')->index();
            $table->unsignedBigInteger('line_id')->index();
            $table->unsignedBigInteger('employee_id');
            $table->decimal('amount', 12, 2);
            $table->unsignedBigInteger('payment_method_id');
            $table->date('paid_on');
            $table->unsignedBigInteger('expense_id')->default(0);
            $table->nullableMorphs('creatable');
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->default(0);
            $table->string('cancel_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_payments');
    }
};
