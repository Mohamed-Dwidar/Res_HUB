<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The old Employees page's attachments (user 2026-11-05): 0 rows, no files, code removed 2026-11-04 — employee documents are
 * HrModule employee_documents now. Down recreates the empty table as it was (create_employee_attachments_table).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('employee_attachments');
    }

    public function down(): void
    {
        if (!Schema::hasTable('employee_attachments')) {
            Schema::create('employee_attachments', function (Blueprint $table) {
                $table->id();
                $table->bigInteger('employee_id')->index();
                $table->string('attach_name')->nullable();
                $table->string('attach_label')->nullable();
                $table->timestamps();
            });
        }
    }
};
