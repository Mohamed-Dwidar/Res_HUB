<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // every discount change of a reservation / package (2026-11-17): % / fixed amount before → after, who
    // (Payment Exceptions › Discounts)
    public function up(): void {
        Schema::create('discount_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->morphs('loggable');
            $table->decimal('from_percentage', 5, 2)->default(0);
            $table->decimal('to_percentage', 5, 2)->default(0);
            $table->decimal('from_fixed', 12, 2)->default(0);
            $table->decimal('to_fixed', 12, 2)->default(0);
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->nullableMorphs('creatable');
            $table->timestamp('created_at')->nullable();
            $table->index(['account_id', 'created_at']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('discount_logs');
    }
};
