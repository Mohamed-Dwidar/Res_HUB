<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // the renewal increase of a period reservation (2026-11-12): the next period's price = this price (before discount) + the
    // increase; copied to the renewal (compounds); existing reservations = none
    public function up(): void {
        Schema::table('reservations', function (Blueprint $table) {
            $table->string('renewal_increase_type', 10)->default('none')->after('net_amount');
            $table->decimal('renewal_increase_value', 12, 2)->default(0)->after('renewal_increase_type');
        });
    }

    public function down(): void {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn(['renewal_increase_type', 'renewal_increase_value']);
        });
    }
};
