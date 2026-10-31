<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Contracts (ContractModule, 2026-10-31, docs/contracts-plan.md): what a contract with a company needs — commercial register,
     * tax number, address, and its legal representative (name, title, national ID). To issue a company contract: the commercial
     * register + the representative's name.
     */
    public function up(): void {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('commercial_register', 50)->nullable()->after('name_en');
            $table->string('tax_number', 50)->nullable()->after('commercial_register');
            $table->text('address')->nullable()->after('tax_number');
            $table->string('representative_name')->nullable()->after('address');
            $table->string('representative_title')->nullable()->after('representative_name');
            $table->string('representative_national_id', 30)->nullable()->after('representative_title');
        });
    }

    public function down(): void {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['commercial_register', 'tax_number', 'address', 'representative_name', 'representative_title', 'representative_national_id']);
        });
    }
};
