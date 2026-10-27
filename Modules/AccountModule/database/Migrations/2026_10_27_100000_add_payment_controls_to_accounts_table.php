<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Settings › General, Payment Controls (2026-10-27): a cash in / refund / cash out recorded more than late_entry_hours after
     * the end of its business date = "Late entry"; cash outs of employees from expense_review_amount = the Payment Exceptions
     * report's Expenses tab.
     */
    public function up(): void {
        Schema::table('accounts', function (Blueprint $table) {
            $table->unsignedSmallInteger('late_entry_hours')->default(12)->after('renewal_days');
            $table->decimal('expense_review_amount', 12, 2)->default(500)->after('late_entry_hours');
        });
    }

    public function down(): void {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['late_entry_hours', 'expense_review_amount']);
        });
    }
};
