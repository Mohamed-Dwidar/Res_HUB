<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR H3 (docs/hr-plan.md §3.4): the account's leave types (HR › Leave Types, owner).
 * - is_paid: 0 = unpaid (payroll H5 deducts its days × the daily rate);
 * - yearly_days: the yearly balance of everyone (editable per person + year: employee_leave_balances); NULL = no balance kept;
 * - needs_attachment: a warning when a leave of this type has no file (e.g. a sick note) — never a block.
 * 4 starting types for every account (edit / switch off freely; the numbers are a start, the accounting office confirms them):
 * Annual (paid, 21 days), Casual (paid, 6), Sick (paid, no balance, attachment), Unpaid (no balance). Idempotent: an account
 * that has leave types already is skipped. down() drops the table.
 */
return new class extends Migration
{
    private const DEFAULT_TYPES = [
        // [en, ar, is_paid, yearly_days, needs_attachment]
        ['Annual Leave', 'إجازة سنوية', 1, 21, 0],
        ['Casual Leave', 'إجازة عارضة', 1, 6, 0],
        ['Sick Leave', 'إجازة مرضية', 1, null, 1],
        ['Unpaid Leave', 'إجازة بدون أجر', 0, null, 0],
    ];

    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->index();
            $table->string('name_en')->nullable();
            $table->string('name_ar')->nullable();
            $table->boolean('is_paid')->default(1);
            $table->decimal('yearly_days', 5, 1)->nullable();
            $table->boolean('needs_attachment')->default(0);
            $table->boolean('is_active')->default(1);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        $now = now();
        foreach (DB::table('accounts')->pluck('id') as $accountId) {
            if (DB::table('leave_types')->where('account_id', $accountId)->exists()) {
                continue;
            }
            foreach (self::DEFAULT_TYPES as $index => [$en, $ar, $paid, $days, $attachment]) {
                DB::table('leave_types')->insert([
                    'account_id' => $accountId, 'name_en' => $en, 'name_ar' => $ar, 'is_paid' => $paid, 'yearly_days' => $days,
                    'needs_attachment' => $attachment, 'is_active' => 1, 'sort_order' => $index + 1, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_types');
    }
};
