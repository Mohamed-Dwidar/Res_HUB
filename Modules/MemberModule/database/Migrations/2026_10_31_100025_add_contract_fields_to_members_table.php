<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Contracts (ContractModule, 2026-10-31, docs/contracts-plan.md): the member's address, nationality and ID type; the ID number
     * stays in national_number, now VARCHAR(30) (was DOUBLE: a passport number has letters; the DOUBLE values convert as typed,
     * e.g. 29709108800599). id_type: national_id | passport, NULL = not chosen (shown as national ID when there is a number).
     * The old 0 values (= none) become NULL.
     */
    public function up(): void {
        Schema::table('members', function (Blueprint $table) {
            $table->string('national_number', 30)->nullable()->change();
            $table->string('id_type', 20)->nullable()->after('national_number');
            $table->string('nationality', 100)->nullable()->after('id_type');
            $table->text('address')->nullable()->after('nationality');
        });
        DB::table('members')->where('national_number', '0')->update(['national_number' => null]);
    }

    public function down(): void {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn(['id_type', 'nationality', 'address']);
        });
        // a passport number (letters) can't go back into a DOUBLE: emptied first
        DB::table('members')->whereRaw("national_number NOT REGEXP '^[0-9]+$'")->update(['national_number' => null]);
        Schema::table('members', function (Blueprint $table) {
            $table->double('national_number')->nullable()->change();
        });
    }
};
