<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // a fixed-amount discount (2026-11-17): the Edit form's Discount Type = Percentage (discount_percentage) or Fixed amount (this,
    // the % = 0), before tax: after_discount = price − price × % − discount_fixed. Existing packages = 0 (net unchanged).
    public function up(): void {
        Schema::table('packages', function (Blueprint $table) {
            $table->decimal('discount_fixed', 12, 2)->default(0)->after('discount_percentage');
        });
    }

    public function down(): void {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn(['discount_fixed']);
        });
    }
};
