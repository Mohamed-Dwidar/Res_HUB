<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Settings › General › Taxes (2026-10-28, docs/payments-plan.md §3): the rates (never hard-coded), the tax start date
     * (NULL = no taxes yet; rows created on or after it get a tax snapshot), how an inclusive price that can't be split exactly
     * absorbs the cent (vat | net | extra_discount), the month basis of the tax report (eta_date | created) and the
     * withholding rate (NULL until the accountant gives it).
     */
    public function up(): void {
        Schema::table('accounts', function (Blueprint $table) {
            $table->decimal('vat_rate', 5, 2)->default(14)->after('vat_enabled');
            $table->decimal('table_tax_rate', 5, 2)->default(1)->after('vat_rate');
            $table->date('tax_start_date')->nullable()->after('table_tax_rate');
            $table->string('tax_rounding', 20)->default('vat')->after('tax_start_date');
            $table->string('tax_report_basis', 20)->default('eta_date')->after('tax_rounding');
            $table->decimal('withholding_rate', 5, 2)->nullable()->after('withholding_enabled');
        });
    }

    public function down(): void {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['vat_rate', 'table_tax_rate', 'tax_start_date', 'tax_rounding', 'tax_report_basis', 'withholding_rate']);
        });
    }
};
