<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR H1 (docs/hr-plan.md §3.1): the history of an employee's file + the employee documents and their files. New tables only.
 * - employee_events: created, updated (before / after; secret fields "changed"), status, system access on / off / changed,
 *   document added / updated / deleted. Never edited.
 * - employee_documents: type, name (an "Other" document's own name), number (never for the National ID: the number is in the
 *   profile), issue / expiry dates, notes.
 * - employee_document_files: one or more files per document (National ID front + back), on the PRIVATE disk
 *   hr/{account}/{employee}/{uuid}.{ext}; original_name = the uploaded name (list + download name).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('employee_id')->index();
            $table->string('type', 30);
            $table->json('changes')->nullable();
            $table->string('reason')->nullable();
            $table->nullableMorphs('creatable');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('employee_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('employee_id')->index();
            $table->unsignedBigInteger('document_type_id')->index();
            $table->string('name')->nullable();
            $table->string('number', 100)->nullable();
            $table->date('issue_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->text('notes')->nullable();
            $table->nullableMorphs('creatable');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['account_id', 'expiry_date']);
        });

        Schema::create('employee_document_files', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('employee_document_id')->index();
            $table->string('file', 100);
            $table->string('original_name')->nullable();
            $table->unsignedInteger('size')->default(0);
            $table->nullableMorphs('creatable');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_document_files');
        Schema::dropIfExists('employee_documents');
        Schema::dropIfExists('employee_events');
    }
};
