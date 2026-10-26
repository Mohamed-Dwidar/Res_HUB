<?php

namespace Modules\EmployeeModule\app\Services;

use App\Helpers\UploaderHelper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\EmployeeModule\app\Models\Employee;
use Modules\EmployeeModule\app\Repositories\EmployeeRepository;
use Modules\UserModule\app\Services\UserService;

/**
 * Every method takes the owner account id, so an account can only reach its own employees.
 * The login (email / password) is in the users table, the image in public/uploads/employees/{id}/,
 * the attachments on the private disk (storage/app/private/employees/{id}/, downloaded through the account).
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
        return $this->employeeRepository->forAccount($accountId)->with('attachments')->findOrFail($id);
    }

    public function create($accountId, array $data)
    {
        $employee = DB::transaction(function () use ($accountId, $data) {
            $employee = $this->employeeRepository->create($this->employeeData($data) + ['account_id' => $accountId]);
            $this->userService->createFor($employee, $data);
            return $employee;
        });

        $this->storeImage($employee, $data['image'] ?? null);
        $this->storeAttachments($employee, $data['attachments'] ?? []);
        return $employee;
    }

    public function update($accountId, $id, array $data)
    {
        $employee = $this->findOne($accountId, $id);

        DB::transaction(function () use ($employee, $data) {
            $employee->update($this->employeeData($data));
            $this->userService->update($employee->user->id, $data);
        });

        $this->storeImage($employee, $data['image'] ?? null);
        $this->deleteAttachments($employee, $data['delete_attachments'] ?? []);
        $this->storeAttachments($employee, $data['attachments'] ?? []);
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

    // one attachment of one of the account's employees (download)
    public function findAttachment($accountId, $id, $attachmentId)
    {
        return $this->findOne($accountId, $id)->attachments()->findOrFail($attachmentId);
    }

    private function employeeData(array $data): array
    {
        return [
            'name' => $data['name'],
            // the columns are not nullable: 0 = none
            'position_id' => $data['position_id'] ?? 0,
            'shift_id' => $data['shift_id'] ?? 0,
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

    // $attachments: [['label' => ..., 'file' => UploadedFile], ...]
    private function storeAttachments(Employee $employee, array $attachments)
    {
        foreach ($attachments as $attachment) {
            $file = $attachment['file'] ?? null;
            if (!$file) {
                continue;
            }
            $name = Str::random(32) . '.' . strtolower($file->getClientOriginalExtension());
            $file->storeAs('employees/' . $employee->id, $name, 'local');

            $employee->attachments()->create([
                'attach_name' => $name,
                'attach_label' => trim((string) ($attachment['label'] ?? '')) ?: pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            ]);
        }
    }

    // $ids: attachments of this employee to remove
    private function deleteAttachments(Employee $employee, array $ids)
    {
        foreach ($employee->attachments()->whereIn('id', $ids)->get() as $attachment) {
            Storage::disk('local')->delete($attachment->path);
            $attachment->delete();
        }
    }
}
