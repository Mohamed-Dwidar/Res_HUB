<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Taxes when an invoice is requested (docs/payments-plan.md §3). invoice_requested: default no (pre-ticked by the plan's
     * invoice_by_default; inside a package = the package's). Rows created before the tax start date keep everything NULL / 0:
     * what they owe never changes. owed = tax_total when the invoice is requested and taxed, else net_amount (as today).
     */
    public function up(): void {
        Schema::table('reservations', function (Blueprint $table) {
            $table->boolean('invoice_requested')->default(false)->after('remaining');
            // the tax TYPE used (stored on every save from the tax start date, even without an invoice)
            $table->decimal('tax_vat_rate', 5, 2)->nullable()->after('invoice_requested');
            $table->boolean('tax_vat_exempt')->nullable()->after('tax_vat_rate');
            $table->decimal('tax_table_rate', 5, 2)->nullable()->after('tax_vat_exempt');
            $table->boolean('tax_inclusive')->nullable()->after('tax_table_rate');
            $table->string('tax_rounding', 20)->nullable()->after('tax_inclusive');
            // the amounts (only while the invoice is requested; NULL = no tax lines)
            $table->decimal('tax_net', 12, 2)->nullable()->after('tax_rounding');
            $table->decimal('table_tax', 12, 2)->nullable()->after('tax_net');
            $table->decimal('vat', 12, 2)->nullable()->after('table_tax');
            $table->decimal('tax_extra_discount', 12, 2)->nullable()->after('vat');
            $table->decimal('tax_total', 12, 2)->nullable()->after('tax_extra_discount');
            // the ETA to-do (invoice requested, by created date)
            $table->index(['account_id', 'invoice_requested', 'created_at'], 'reservations_invoice_index');
        });
    }

    public function down(): void {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropIndex('reservations_invoice_index');
            $table->dropColumn(['invoice_requested', 'tax_total', 'tax_vat_rate', 'tax_vat_exempt', 'tax_table_rate', 'tax_inclusive', 'tax_rounding', 'tax_net', 'table_tax', 'vat', 'tax_extra_discount']);
        });
    }
};
