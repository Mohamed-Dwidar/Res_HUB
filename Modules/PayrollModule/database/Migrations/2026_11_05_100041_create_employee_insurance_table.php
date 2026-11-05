<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR H2 (docs/hr-plan.md §3.3): social insurance registration, ONE row per person (made on the first save; no soft delete,
 * the history is in employee_events). New table only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_insurance', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->index();
            $table->unsignedBigInteger('employee_id')->unique();
            $table->boolean('is_insured')->default(0);
            $table->string('insurance_no', 50)->nullable();
            $table->date('start_date')->nullable();
            $table->decimal('insured_wage', 12, 2)->nullable();
            $table->date('end_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_insurance');
    }
};
