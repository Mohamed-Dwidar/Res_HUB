<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * The Access Log (EmployeeModule, 2026-11-03, docs/roles-plan.md): the employees' logins, failed logins (a wrong password on
     * an employee's email), logouts, idle timeouts (R0), exports and sensitive actions (R1+: deletes, refunds, contract issue,
     * ETA entered, role changes). Owner page Management › Access Log. Never edited; no updated_at.
     * user_id = users.id (0 = unknown); email = the login's email at that moment (kept after the login is removed);
     * subject_type / subject_id = the record of a sensitive action (e.g. the payment deleted).
     */
    public function up(): void {
        Schema::create('access_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('user_id')->default(0);
            $table->string('email')->nullable();
            $table->string('event', 20);
            $table->string('page', 100)->nullable();
            $table->string('format', 10)->nullable();
            $table->json('filters')->nullable();
            $table->unsignedInteger('rows')->nullable();
            $table->string('subject_type', 100)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['account_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('access_logs');
    }
};
