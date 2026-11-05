<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR H1 (docs/hr-plan.md §A, user 2026-11-05): the HR file IS the employees row — one row per person, with or without a login
 * (a login = a users row with userable = this employee; code that lists LOGINS uses Employee::withLogin()). Replaces the
 * separate `staff` table. Existing columns are reused as they are: name (+ new name_ar), phone / another_phone, gender,
 * birth_date, address, educational_qualification, position_id, shift_id, join_date (= hire date), leave_date (= the login is
 * off from that day; EnsureUserIsActive), role_id (the login's role).
 * national_id / bank_account / wallet_number are TEXT, encrypted with APP_KEY by the HrModule Staff model (never change APP_KEY
 * on the server; keep a backup of it).
 *
 * Backfill (account 1, the live data; each row re-checked by id + name, so nothing else is touched):
 * - employee_no = 1, 2, 3 … per account in id order (the deleted rows after the others);
 * - status = resigned since leave_date when there is one, else active;
 * - email = the login email (the contact email; editable);
 * - #3 "ABEX" (the accounting office) → is_external = 1;
 * - #2 "User" (an old test login, left 2024) → soft deleted (its users row stays: the Access Log / "Created by" of old rows).
 */
return new class extends Migration
{
    const TEST_LOGIN_REASON = 'Old test login, removed by the HR migration';

    public function up(): void
    {
        if (!Schema::hasColumn('employees', 'employee_no')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->unsignedInteger('employee_no')->default(0)->after('account_id');
                $table->boolean('is_external')->default(0)->after('employee_no');
                $table->string('name_ar')->nullable()->after('name');
                $table->string('email')->nullable()->after('another_phone');
                // work
                $table->unsignedBigInteger('department_id')->default(0)->after('position_id');
                $table->date('probation_ends_on')->nullable()->after('join_date');
                $table->string('status', 12)->default('active')->after('probation_ends_on');
                $table->date('status_date')->nullable()->after('status');
                $table->string('status_reason')->nullable()->after('status_date');
                // identity (permission hr.sensitive)
                $table->string('nationality', 50)->nullable()->after('birth_date');
                $table->string('id_type', 20)->default('national_id')->after('nationality');
                $table->text('national_id')->nullable()->after('id_type');
                $table->string('marital_status', 12)->nullable()->after('national_id');
                $table->string('military_status', 12)->nullable()->after('marital_status');
                // salary payment (hr.sensitive; bank_account / wallet_number encrypted)
                $table->string('pay_method', 10)->default('cash')->after('salary');
                $table->string('bank_name')->nullable()->after('pay_method');
                $table->text('bank_account')->nullable()->after('bank_name');
                $table->text('wallet_number')->nullable()->after('bank_account');
                // emergency contact (hr.sensitive)
                $table->string('emergency_name')->nullable()->after('wallet_number');
                $table->string('emergency_relation', 50)->nullable()->after('emergency_name');
                $table->string('emergency_phone', 30)->nullable()->after('emergency_relation');
                $table->text('notes')->nullable()->after('emergency_phone');
                $table->nullableMorphs('creatable');
                $table->index(['account_id', 'employee_no']);
                $table->index(['account_id', 'status']);
            });
        }

        // #2 "User" = an old test login: removed from the lists (soft delete)
        DB::table('employees')->where('id', 2)->where('account_id', 1)->where('name', 'User')->whereNull('deleted_at')
            ->update(['deleted_at' => now(), 'status_reason' => self::TEST_LOGIN_REASON]);

        // employee_no per account: the people in the lists first (id order), then the deleted ones (only rows without one: safe twice)
        foreach (DB::table('employees')->where('employee_no', 0)->orderByRaw('deleted_at IS NOT NULL')->orderBy('id')->get(['id', 'account_id']) as $row) {
            $next = (int) DB::table('employees')->where('account_id', $row->account_id)->max('employee_no') + 1;
            DB::table('employees')->where('id', $row->id)->update(['employee_no' => $next]);
        }

        // left logins = resigned since their leave date (only rows still at the default)
        DB::table('employees')->whereNotNull('leave_date')->where('status', 'active')->whereNull('status_date')
            ->update(['status' => 'resigned', 'status_date' => DB::raw('leave_date')]);

        // the contact email = the login email, where empty
        $employeeType = 'Modules\\EmployeeModule\\app\\Models\\Employee';
        foreach (DB::table('users')->where('userable_type', $employeeType)->get(['userable_id', 'email']) as $user) {
            DB::table('employees')->where('id', $user->userable_id)->whereNull('email')->update(['email' => $user->email]);
        }

        // #3 ABEX = the accounting office: External (not on payroll)
        DB::table('employees')->where('id', 3)->where('account_id', 1)->where('name', 'ABEX')->update(['is_external' => 1]);
    }

    public function down(): void
    {
        if (!Schema::hasColumn('employees', 'employee_no')) {
            return;
        }
        // undo the soft delete of #2 made by up() (its marker), then drop the columns
        DB::table('employees')->where('id', 2)->where('status_reason', self::TEST_LOGIN_REASON)->update(['deleted_at' => null]);

        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex(['account_id', 'employee_no']);
            $table->dropIndex(['account_id', 'status']);
            $table->dropMorphs('creatable');
            $table->dropColumn([
                'employee_no', 'is_external', 'name_ar', 'email', 'department_id', 'probation_ends_on', 'status', 'status_date',
                'status_reason', 'nationality', 'id_type', 'national_id', 'marital_status', 'military_status', 'pay_method',
                'bank_name', 'bank_account', 'wallet_number', 'emergency_name', 'emergency_relation', 'emergency_phone', 'notes',
            ]);
        });
    }
};
