<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    private const ACTIONS = ['close', 'close_on_behalf', 'logout_closed', 'logout_still_working', 'still_working',
        'payment_edit', 'payment_delete', 'payment_refund', 'expense_edit', 'expense_delete', 'reopen'];

    /**
     * Payments audit (2026-10-27): payment_move = the payments of a reservation moved into a package
     * (PaymentService::movePayments()); every edit / delete is logged now, open shift too (shift_closing_id 0).
     */
    public function up(): void {
        DB::statement("ALTER TABLE shift_events MODIFY action ENUM('" . implode("','", [...self::ACTIONS, 'payment_move']) . "') NOT NULL");
    }

    public function down(): void {
        if (DB::table('shift_events')->where('action', 'payment_move')->exists()) {
            throw new RuntimeException('shift_events has payment_move events: remove them first, or keep this migration.');
        }
        DB::statement("ALTER TABLE shift_events MODIFY action ENUM('" . implode("','", self::ACTIONS) . "') NOT NULL");
    }
};
