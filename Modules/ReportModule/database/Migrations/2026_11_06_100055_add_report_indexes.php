<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reports (docs/reports-plan.md §0): indexes only, no data change.
 *  - payments (account_id, paid_at): the Income report / any report by payment date (today only paid_at alone).
 *  - reservations (account_id, remaining): the Outstanding & credits report (remaining <> 0 of one account).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->index(['account_id', 'paid_at'], 'payments_account_paid_at_index');
        });
        Schema::table('reservations', function (Blueprint $table) {
            $table->index(['account_id', 'remaining'], 'reservations_account_remaining_index');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_account_paid_at_index');
        });
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropIndex('reservations_account_remaining_index');
        });
    }
};
