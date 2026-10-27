<?php

namespace Modules\PlanModule\app\Services;

use Modules\PlanModule\app\Models\Plan;
use Modules\PlanModule\app\Repositories\PlanRepository;

/**
 * Every method takes the owner account id, so an account can only reach its own plans.
 * The plans are assigned from the space form (plan_space) and the unit form (plan_unit), not here.
 */
class PlanService
{
    private $planRepository;

    public function __construct(PlanRepository $planRepository)
    {
        $this->planRepository = $planRepository;
    }

    // list query (paged / ordered by DataTables)
    public function listQuery($accountId, array $filters = [])
    {
        return $this->planRepository->forAccount($accountId)->filter($filters);
    }

    // [id => name], for the space form
    public function options($accountId): array
    {
        return $this->planRepository->forAccount($accountId)->orderBy('name')->pluck('name', 'id')->all();
    }

    // 404 when the plan belongs to another account
    public function findOne($accountId, $id)
    {
        return $this->planRepository->forAccount($accountId)->with([
            'spaces' => fn ($query) => $query->ordered(),
            'units' => fn ($query) => $query->with('space')->orderByLocalized('name'),
        ])->findOrFail($id);
    }

    public function create($accountId, array $data)
    {
        return $this->planRepository->create($this->planData($data) + ['account_id' => $accountId]);
    }

    public function update($accountId, $id, array $data)
    {
        $plan = $this->findOne($accountId, $id);
        $plan->update($this->planData($data));
        return $plan;
    }

    // the active switch of the list
    public function setActive($accountId, $id, bool $isActive)
    {
        $plan = $this->findOne($accountId, $id);
        $plan->update(['is_active' => $isActive]);
        return $plan;
    }

    // soft delete: the spaces / units links are kept (hidden while it is deleted) so it can be restored
    public function deleteOne($accountId, $id)
    {
        return $this->findOne($accountId, $id)->delete();
    }

    private function planData(array $data): array
    {
        return [
            'name' => $data['name'],
            'capacity' => $data['capacity'] ?? null,
            'lease_period' => $data['lease_period'] ?? null,
            'facilities' => $data['facilities'] ?? null,
            'amount' => $data['amount'],
            'is_time_based' => !empty($data['is_time_based']),
            'is_active' => !empty($data['is_active']),
        ] + Plan::taxFlagsFrom($data);
    }
}
