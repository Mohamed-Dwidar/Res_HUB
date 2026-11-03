<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Roles (EmployeeModule, R0 2026-11-03, docs/roles-plan.md): what an employee may do, per account (Settings › Roles).
     * permissions = the ticked keys of config('employeemodule.permissions') (json list); idle_hours = log out after N hours
     * without a request (NULL = no limit); is_default = the account's "Front desk" (made by the next migration): used for an
     * employee without a role (role_id 0) and for new employees, can't be deleted. created_by = users.id (0 = the migration).
     */
    public function up(): void {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->string('name');
            $table->json('permissions')->nullable();
            $table->unsignedTinyInteger('idle_hours')->nullable();
            $table->boolean('is_default')->default(false);
            $table->unsignedBigInteger('created_by')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index('account_id');
        });
    }

    public function down(): void {
        Schema::dropIfExists('roles');
    }
};
