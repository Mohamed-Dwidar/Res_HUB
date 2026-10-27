<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * The plan's taxes (2026-10-28, docs/payments-plan.md §3), set by the owner: VAT standard / exempt, table tax yes / no,
     * the price includes the taxes yes / no. Read only when a reservation is saved after the tax start date (its snapshot keeps
     * them): a change affects only new reservations. Defaults = VAT, no table tax, price includes tax (what a member owes for an
     * inclusive price never changes).
     */
    public function up(): void {
        Schema::table('plans', function (Blueprint $table) {
            $table->boolean('vat_exempt')->default(false)->after('is_time_based');
            $table->boolean('table_tax')->default(false)->after('vat_exempt');
            $table->boolean('price_includes_tax')->default(true)->after('table_tax');
        });
    }

    public function down(): void {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['vat_exempt', 'table_tax', 'price_includes_tax']);
        });
    }
};
