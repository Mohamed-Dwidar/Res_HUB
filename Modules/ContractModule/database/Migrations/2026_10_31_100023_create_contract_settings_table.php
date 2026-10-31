<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Settings › Contracts (ContractModule, 2026-10-31, docs/contracts-plan.md): the lessor data printed on every contract (the old
     * DMS kept it in translation files) + number_start. One row per account, created on the first save (until then
     * ContractService::settings() gives the defaults: the account's name / address).
     * number_start: the first contract number of the account; a new contract gets max(number_start, highest + 1) — set by the
     * owner when the old DMS contracts are imported (their numbers are kept).
     */
    public function up(): void {
        Schema::create('contract_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->unique();
            $table->string('lessor_name_ar')->nullable();
            $table->string('lessor_name_en')->nullable();
            $table->string('commercial_register', 50)->nullable();
            $table->string('tax_number', 50)->nullable();
            $table->string('representative_name')->nullable();
            $table->string('representative_title')->nullable();
            $table->string('representative_national_id', 30)->nullable();
            $table->text('property_address_ar')->nullable();
            $table->text('property_address_en')->nullable();
            $table->unsignedInteger('number_start')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('contract_settings');
    }
};
