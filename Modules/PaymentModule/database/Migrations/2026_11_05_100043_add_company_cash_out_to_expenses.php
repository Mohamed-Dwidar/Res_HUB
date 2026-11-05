<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR H4 part 1 (docs/hr-plan.md §6b): the account-level ("company") Cash Out — advances (H4) and salaries (H5) are paid by the
 * company, never from a front-desk shift.
 * - expenses.is_company: 1 = a company Cash Out made by the system (ExpenseService::createCompany()): in no shift, never in a
 *   hand-over (shift_closing_id stays 0 forever), not editable / deletable from the Cash Out forms.
 * - expense_categories.generated_key: NULL = a normal category; 'advances' / 'salaries' = the system's category (made on first
 *   use), not offered in the Cash Out form. Unique per account.
 * Columns only, no data change (every existing row = 0 / NULL). down() drops them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->boolean('is_company')->default(0)->after('shift_closing_id');
        });
        Schema::table('expense_categories', function (Blueprint $table) {
            $table->string('generated_key', 30)->nullable()->after('name_en');
            $table->unique(['account_id', 'generated_key']);
        });
    }

    public function down(): void
    {
        Schema::table('expense_categories', function (Blueprint $table) {
            $table->dropUnique(['account_id', 'generated_key']);
            $table->dropColumn('generated_key');
        });
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn('is_company');
        });
    }
};
