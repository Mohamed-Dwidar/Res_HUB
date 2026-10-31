<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Contract templates of an account (ContractModule, 2026-10-31, docs/contracts-plan.md): a name (what staff see) + the printed
     * title + the body (rich text, sanitized HTML: ContractService::sanitize()) in Arabic (+ optional English) + the renewal annex
     * texts + the default term + on / off. None exist until the owner saves one (the config "Start from" texts are suggestions).
     * default_term_months: how long a contract made from it runs (12 for monthly units), NULL = the reservation's own period.
     * contract_template_unit: the units whose reservations get contracts from it; a unit has ONE template at most (UNIQUE unit_id).
     * account_id repeated for the unit-side reads. A deleted template (soft delete) loses its rows.
     */
    public function up(): void {
        Schema::create('contract_templates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->index();
            $table->string('name', 100);
            $table->string('title_ar');
            $table->string('title_en')->nullable();
            $table->longText('body_ar');
            $table->longText('body_en')->nullable();
            $table->longText('renewal_body_ar')->nullable();
            $table->longText('renewal_body_en')->nullable();
            $table->unsignedTinyInteger('default_term_months')->nullable();
            $table->boolean('is_enabled')->default(1);
            $table->nullableMorphs('creatable');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('contract_template_unit', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('contract_template_id')->index();
            $table->unsignedBigInteger('unit_id')->unique();
            $table->unsignedBigInteger('account_id')->index();
        });
    }

    public function down(): void {
        Schema::dropIfExists('contract_template_unit');
        Schema::dropIfExists('contract_templates');
    }
};
