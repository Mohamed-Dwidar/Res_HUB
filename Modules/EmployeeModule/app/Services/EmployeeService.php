<?php

namespace Modules\EmployeeModule\app\Services;

use App\Helpers\UploaderHelper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Modules\EmployeeModule\app\Models\Employee;
use Modules\EmployeeModule\app\Repositories\EmployeeRepository;
use Modules\UserModule\app\Services\UserService;

/**
 * Every method takes the owner account id, so an account can only reach its own employees.
 * An employee may have NO login (docs/hr-plan.md, 2026-11-05: the employees row is the HR file): the login (email / password)
 * is a users row, made only when an email + password are given, changed only when it exists. The HR file itself (number,
 * status, documents, the System Access section) = HrModule StaffService. Image: public/uploads/employees/{id}/.
 */
class EmployeeService
{
    use UploaderHelper;

    private $employeeRepository;
    private $userService;

    public function __construct(EmployeeRepository $employeeRepository, UserService $userService)
    {
        $this->employeeRepository = $employeeRepository;
        $this->userService = $userService;
    }

    // list query (paged / ordered by DataTables)
    public function listQuery($accountId, array $filters = [])
    {
        return $this->employeeRepository->forAccount($accountId)->filter($filters);
    }

    // 404 when the employee belongs to another account
    public function findOne($accountId, $id)
    {
        return $this->employeeRepository->forAccount($accountId)->findOrFail($id);
    }

    public function create($accountId, array $data)
    {
        $employee = DB::transaction(function () use ($accountId, $data) {
            $employee = $this->employeeRepository->create($this->employeeData($data) + ['account_id' => $accountId]);
            // a login only with an email + password (else an HR file without one)
            if (filled($data['email'] ?? null) && filled($data['password'] ?? null)) {
                $this->userService->createFor($employee, $data);
            }
            return $employee;
        });

        $this->storeImage($employee, $data['image'] ?? null);
        return $employee;
    }

    public function update($accountId, $id, array $data)
    {
        $employee = $this->findOne($accountId, $id);

        DB::transaction(function () use ($employee, $data) {
            $employee->update($this->employeeData($data));
            // no login = nothing to change there (the login is made from the HR file's System Access, StaffService)
            if ($employee->user) {
                $this->userService->update($employee->user->id, $data);
            }
        });

        $this->storeImage($employee, $data['image'] ?? null);
        return $employee;
    }

    // Mark Left (leave date = today: from now on he can not log in, EnsureUserIsActive logs him out on his next request) /
    // Undo Left (no leave date); the Employees list row menu and the employee view (2026-10-26)
    public function setLeft($accountId, $id, bool $left): Employee
    {
        $employee = $this->findOne($accountId, $id);
        $employee->update(['leave_date' => $left ? today() : null]);
        return $employee;
    }

    // soft delete; the login is removed so he can not sign in and the email can be used again
    public function deleteOne($accountId, $id)
    {
        $employee = $this->findOne($accountId, $id);

        return DB::transaction(function () use ($employee) {
            $employee->user()->delete();
            return $employee->delete();
        });
    }

    private function employeeData(array $data): array
    {
        return [
            'name' => $data['name'],
            // the columns are not nullable: 0 = none
            'position_id' => $data['position_id'] ?? 0,
            'shift_id' => $data['shift_id'] ?? 0,
            'role_id' => $data['role_id'] ?? 0,
            'phone' => $data['phone'],
            'another_phone' => $data['another_phone'] ?? null,
            'gender' => $data['gender'],
            'birth_date' => $data['birth_date'] ?? null,
            'address' => $data['address'] ?? null,
            'salary' => $data['salary'] ?? null,
            'educational_qualification' => $data['educational_qualification'] ?? null,
            'join_date' => $data['join_date'] ?? null,
            'leave_date' => $data['leave_date'] ?? null,
        ];
    }

    private function storeImage(Employee $employee, $file)
    {
        if (!$file) {
            return;
        }

        $folder = 'employees/' . $employee->id;
        if ($employee->image) {
            File::delete(public_path('uploads/' . $folder . '/' . $employee->image));
        }

        $employee->update(['image' => $this->uploadImage($file, $folder, 'employee', 400, 400)]);
    }
}
