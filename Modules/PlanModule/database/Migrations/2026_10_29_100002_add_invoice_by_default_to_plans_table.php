<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // "Invoice by default" (docs/payments-plan.md §3): pre-ticks "Invoice requested" on NEW reservations of the plan; default off
    public function up(): void {
        Schema::table('plans', function (Blueprint $table) {
            $table->boolean('invoice_by_default')->default(false)->after('price_includes_tax');
        });
    }

    public function down(): void {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('invoice_by_default');
        });
    }
};
