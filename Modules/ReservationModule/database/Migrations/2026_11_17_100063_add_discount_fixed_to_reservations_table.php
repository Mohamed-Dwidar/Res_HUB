<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // a fixed-amount discount (2026-11-17): the form's Discount Type = Percentage (discount_percentage) or Fixed amount (this,
    // the % = 0), before tax: net_amount = amount − discount_value (the %) − discount_fixed; discount_fixed_repeat = copied to the
    // renewal (one-off by default).
    // Existing reservations = 0 (net unchanged).
    public function up(): void {
        Schema::table('reservations', function (Blueprint $table) {
            $table->decimal('discount_fixed', 12, 2)->default(0)->after('discount_value');
            $table->boolean('discount_fixed_repeat')->default(false)->after('discount_fixed');
        });
    }

    public function down(): void {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn(['discount_fixed', 'discount_fixed_repeat']);
        });
    }
};
