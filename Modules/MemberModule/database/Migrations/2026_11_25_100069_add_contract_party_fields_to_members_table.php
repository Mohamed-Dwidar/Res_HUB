<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // the member as the lessee of a contract (2026-11-25): legal_name = the name printed on it (NULL = the member's / company's name),
    // its legal representative + title (the ID number = national_number), commercial register, tax card, a second mobile, the activity.
    public function up(): void {
        Schema::table('members', function (Blueprint $table) {
            $table->string('legal_name')->nullable();
            $table->string('representative_name')->nullable();
            $table->string('representative_title')->nullable();
            $table->string('commercial_register', 50)->nullable();
            $table->string('tax_number', 50)->nullable();
            $table->string('other_phone', 30)->nullable();
            $table->string('activity', 500)->nullable();
        });
    }

    public function down(): void {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn(['legal_name', 'representative_name', 'representative_title', 'commercial_register', 'tax_number', 'other_phone', 'activity']);
        });
    }
};
