<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Every change of "Invoice requested" (docs/payments-plan.md §3 Controls): the row (reservation / package / other-income
     * payment), before → after, the amount paid on it at that moment (> 0 = "Invoice toggled" in Payment Exceptions), who, when.
     */
    public function up(): void {
        Schema::create('invoice_request_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->morphs('loggable');
            $table->boolean('from_value');
            $table->boolean('to_value');
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->nullableMorphs('creatable');
            $table->timestamp('created_at')->nullable();
            $table->index(['account_id', 'created_at']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('invoice_request_logs');
    }
};
