<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // Settings › General (2026-11-12): "Allow settling a Cash Out against a reservation" — off by default
    public function up(): void {
        Schema::table('accounts', function (Blueprint $table) {
            $table->boolean('cash_out_offset_enabled')->default(false)->after('vat_enabled');
        });
    }

    public function down(): void {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('cash_out_offset_enabled');
        });
    }
};
