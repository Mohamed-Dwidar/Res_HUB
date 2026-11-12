<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // A Cash Out settled against a reservation / package (2026-11-12): the Cash Out + a matching Cash In on it, linked both ways
    // (0 = none), both with the system payment method "Offset" (payment_methods.generated_key = 'offset', made on first use,
    // never offered in the forms): its shift line = in X, out X, net 0.
    public function up(): void {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->string('generated_key', 30)->nullable()->after('sort_order');
            $table->unique(['account_id', 'generated_key']);
        });
        Schema::table('expenses', function (Blueprint $table) {
            $table->unsignedBigInteger('offset_payment_id')->default(0)->after('is_company')->index();
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->unsignedBigInteger('offset_expense_id')->default(0)->after('refund_of')->index();
        });
    }

    public function down(): void {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['offset_expense_id']);
            $table->dropColumn('offset_expense_id');
        });
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex(['offset_payment_id']);
            $table->dropColumn('offset_payment_id');
        });
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropUnique(['account_id', 'generated_key']);
            $table->dropColumn('generated_key');
        });
    }
};
