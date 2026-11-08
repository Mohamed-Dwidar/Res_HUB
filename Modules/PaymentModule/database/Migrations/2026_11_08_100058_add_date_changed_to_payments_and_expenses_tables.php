<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * "Date changed by owner" (2026-11-08): when the owner edits the date of a cash in / refund / withholding (paid_at) or a cash out
     * (spent_at), the row is stamped (when + by whom) — shown instead of "Late entry", which now means an EMPLOYEE's entry recorded
     * after its date. Existing rows: NULL / 0 (nothing changed).
     */
    public function up(): void
    {
        foreach (['payments' => 'paid_at', 'expenses' => 'spent_at'] as $table => $after) {
            Schema::table($table, function (Blueprint $table) use ($after) {
                $table->timestamp('date_changed_at')->nullable()->after($after);
                $table->unsignedBigInteger('date_changed_by')->default(0)->after('date_changed_at');
            });
        }
    }

    public function down(): void
    {
        foreach (['payments', 'expenses'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn(['date_changed_at', 'date_changed_by']);
            });
        }
    }
};
