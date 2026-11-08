<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Extras (2026-11-08): every active, non-system income category is an extra item; its optional default price fills the
     * Add Extra form (editable on each extra; NULL = typed every time). Nothing changes for the existing rows (NULL).
     */
    public function up(): void
    {
        Schema::table('income_categories', function (Blueprint $table) {
            $table->decimal('default_price', 12, 2)->nullable()->after('is_revenue');
        });
    }

    public function down(): void
    {
        Schema::table('income_categories', function (Blueprint $table) {
            $table->dropColumn('default_price');
        });
    }
};
