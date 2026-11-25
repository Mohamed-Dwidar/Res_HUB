<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // Template type (2026-11-25): contract | renewal (a renewal annex template, printed for a renewed term). A unit has one template
    // of each type: contract_template_unit gets the kind and its UNIQUE moves from unit_id to (unit_id, kind). Existing rows = contract.
    public function up(): void {
        Schema::table('contract_templates', function (Blueprint $table) {
            $table->string('kind', 10)->default('contract')->after('name');
        });
        Schema::table('contract_template_unit', function (Blueprint $table) {
            $table->string('kind', 10)->default('contract')->after('unit_id');
            $table->dropUnique(['unit_id']);
            $table->unique(['unit_id', 'kind']);
        });
    }

    // the renewal annex templates' unit rows go first (unit_id must be unique again)
    public function down(): void {
        DB::table('contract_template_unit')->where('kind', '!=', 'contract')->delete();
        Schema::table('contract_template_unit', function (Blueprint $table) {
            $table->dropUnique(['unit_id', 'kind']);
            $table->unique('unit_id');
            $table->dropColumn('kind');
        });
        Schema::table('contract_templates', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
