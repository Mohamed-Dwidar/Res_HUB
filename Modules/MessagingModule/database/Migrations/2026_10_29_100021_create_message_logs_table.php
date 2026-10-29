<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Every message sent (MessagingModule, 2026-10-29; a log, no updates by the app): what it was about (messageable: a
     * reservation now; a package / payment later), to whom (member, the international number used), which type / language,
     * who (creatable), when. The message text is never stored.
     * status / external_id: the WhatsApp API only (sent | delivered | read | failed, Meta's message id); NULL for click-to-chat
     * (the log = the employee pressed Send, WhatsApp itself is not checked).
     * Indexes: the popup history + the icon state (messageable + type), a future report (account + date), the API webhook (external_id).
     */
    public function up(): void {
        Schema::create('message_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->string('channel', 20);
            $table->string('driver', 20);
            $table->string('messageable_type');
            $table->unsignedBigInteger('messageable_id');
            $table->unsignedBigInteger('member_id')->default(0);
            $table->string('type', 40);
            $table->char('language', 2);
            $table->string('phone', 20);
            $table->string('status', 20)->nullable();
            $table->string('external_id', 100)->nullable();
            $table->nullableMorphs('creatable');
            $table->timestamp('created_at')->nullable();
            $table->index(['messageable_type', 'messageable_id', 'type', 'created_at'], 'message_logs_messageable_type_index');
            $table->index(['account_id', 'created_at']);
            $table->index('external_id');
        });
    }

    public function down(): void {
        Schema::dropIfExists('message_logs');
    }
};
