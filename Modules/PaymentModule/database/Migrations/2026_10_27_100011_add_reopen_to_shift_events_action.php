<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    // the actions before "reopen" (2026-10-26: the account reopens a login's last closing, ShiftClosingService::reopen())
    private const ACTIONS = ['close', 'close_on_behalf', 'logout_closed', 'logout_still_working', 'still_working',
        'payment_edit', 'payment_delete', 'payment_refund', 'expense_edit', 'expense_delete'];

    /**
     * Run the migrations: the live DB was made before "reopen" was added to the create migration, so Reopen failed there.
     */
    public function up(): void {
        DB::statement("ALTER TABLE shift_events MODIFY action ENUM('" . implode("','", [...self::ACTIONS, 'reopen']) . "') NOT NULL");
    }

    /**
     * Reverse the migrations (refused while reopen events exist: MySQL would cut them).
     */
    public function down(): void {
        if (DB::table('shift_events')->where('action', 'reopen')->exists()) {
            throw new RuntimeException('shift_events has reopen events: remove them first, or keep this migration.');
        }
        DB::statement("ALTER TABLE shift_events MODIFY action ENUM('" . implode("','", self::ACTIONS) . "') NOT NULL");
    }
};
