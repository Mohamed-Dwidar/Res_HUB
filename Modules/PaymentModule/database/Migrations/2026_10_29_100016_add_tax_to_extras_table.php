<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * The tax type / amounts of an extra (docs/payments-plan.md §3): extras exist only on packages and follow the package's
     * "Invoice requested" (no toggle of their own); type from the income category; adjustments are never taxed.
     */
    public function up(): void {
        Schema::table('extras', function (Blueprint $table) {
            // the tax TYPE used (stored on every save from the tax start date, even without an invoice)
            $table->decimal('tax_vat_rate', 5, 2)->nullable()->after('amount');
            $table->boolean('tax_vat_exempt')->nullable()->after('tax_vat_rate');
            $table->decimal('tax_table_rate', 5, 2)->nullable()->after('tax_vat_exempt');
            $table->boolean('tax_inclusive')->nullable()->after('tax_table_rate');
            $table->string('tax_rounding', 20)->nullable()->after('tax_inclusive');
            // the amounts (only while the invoice is requested; NULL = no tax lines)
            $table->decimal('tax_net', 12, 2)->nullable()->after('tax_rounding');
            $table->decimal('table_tax', 12, 2)->nullable()->after('tax_net');
            $table->decimal('vat', 12, 2)->nullable()->after('table_tax');
            $table->decimal('tax_extra_discount', 12, 2)->nullable()->after('vat');
            // what the extra adds to the package when taxed (exclusive category: amount + taxes), else = amount
            $table->decimal('tax_total', 12, 2)->nullable()->after('tax_extra_discount');
        });
    }

    public function down(): void {
        Schema::table('extras', function (Blueprint $table) {
            $table->dropColumn(['tax_total', 'tax_vat_rate', 'tax_vat_exempt', 'tax_table_rate', 'tax_inclusive', 'tax_rounding', 'tax_net', 'table_tax', 'vat', 'tax_extra_discount']);
        });
    }
};
