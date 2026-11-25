<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // A contract's history (Phase 3, 2026-11-25): issued | signed | back_to_draft | ended | cancelled — when, by whom, the date it
    // applies to (event_date: the end / cancel date), the reason, data (json: number, earlier dates …). Never edited.
    public function up(): void {
        Schema::create('contract_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->index();
            $table->unsignedBigInteger('contract_id')->index();
            $table->string('type', 20);
            $table->date('event_date')->nullable();
            $table->string('reason')->nullable();
            $table->json('data')->nullable();
            $table->nullableMorphs('creatable');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void {
        Schema::dropIfExists('contract_events');
    }
};
