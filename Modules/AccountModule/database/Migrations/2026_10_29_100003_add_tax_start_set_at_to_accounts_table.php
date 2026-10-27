<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * When the tax start date was set (2026-10-28, docs/payments-plan.md §3): rows created from the start date up to this moment
     * are "back-dated" — "Invoice requested" only for the owner, never pre-ticked; rows created after it follow the normal rule.
     */
    public function up(): void {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dateTime('tax_start_set_at')->nullable()->after('tax_start_date');
        });
    }

    public function down(): void {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('tax_start_set_at');
        });
    }
};
