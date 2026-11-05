<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR H2 (docs/hr-plan.md §3.3): the account's allowance types (transport, meals, phone …), HR › Allowance Types (owner).
 * is_insurable = counted in the insurable salary. New table only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('allowance_types', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->index();
            $table->string('name_en')->nullable();
            $table->string('name_ar')->nullable();
            $table->boolean('is_insurable')->default(0);
            $table->boolean('is_active')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('allowance_types');
    }
};
