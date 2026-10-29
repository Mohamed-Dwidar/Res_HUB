<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * The message texts (MessagingModule, 2026-10-29): per account + channel + type (config messagingmodule.types) a default
     * (space_id 0 = all spaces) and optional space versions (space_id = that space: used instead of the default for its
     * reservations; is_enabled 0 on a space version = the type is off for that space). body_en empty = Arabic is used.
     * api_*: the future WhatsApp API template (name, approval status per language, the placeholder order = Meta's {{1}}, {{2}} ...),
     * unused by click-to-chat.
     */
    public function up(): void {
        Schema::create('message_templates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->string('channel', 20)->default('whatsapp');
            $table->string('type', 40);
            $table->unsignedBigInteger('space_id')->default(0);
            $table->boolean('is_enabled')->default(1);
            $table->text('body_ar');
            $table->text('body_en')->nullable();
            $table->string('api_template_name', 100)->nullable();
            $table->string('api_status_ar', 20)->nullable();
            $table->string('api_status_en', 20)->nullable();
            $table->json('api_variables')->nullable();
            $table->nullableMorphs('creatable');
            $table->timestamps();
            $table->unique(['account_id', 'channel', 'type', 'space_id']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('message_templates');
    }
};
