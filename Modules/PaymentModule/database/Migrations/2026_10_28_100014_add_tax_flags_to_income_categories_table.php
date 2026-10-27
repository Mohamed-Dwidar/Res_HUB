<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * The taxes of the extras of a category (2026-10-28, docs/payments-plan.md §3): the same three flags as the plans. A category
     * not counted as income (is_revenue = 0, e.g. VAT collected) is never taxed, whatever its flags.
     */
    public function up(): void {
        Schema::table('income_categories', function (Blueprint $table) {
            $table->boolean('vat_exempt')->default(false)->after('is_revenue');
            $table->boolean('table_tax')->default(false)->after('vat_exempt');
            $table->boolean('price_includes_tax')->default(true)->after('table_tax');
        });
    }

    public function down(): void {
        Schema::table('income_categories', function (Blueprint $table) {
            $table->dropColumn(['vat_exempt', 'table_tax', 'price_includes_tax']);
        });
    }
};
