<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Reference no. rule (2026-10-27): a payment method that needs a reference (every method except cash) → a new cash in, refund
     * or cash out with it needs "Reference no.", unique per account + method (PaymentService / ExpenseService). Expenses get the
     * reference column; the indexes serve that check.
     */
    public function up(): void {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->boolean('requires_reference')->default(true)->after('is_active');
        });
        // the cash method(s): no reference (the owner can change it in Settings › Payment Methods)
        DB::table('payment_methods')->whereIn('name_en', ['Cash', 'cash'])->update(['requires_reference' => false]);

        Schema::table('expenses', function (Blueprint $table) {
            $table->string('reference')->nullable()->after('payment_method_id');
            $table->index(['account_id', 'payment_method_id', 'reference'], 'expenses_reference_index');
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->index(['account_id', 'payment_method_id', 'reference'], 'payments_reference_index');
        });
    }

    public function down(): void {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_reference_index');
        });
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex('expenses_reference_index');
            $table->dropColumn('reference');
        });
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn('requires_reference');
        });
    }
};
