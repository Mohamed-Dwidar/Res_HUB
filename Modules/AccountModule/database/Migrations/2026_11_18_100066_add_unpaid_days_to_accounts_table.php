<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // Settings › General (2026-11-18): the Schedule's "Unpaid (past)" list = hourly / full day reservations that ended in the last
    // N days and still owe (default 30; older ones = a link to the Outstanding report)
    public function up(): void {
        Schema::table('accounts', function (Blueprint $table) {
            $table->unsignedSmallInteger('unpaid_days')->default(30)->after('renewal_days');
        });
    }

    public function down(): void {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('unpaid_days');
        });
    }
};
