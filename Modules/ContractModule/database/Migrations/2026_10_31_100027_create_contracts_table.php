<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Contracts (ContractModule Phase 2, 2026-10-31, docs/contracts-plan.md §2): created by hand from a reservation (never
     * automatically). One contract per TERM (starts_on → ends_on): it covers the member's periods of that unit starting inside it;
     * kind = renewal + renewal_of = the contract it renews (printed with the template's renewal annex).
     * status: draft (editable, printed with a DRAFT watermark) → issued (contract_no + the snapshot, never edited) → signed
     * (signed_on + the scan, optional) → ended | cancelled (Phase 3).
     * contract_no: NULL until issued; MAX + 1 per account, never below contract_settings.number_start (UNIQUE per account).
     * body_ar / body_en: a draft = the owner's edited text with {placeholders} (NULL = the template's); issued = the rendered text
     * (the snapshot); values = every placeholder value at issue {ar: {...}, en: {...}} (the PDF tables read them).
     * The contract's OWN data (2026-11-01, user: every printed value is the contract's, like the old DMS form; pre-filled from the
     * reservation / member / company / Settings › Contracts, edited freely, never written back unless "Also save to member /
     * company"): unit_number, contract_date, duration_text, term_amount (= the contract value) + currency, tenant_* (name,
     * representative, company, title, national ID, mobile, email), unit_address, activity, deposit_amount, notes. The PDF and the
     * placeholders use them. Fixed links: reservation, member, unit, template.
     * The old DMS import (later): source = dms + source_id (its id), contract_no kept, its fields straight into these columns
     * (member_id / unit_id 0 when they can't be matched).
     * signed_file: a private file (storage/app/private/contracts/{account_id}/).
     */
    public function up(): void {
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->unsignedInteger('contract_no')->nullable();
            $table->string('kind', 10)->default('contract');
            $table->unsignedBigInteger('renewal_of')->default(0)->index();
            $table->string('source', 10)->default('app');
            $table->unsignedBigInteger('source_id')->nullable();

            $table->unsignedBigInteger('contract_template_id')->default(0);
            $table->string('template_name', 100)->nullable();
            $table->unsignedBigInteger('reservation_id')->default(0)->index();
            $table->unsignedBigInteger('member_id')->default(0)->index();
            $table->unsignedBigInteger('company_id')->default(0);
            $table->string('party_type', 12)->default('individual');
            $table->unsignedBigInteger('space_id')->default(0);
            $table->unsignedBigInteger('unit_id')->default(0)->index();

            // the contract's own data (pre-filled, editable per contract)
            $table->string('unit_number', 100)->nullable();
            $table->date('contract_date')->nullable();
            $table->string('duration_text', 100)->nullable();
            $table->string('currency', 50)->nullable();
            $table->string('tenant_name')->nullable();
            $table->string('tenant_representative')->nullable();
            $table->string('tenant_company')->nullable();
            $table->string('tenant_title')->nullable();
            $table->string('tenant_national_id', 30)->nullable();
            $table->string('tenant_mobile', 30)->nullable();
            $table->string('tenant_email')->nullable();
            $table->text('unit_address')->nullable();

            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedTinyInteger('term_months')->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            // the contract value (pre-filled = the price for the term, editable) + currency
            $table->decimal('term_amount', 12, 2)->default(0);
            $table->decimal('deposit_amount', 12, 2)->nullable();
            $table->string('activity')->nullable();

            $table->string('title_ar')->nullable();
            $table->string('title_en')->nullable();
            $table->longText('body_ar')->nullable();
            $table->longText('body_en')->nullable();
            $table->json('values')->nullable();

            $table->string('status', 12)->default('draft');
            $table->timestamp('issued_at')->nullable();
            $table->unsignedBigInteger('issued_by')->nullable();
            $table->date('signed_on')->nullable();
            $table->string('signed_file')->nullable();
            $table->unsignedBigInteger('signed_by')->nullable();
            $table->date('ended_on')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->text('notes')->nullable();

            $table->nullableMorphs('creatable');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['account_id', 'contract_no']);
            $table->index(['account_id', 'status']);
            $table->index(['account_id', 'ends_on']);
            $table->index(['account_id', 'source', 'source_id']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('contracts');
    }
};
