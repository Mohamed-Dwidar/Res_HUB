<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR H1 (docs/hr-plan.md §3.1): the kinds of employee documents (Settings › HR). Every account gets the 9 default types below
 * (only when it has none yet: safe to run twice); the owner edits / deletes them.
 * kind: 'national_id' (the document form hides Number: the ID number lives in the employee's profile, encrypted) | 'other' (the
 * form asks a Document name; never "missing") | NULL. New table only.
 */
return new class extends Migration
{
    // [name_en, name_ar, kind, has_expiry, is_required]
    const DEFAULT_TYPES = [
        ['National ID', 'بطاقة الرقم القومي', 'national_id', 1, 1],
        ['Birth certificate', 'شهادة الميلاد', null, 0, 1],
        ['Military status certificate', 'شهادة الموقف من التجنيد', null, 0, 0],
        ['Criminal record', 'صحيفة الحالة الجنائية (فيش وتشبيه)', null, 0, 1],
        ['Qualification certificate', 'شهادة المؤهل الدراسي', null, 0, 1],
        ['Personal photos', 'صور شخصية', null, 0, 1],
        ['Insurance print', 'برنت التأمينات', null, 0, 0],
        ['Medical certificate', 'الشهادة الصحية', null, 1, 0],
        ['Other', 'أخرى', 'other', 0, 0],
    ];

    public function up(): void
    {
        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->index();
            $table->string('name_en')->nullable();
            $table->string('name_ar')->nullable();
            $table->string('kind', 20)->nullable();
            $table->boolean('has_expiry')->default(0);
            $table->unsignedSmallInteger('remind_days')->default(30);
            $table->boolean('is_required')->default(0);
            $table->boolean('is_active')->default(1);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        $now = now();
        foreach (DB::table('accounts')->pluck('id') as $accountId) {
            if (DB::table('document_types')->where('account_id', $accountId)->exists()) {
                continue;
            }
            foreach (self::DEFAULT_TYPES as $index => [$en, $ar, $kind, $expiry, $required]) {
                DB::table('document_types')->insert([
                    'account_id' => $accountId, 'name_en' => $en, 'name_ar' => $ar, 'kind' => $kind, 'has_expiry' => $expiry,
                    'remind_days' => 30, 'is_required' => $required, 'is_active' => 1, 'sort_order' => $index + 1,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('document_types');
    }
};
