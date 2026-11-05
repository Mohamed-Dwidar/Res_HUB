<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR H4 (docs/hr-plan.md §3.5): advances (سلف) and their moves. New tables only.
 *
 * employee_advances: given = an account-level company Cash Out (expense_id, category "Advances", ExpenseService::createCompany());
 * repaid by installment_amount per month from first_period (installments = how many). advance_no = MAX + 1 per account.
 * status open | settled (remaining 0) | cancelled (cancelled_* + its company Cash Out voided in the same step; refused once
 * anything was deducted / repaid).
 *
 * employee_advance_moves: deduct (written by payroll H5, payroll_line_id) | extra (a planned extra deduction in a chosen month,
 * read by H5) | repay (cash: payment_id = the Cash In "Advance repayment") | change (installment changed from period: amount =
 * the new installment). Remaining = amount − Σ deduct − Σ repay. Cancelling a planned extra / a repay = soft delete with reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_advances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->index();
            $table->unsignedBigInteger('employee_id')->index();
            $table->unsignedInteger('advance_no');
            $table->decimal('amount', 12, 2);
            $table->date('given_on');
            $table->unsignedSmallInteger('installments');
            $table->decimal('installment_amount', 12, 2);
            $table->date('first_period'); // the first day of the first deduction month
            $table->unsignedBigInteger('payment_method_id');
            $table->unsignedBigInteger('expense_id')->default(0);
            $table->string('reason')->nullable();
            $table->string('status', 10)->default('open'); // open | settled | cancelled
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->default(0);
            $table->string('cancel_reason')->nullable();
            $table->nullableMorphs('creatable');
            $table->timestamps();
            $table->unique(['account_id', 'advance_no']);
        });

        Schema::create('employee_advance_moves', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->index();
            $table->unsignedBigInteger('advance_id')->index();
            $table->string('type', 10); // deduct | extra | repay | change
            $table->decimal('amount', 12, 2);
            $table->date('period')->nullable();
            $table->unsignedBigInteger('payment_id')->default(0);
            $table->unsignedBigInteger('payroll_line_id')->default(0);
            $table->string('reason')->nullable();
            $table->nullableMorphs('creatable');
            $table->string('delete_reason')->nullable();
            $table->unsignedBigInteger('deleted_by')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_advance_moves');
        Schema::dropIfExists('employee_advances');
    }
};
