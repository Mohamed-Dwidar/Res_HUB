<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Reservation extras (2026-11-08): Σ of the reservation's extras (each = its tax_total when taxed, else its amount), kept by
     * PaymentModule ExtraService; what the reservation owes = its booking (dueAmount()) + extras_total. No reservation has extras
     * yet: 0 for all, nothing owed changes.
     */
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->decimal('extras_total', 12, 2)->default(0)->after('net_amount');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn('extras_total');
        });
    }
};
