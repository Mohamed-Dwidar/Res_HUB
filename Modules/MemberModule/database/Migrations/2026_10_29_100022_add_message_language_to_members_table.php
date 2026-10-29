<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * The member's WhatsApp language (MessagingModule, 2026-10-29): ar | en; NULL = the account's default (Settings › WhatsApp
     * Messages). The popup opens in it, the AR | EN toggle can change it for one send.
     */
    public function up(): void {
        Schema::table('members', function (Blueprint $table) {
            $table->char('message_language', 2)->nullable()->after('phone_key');
        });
    }

    public function down(): void {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn('message_language');
        });
    }
};
