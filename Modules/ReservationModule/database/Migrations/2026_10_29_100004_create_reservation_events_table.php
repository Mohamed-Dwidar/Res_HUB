<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Owner overrides on reservations (2026-10-29), listed in Payment Exceptions:
     * - renewed_unpaid: a period renewed before the previous one was paid (reservation = the new period, related = the unpaid one,
     *   amount = what it still owed; data: the new period's amount, the total the member owes)
     * - cancelled_paid: a reservation with payments set to a not counted status (amount = its net paid; data: status before → after)
     * reason = the owner's reason; creatable = who; no updates (a log).
     */
    public function up(): void {
        Schema::create('reservation_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('reservation_id');
            $table->unsignedBigInteger('related_reservation_id')->default(0);
            $table->string('type', 30);
            $table->string('reason', 255)->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->json('data')->nullable();
            $table->nullableMorphs('creatable');
            $table->timestamp('created_at')->nullable();
            $table->index(['account_id', 'type', 'created_at']);
            $table->index('reservation_id');
        });
    }

    public function down(): void {
        Schema::dropIfExists('reservation_events');
    }
};
