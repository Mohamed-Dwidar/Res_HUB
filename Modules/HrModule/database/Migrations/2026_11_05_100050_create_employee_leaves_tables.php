<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR H3 (docs/hr-plan.md §3.4). New tables only.
 *
 * employee_leave_balances: a person's own yearly days of a type for one year (overrides leave_types.yearly_days); one row per
 * person + type + year, only when HR changes it.
 *
 * employee_leaves: a leave HR records (no request / approval steps): type, from_date – to_date, days (calendar days by default,
 * editable, half days), notes, ONE optional file on the private disk hr/{account}/{employee}/ (random name; served by the
 * controller, like the documents), creatable. Balance of a year = the person's days (or the type's) − Σ days of the year's
 * leaves (a leave is counted in the year of its from_date). Cancel = soft delete with delete_reason + deleted_by.
 * Payroll H5 reads the unpaid types' days inside its month (a leave crossing months split by its dates).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_leave_balances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->index();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('leave_type_id');
            $table->unsignedSmallInteger('year');
            $table->decimal('days', 5, 1);
            $table->timestamps();
            $table->unique(['employee_id', 'leave_type_id', 'year']);
        });

        Schema::create('employee_leaves', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->index();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('leave_type_id')->index();
            $table->date('from_date');
            $table->date('to_date');
            $table->decimal('days', 5, 1);
            $table->text('notes')->nullable();
            $table->string('attachment')->nullable();
            $table->string('attachment_name')->nullable();
            $table->nullableMorphs('creatable');
            $table->string('delete_reason')->nullable();
            $table->unsignedBigInteger('deleted_by')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['employee_id', 'from_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_leaves');
        Schema::dropIfExists('employee_leave_balances');
    }
};
