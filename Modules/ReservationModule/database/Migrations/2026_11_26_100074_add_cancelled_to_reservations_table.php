<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // when / who cancelled a reservation (2026-11-26, user: shown in the reservation view): set when a counted status changes
    // to a not counted one (Cancelled ...), emptied when it is restored. cancelled_by = users.id (0 = none).
    // Existing cancelled reservations = NULL (the date was never recorded: the view shows none).
    public function up(): void {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dateTime('cancelled_at')->nullable()->after('left_by');
            $table->unsignedBigInteger('cancelled_by')->default(0)->after('cancelled_at');
        });
    }

    public function down(): void {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn(['cancelled_at', 'cancelled_by']);
        });
    }
};
