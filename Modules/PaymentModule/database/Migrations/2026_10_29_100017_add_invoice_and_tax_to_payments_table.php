<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Other-income cash ins (no reservation / package) with an invoice (docs/payments-plan.md §3): the toggle (pre-ticked by the
     * category's invoice_by_default) + the snapshot; the amount stays the money collected = the total. Unused (0 / NULL) on
     * reservation / package payments, refunds, withholding.
     */
    public function up(): void {
        Schema::table('payments', function (Blueprint $table) {
            $table->boolean('invoice_requested')->default(false)->after('amount');
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
            $table->index(['account_id', 'invoice_requested', 'created_at'], 'payments_invoice_index');
        });
    }

    public function down(): void {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_invoice_index');
            $table->dropColumn(['invoice_requested', 'tax_vat_rate', 'tax_vat_exempt', 'tax_table_rate', 'tax_inclusive', 'tax_rounding', 'tax_net', 'table_tax', 'vat', 'tax_extra_discount']);
        });
    }
};
