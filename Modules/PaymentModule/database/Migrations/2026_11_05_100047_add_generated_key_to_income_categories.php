<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR H4 (docs/hr-plan.md §3.5): income_categories.generated_key, like expense_categories (100043): 'advance_repayment' = the
 * system's other-income category of a cash advance repayment (not counted as income), made on first use, not offered in the
 * Add Cash In form. Column only, no data change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('income_categories', function (Blueprint $table) {
            $table->string('generated_key', 30)->nullable()->after('name_en');
            $table->unique(['account_id', 'generated_key']);
        });
    }

    public function down(): void
    {
        Schema::table('income_categories', function (Blueprint $table) {
            $table->dropUnique(['account_id', 'generated_key']);
            $table->dropColumn('generated_key');
        });
    }
};
