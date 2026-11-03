<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * R0 (2026-11-03, docs/roles-plan.md): employees.role_id (0 = none → the account's default role) + per account ONE
     * "Front desk" role (is_default) = exactly what every employee can do today, given to every employee with role_id 0.
     * Idempotent: an account that already has its default role keeps it; employees with a role are not touched.
     * The permission list is written HERE (not read from config): a later change of the catalog must not change what this
     * migration made.
     */
    const FRONT_DESK = [
        'schedule.view',
        'reservations.view', 'reservations.create', 'reservations.edit', 'reservations.delete', 'reservations.status',
        'reservations.renew', 'reservations.mark_left',
        'packages.view', 'packages.create', 'packages.edit', 'packages.delete', 'packages.statement', 'extras.create',
        'payments.view', 'payments.create', 'payments.receipt', 'payments.refund', 'payments.edit_own_open',
        'expenses.view', 'expenses.create', 'expenses.edit_own_open',
        'shift.has',
        'members.view', 'members.create', 'members.edit',
        'messages.send',
        'contracts.view', 'contracts.draft', 'contracts.issue', 'contracts.upload_signed', 'contracts.delete_own_draft',
        'contracts.pdf',
    ];

    public function up(): void {
        if (!Schema::hasColumn('employees', 'role_id')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->unsignedBigInteger('role_id')->default(0)->after('shift_id');
                $table->index('role_id');
            });
        }

        $now = now();
        foreach (DB::table('accounts')->orderBy('id')->pluck('id') as $accountId) {
            $roleId = DB::table('roles')->where('account_id', $accountId)->where('is_default', 1)->whereNull('deleted_at')->value('id');
            if (!$roleId) {
                $roleId = DB::table('roles')->insertGetId([
                    'account_id' => $accountId,
                    'name' => 'Front desk',
                    'permissions' => json_encode(self::FRONT_DESK),
                    'idle_hours' => null,
                    'is_default' => 1,
                    'created_by' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            DB::table('employees')->where('account_id', $accountId)->where('role_id', 0)->update(['role_id' => $roleId]);
        }
    }

    // the Front desk rows go with the roles table (previous migration's down)
    public function down(): void {
        if (Schema::hasColumn('employees', 'role_id')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->dropIndex(['role_id']);
                $table->dropColumn('role_id');
            });
        }
    }
};
