<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // the contract's own lessor data (2026-11-25; pre-filled from Settings › Contracts, edited per contract; NULL = never set: the
    // settings' value, '' = emptied on this contract) + the lessee's commercial register / tax card; tenant_mobile holds several
    // numbers, activity a long text.
    public function up(): void {
        Schema::table('contracts', function (Blueprint $table) {
            $table->string('lessor_name')->nullable()->after('currency');
            $table->string('lessor_commercial_register', 50)->nullable()->after('lessor_name');
            $table->string('lessor_tax_number', 50)->nullable()->after('lessor_commercial_register');
            $table->string('lessor_representative')->nullable()->after('lessor_tax_number');
            $table->string('lessor_representative_title')->nullable()->after('lessor_representative');
            $table->string('lessor_representative_national_id', 30)->nullable()->after('lessor_representative_title');
            $table->string('tenant_commercial_register', 50)->nullable()->after('tenant_national_id');
            $table->string('tenant_tax_number', 50)->nullable()->after('tenant_commercial_register');
            $table->string('tenant_mobile', 100)->nullable()->change();
            $table->string('activity', 500)->nullable()->change();
        });
    }

    // tenant_mobile / activity stay wide (a longer value saved meanwhile would not fit back)
    public function down(): void {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn(['lessor_name', 'lessor_commercial_register', 'lessor_tax_number', 'lessor_representative', 'lessor_representative_title',
                'lessor_representative_national_id', 'tenant_commercial_register', 'tenant_tax_number']);
        });
    }
};
