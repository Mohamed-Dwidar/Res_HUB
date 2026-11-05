<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR H5 (docs/hr-plan.md §3.6, §6): one payroll per account per month. status draft (Calculate / Recalculate; reads only) |
 * locked (frozen; bonuses / deductions stamped, advance deduct moves written). history = [{type: calculated | locked |
 * reopened, at, by, reason, totals}]. New table only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->date('period'); // the first day of the month
            $table->string('status', 10)->default('draft'); // draft | locked
            $table->json('totals')->nullable();
            $table->timestamp('calculated_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->unsignedBigInteger('locked_by')->default(0);
            $table->unsignedSmallInteger('reopen_count')->default(0);
            $table->json('history')->nullable();
            $table->text('notes')->nullable();
            $table->nullableMorphs('creatable');
            $table->timestamps();
            $table->unique(['account_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_runs');
    }
};
