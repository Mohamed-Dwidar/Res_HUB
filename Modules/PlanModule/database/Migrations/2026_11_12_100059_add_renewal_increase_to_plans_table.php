<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // the renewal increase (2026-11-12): the default of the period reservations made with the plan — none | percent | fixed + value;
    // existing plans = none
    public function up(): void {
        Schema::table('plans', function (Blueprint $table) {
            $table->string('renewal_increase_type', 10)->default('none')->after('invoice_by_default');
            $table->decimal('renewal_increase_value', 12, 2)->default(0)->after('renewal_increase_type');
        });
    }

    public function down(): void {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['renewal_increase_type', 'renewal_increase_value']);
        });
    }
};
