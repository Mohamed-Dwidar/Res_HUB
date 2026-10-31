<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * The messages of an account (MessagingModule, 2026-10-29; by unit + name 2026-10-31, before go-live): a name (what staff
     * see) + the texts (body_en empty = Arabic is used) + on / off + suggest_when (NULL = Never; else config
     * messagingmodule.suggest_when: new_booking | before_renewal | renewal_day | after_renewal | payment_due | marked_left =
     * the ★ in the popup + the popup opened after a save / Mark left). None exist until the owner saves one (the config
     * "Start from" texts are only suggestions).
     * message_template_unit: the units a message is used for (a reservation of a unit sees only its unit's messages; a unit
     * without any = no WhatsApp icon). A unit has at most ONE message per suggest_when (NULL = unlimited): checked by
     * MessagingService (transaction + lock), not by the DB. account_id repeated for the unit-side reads.
     * api_*: the future WhatsApp API template (name, approval status per language, the placeholder order = Meta's {{1}}, {{2}} ...),
     * unused by click-to-chat.
     */
    public function up(): void {
        Schema::create('message_templates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->string('channel', 20)->default('whatsapp');
            $table->string('name', 100);
            $table->string('suggest_when', 30)->nullable();
            $table->boolean('is_enabled')->default(1);
            $table->text('body_ar');
            $table->text('body_en')->nullable();
            $table->string('api_template_name', 100)->nullable();
            $table->string('api_status_ar', 20)->nullable();
            $table->string('api_status_en', 20)->nullable();
            $table->json('api_variables')->nullable();
            $table->nullableMorphs('creatable');
            $table->timestamps();
            $table->index(['account_id', 'channel']);
        });

        Schema::create('message_template_unit', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('message_template_id');
            $table->unsignedBigInteger('unit_id');
            $table->unsignedBigInteger('account_id');
            $table->unique(['message_template_id', 'unit_id']);
            $table->index(['account_id', 'unit_id']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('message_template_unit');
        Schema::dropIfExists('message_templates');
    }
};
