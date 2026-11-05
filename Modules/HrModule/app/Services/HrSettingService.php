<?php

namespace Modules\HrModule\app\Services;

use Modules\HrModule\app\Models\Department;
use Modules\HrModule\app\Models\DocumentType;
use Modules\HrModule\app\Models\LeaveType;
use Modules\HrModule\app\Repositories\DepartmentRepository;
use Modules\HrModule\app\Repositories\DocumentTypeRepository;

/**
 * The HR setup lists (HR › Employee Departments, HR › Document Types; 2026-11-05: two pages, the account only). Later phases add their lists here
 * (leave types, holidays …). Every method takes the owner account id.
 */
class HrSettingService
{
    public function __construct(private DepartmentRepository $departmentRepository, private DocumentTypeRepository $typeRepository)
    {
    }

    // ------------------------------------------------------------------ departments

    public function departmentsQuery($accountId)
    {
        return $this->departmentRepository->forAccount($accountId);
    }

    public function findDepartment($accountId, $id): Department
    {
        return $this->departmentRepository->forAccount($accountId)->findOrFail($id);
    }

    // [id => name] for the staff form: the active ones + the file's current one
    public function departmentOptions($accountId, $currentId = 0): array
    {
        return Department::where('account_id', $accountId)
            ->where(fn ($query) => $query->where('is_active', true)->orWhere('id', (int) $currentId))
            ->orderByLocalized()->get()->mapWithKeys(fn ($department) => [$department->id => $department->name])->all();
    }

    // the Staff list filter: [id => ['name', 'active']], active first
    public function departmentFilterOptions($accountId): array
    {
        return Department::where('account_id', $accountId)->orderByDesc('is_active')->orderByLocalized()->get()
            ->mapWithKeys(fn ($item) => [$item->id => ['name' => $item->name, 'active' => (bool) $item->is_active]])->all();
    }

    public function saveDepartment($accountId, array $data, $id = null): Department
    {
        $department = $id ? $this->findDepartment($accountId, $id) : new Department(['account_id' => $accountId]);
        $department->fill([
            'name_en' => $data['name_en'] ?? null,
            'name_ar' => $data['name_ar'] ?? null,
            'is_active' => !empty($data['is_active']),
        ])->save();
        return $department;
    }

    public function setDepartmentActive($accountId, $id, bool $active): Department
    {
        $department = $this->findDepartment($accountId, $id);
        $department->update(['is_active' => $active]);
        return $department;
    }

    // soft delete; false while job titles use it (their people belong to it)
    public function deleteDepartment($accountId, $id): bool
    {
        $department = $this->findDepartment($accountId, $id);
        return $department->positions_count > 0 ? false : (bool) $department->delete();
    }

    // ------------------------------------------------------------------ leave types (H3)

    public function leaveTypesQuery($accountId)
    {
        return LeaveType::withCount('leaves')->where('account_id', $accountId);
    }

    public function findLeaveType($accountId, $id): LeaveType
    {
        return $this->leaveTypesQuery($accountId)->findOrFail($id);
    }

    // a new type goes last; yearly_days empty = no balance kept
    public function saveLeaveType($accountId, array $data, $id = null): LeaveType
    {
        $type = $id ? $this->findLeaveType($accountId, $id) : new LeaveType([
            'account_id' => $accountId,
            'sort_order' => (int) LeaveType::where('account_id', $accountId)->max('sort_order') + 1,
        ]);
        $type->fill([
            'name_en' => $data['name_en'] ?? null,
            'name_ar' => $data['name_ar'] ?? null,
            'is_paid' => !empty($data['is_paid']),
            'yearly_days' => isset($data['yearly_days']) && $data['yearly_days'] !== '' ? round((float) $data['yearly_days'], 1) : null,
            'needs_attachment' => !empty($data['needs_attachment']),
            'is_active' => !empty($data['is_active']),
        ])->save();
        return $type;
    }

    public function setLeaveTypeActive($accountId, $id, bool $active): LeaveType
    {
        $type = $this->findLeaveType($accountId, $id);
        $type->update(['is_active' => $active]);
        return $type;
    }

    // soft delete; false while leaves use it (cancelled ones too: switch it off instead)
    public function deleteLeaveType($accountId, $id): bool
    {
        $type = $this->findLeaveType($accountId, $id);
        return $type->leaves_count > 0 ? false : (bool) $type->delete();
    }

    // ------------------------------------------------------------------ document types

    public function typesQuery($accountId)
    {
        return $this->typeRepository->forAccount($accountId);
    }

    public function findType($accountId, $id): DocumentType
    {
        return $this->typeRepository->forAccount($accountId)->findOrFail($id);
    }

    // a new type goes last
    public function saveType($accountId, array $data, $id = null): DocumentType
    {
        $type = $id ? $this->findType($accountId, $id) : new DocumentType([
            'account_id' => $accountId,
            'sort_order' => (int) DocumentType::where('account_id', $accountId)->max('sort_order') + 1,
        ]);
        $type->fill([
            'name_en' => $data['name_en'] ?? null,
            'name_ar' => $data['name_ar'] ?? null,
            'has_expiry' => !empty($data['has_expiry']),
            'remind_days' => (int) ($data['remind_days'] ?? 30),
            'is_required' => !empty($data['is_required']),
            'is_active' => !empty($data['is_active']),
        ])->save();
        return $type;
    }

    public function setTypeActive($accountId, $id, bool $active): DocumentType
    {
        $type = $this->findType($accountId, $id);
        $type->update(['is_active' => $active]);
        return $type;
    }

    // soft delete; false while documents use it (switch it off instead)
    public function deleteType($accountId, $id): bool
    {
        $type = $this->findType($accountId, $id);
        return $type->documents_count > 0 ? false : (bool) $type->delete();
    }
}
