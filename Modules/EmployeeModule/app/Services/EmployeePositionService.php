<?php

namespace Modules\EmployeeModule\app\Services;

use Modules\EmployeeModule\app\Repositories\EmployeePositionRepository;

/**
 * Every method takes the owner account id, so an account can only reach its own positions.
 */
class EmployeePositionService
{
    private $positionRepository;

    public function __construct(EmployeePositionRepository $positionRepository)
    {
        $this->positionRepository = $positionRepository;
    }

    // list query (paged / ordered by DataTables)
    public function listQuery($accountId, array $filters = [])
    {
        return $this->positionRepository->forAccount($accountId)->filter($filters);
    }

    // [id => name] for the employee form: the active ones + the employee's current one (even if inactive)
    public function options($accountId, $currentId = null): array
    {
        return $this->positionRepository->forAccount($accountId)
            ->where(fn ($query) => $query->where('is_active', true)->orWhere('id', (int) $currentId))
            ->orderBy('name')->get()->mapWithKeys(fn ($position) => [$position->id => $this->optionLabel($position)])->all();
    }

    // the staff form's Job Title (Department → Job Title): [id => ['name', 'data' => ['match' => 'department_id:N' | '*']]] —
    // '*' = a title with no department, offered with every department (custom.js filterChain)
    public function optionsWithDepartment($accountId, $currentId = null): array
    {
        return $this->positionRepository->forAccount($accountId)
            ->where(fn ($query) => $query->where('is_active', true)->orWhere('id', (int) $currentId))
            ->orderBy('name')->get()->mapWithKeys(fn ($position) => [$position->id => [
                'name' => $this->optionLabel($position),
                'data' => ['match' => $position->department_id ? 'department_id:' . $position->department_id : '*'],
            ]])->all();
    }

    // the list filters: [id => ['name', 'active']], all of them, the active ones first (the inactive ones marked)
    public function filterOptions($accountId): array
    {
        return $this->positionRepository->forAccount($accountId)->orderByDesc('is_active')->orderBy('name')->get()
            ->mapWithKeys(fn ($item) => [$item->id => ['name' => $item->name, 'active' => (bool) $item->is_active]])->all();
    }

    // 404 when it belongs to another account
    public function findOne($accountId, $id)
    {
        return $this->positionRepository->forAccount($accountId)->findOrFail($id);
    }

    public function create($accountId, array $data)
    {
        return $this->positionRepository->create($this->positionData($data) + ['account_id' => $accountId]);
    }

    public function update($accountId, $id, array $data)
    {
        $position = $this->findOne($accountId, $id);
        $position->update($this->positionData($data));
        return $position;
    }

    // the active switch of the list
    public function setActive($accountId, $id, bool $isActive)
    {
        $position = $this->findOne($accountId, $id);
        $position->update(['is_active' => $isActive]);
        return $position;
    }

    // soft delete, false when employees still use it
    public function deleteOne($accountId, $id)
    {
        $position = $this->findOne($accountId, $id);
        if ($position->employees_count > 0) {
            return false;
        }
        return $position->delete();
    }

    private function optionLabel($position): string
    {
        return $position->name;
    }

    private function positionData(array $data): array
    {
        return [
            'name' => $data['name'],
            'department_id' => (int) ($data['department_id'] ?? 0),
            'is_active' => !empty($data['is_active']),
        ];
    }
}
