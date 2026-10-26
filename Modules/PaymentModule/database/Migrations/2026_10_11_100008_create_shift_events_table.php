<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void {
        // the shift history: closings (by the collector / the account), payments changed after closing, and the reminder answers
        Schema::create('shift_events', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('account_id')->index();
            // users.id of the collector whose shift it is
            $table->bigInteger('user_id')->index();
            // 0 = the open period (reminder answers)
            $table->bigInteger('shift_closing_id')->default(0)->index();
            // the payment changed after closing (payment_edit / payment_delete / payment_refund), else 0
            $table->bigInteger('payment_id')->default(0)->index();
            // the expense changed after closing (expense_edit / expense_delete), else 0
            $table->bigInteger('expense_id')->default(0)->index();
            // users.id of who did it (the employee or the account)
            $table->bigInteger('by_user_id');
            $table->enum('action', ['close', 'close_on_behalf', 'logout_closed', 'logout_still_working', 'still_working',
                'payment_edit', 'payment_delete', 'payment_refund', 'expense_edit', 'expense_delete', 'reopen']);
            // required on still_working, the payment_* actions and reopen
            $table->string('reason')->nullable();
            // the closing's totals at that moment (close, payment_edit / payment_delete: after the change)
            $table->json('totals')->nullable();
            // payment_*: {before: {...}, after: {...}} of the payment
            $table->json('changes')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {
        Schema::dropIfExists('shift_events');
    }
};
