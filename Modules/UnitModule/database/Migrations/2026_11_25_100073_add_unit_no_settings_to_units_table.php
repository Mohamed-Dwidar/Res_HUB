<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // the unit numbers of a unit's contracts (2026-11-25): a prefix (e.g. "V ") + an optional first number; the Create Contract page
    // suggests the lowest free number from them (ContractService::suggestNumber()). NULL = no numbering (the old defaults).
    public function up(): void {
        Schema::table('units', function (Blueprint $table) {
            $table->string('unit_no_prefix', 10)->nullable()->after('concurrent_usage');
            $table->unsignedInteger('unit_no_start')->nullable()->after('unit_no_prefix');
        });
    }

    public function down(): void {
        Schema::table('units', function (Blueprint $table) {
            $table->dropColumn(['unit_no_prefix', 'unit_no_start']);
        });
    }
};
