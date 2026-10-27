<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Taxes when an invoice is requested (docs/payments-plan.md §3): ONE toggle for the package, its reservations and extras
     * follow it. tax_groups = the split per tax type (VAT rate / exempt × table tax × inclusive, each with its rounding, net,
     * taxes, total; the package discount applied per group; reservations created before the tax start date = an untaxed group).
     * Totals over the groups below; owed = tax_total when taxed, else after_discount + extras (as today).
     */
    public function up(): void {
        Schema::table('packages', function (Blueprint $table) {
            $table->boolean('invoice_requested')->default(false)->after('remaining');
            $table->json('tax_groups')->nullable()->after('invoice_requested');
            $table->decimal('tax_net', 12, 2)->nullable()->after('tax_groups');
            $table->decimal('table_tax', 12, 2)->nullable()->after('tax_net');
            $table->decimal('vat', 12, 2)->nullable()->after('table_tax');
            $table->decimal('tax_extra_discount', 12, 2)->nullable()->after('vat');
            $table->decimal('tax_total', 12, 2)->nullable()->after('tax_extra_discount');
            $table->index(['account_id', 'invoice_requested', 'created_at'], 'packages_invoice_index');
        });
    }

    public function down(): void {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropIndex('packages_invoice_index');
            $table->dropColumn(['invoice_requested', 'tax_groups', 'tax_net', 'table_tax', 'vat', 'tax_extra_discount', 'tax_total']);
        });
    }
};
