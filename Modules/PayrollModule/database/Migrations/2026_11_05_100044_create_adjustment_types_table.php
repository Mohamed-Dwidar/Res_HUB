<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR H4 (docs/hr-plan.md §3.5): the account's bonus / deduction types (HR › Adjustment Types, owner): e.g. Incentive, Overtime,
 * Penalty, Absence, Cash shortage. kind = bonus (adds to the salary) | deduction (takes from it). New table only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('adjustment_types', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->index();
            $table->string('name_en')->nullable();
            $table->string('name_ar')->nullable();
            $table->string('kind', 10); // bonus | deduction
            $table->boolean('is_active')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adjustment_types');
    }
};
