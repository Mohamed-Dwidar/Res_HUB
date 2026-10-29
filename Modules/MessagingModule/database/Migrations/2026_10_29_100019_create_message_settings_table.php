<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Settings › WhatsApp Messages (MessagingModule, 2026-10-29): one row per account + channel, created on the first save
     * (until then MessagingService::settings() gives these defaults).
     * driver: click_to_chat (a wa.me link, the employee presses Send in WhatsApp) | api (later: the WhatsApp Cloud API).
     * deposit_percent → {deposit_percent} / {deposit_amount}; payment_details → {payment_details} (InstaPay, bank transfer ...).
     */
    public function up(): void {
        Schema::create('message_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->string('channel', 20)->default('whatsapp');
            $table->boolean('is_enabled')->default(1);
            $table->string('driver', 20)->default('click_to_chat');
            $table->char('default_language', 2)->default('ar');
            $table->decimal('deposit_percent', 5, 2)->default(25);
            $table->text('payment_details')->nullable();
            $table->timestamps();
            $table->unique(['account_id', 'channel']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('message_settings');
    }
};
