<?php

namespace Modules\SpaceModule\app\Services;

use Illuminate\Support\Facades\DB;
use Modules\SpaceModule\app\Models\SubscriptionType;
use Modules\SpaceModule\app\Repositories\SubscriptionTypeRepository;
use Modules\UnitModule\app\Services\UnitService;

/**
 * Every method takes the owner account id, so an account can only reach its own subscription types.
 */
class SubscriptionTypeService
{
    private $subscriptionTypeRepository;
    private $unitService;

    public function __construct(SubscriptionTypeRepository $subscriptionTypeRepository, UnitService $unitService)
    {
        $this->subscriptionTypeRepository = $subscriptionTypeRepository;
        $this->unitService = $unitService;
    }

    // list query (paged / ordered by DataTables)
    public function listQuery($accountId, array $filters = [])
    {
        return $this->subscriptionTypeRepository->forAccount($accountId)->filter($filters);
    }

    // [id => name], for the spaces form / filter
    public function options($accountId): array
    {
        return $this->subscriptionTypeRepository->forAccount($accountId)->orderBy('name')->pluck('name', 'id')->all();
    }

    // for the reservation form: [id => ['name', 'data' => ['can-repeat' => 0|1, 'auto-renew' => 0|1, 'booking-mode' => hourly|full_day|period]]]
    // (booking-mode: custom.js keeps an hourly reservation's duration when its start moves, 2026-11-01)
    public function reservationOptions($accountId): array
    {
        return $this->subscriptionTypeRepository->forAccount($accountId)->orderBy('name')->get()
            ->mapWithKeys(fn ($type) => [$type->id => [
                'name' => $type->name,
                'data' => ['can-repeat' => (int) $type->can_repeat, 'auto-renew' => (int) $type->auto_renew, 'booking-mode' => (string) $type->booking_mode,
                    // the renewal increase fields of the reservation form (period types only, 2026-11-12)
                    'period' => (int) ($type->booking_mode === 'period')],
            ]])->all();
    }

    // 404 when the subscription type belongs to another account
    public function findOne($accountId, $id)
    {
        return $this->subscriptionTypeRepository->forAccount($accountId)->with(['spaces' => fn ($query) => $query->ordered()->latest('id')])->findOrFail($id);
    }

    public function create($accountId, array $data)
    {
        return $this->subscriptionTypeRepository->create($this->typeData($data) + ['account_id' => $accountId]);
    }

    public function update($accountId, $id, array $data)
    {
        $subscriptionType = $this->findOne($accountId, $id);
        $subscriptionType->update($this->typeData($data));
        return $subscriptionType;
    }

    // also removes it from the spaces it was assigned to and from their units
    public function deleteOne($accountId, $id)
    {
        $subscriptionType = $this->findOne($accountId, $id);

        return DB::transaction(function () use ($subscriptionType) {
            $subscriptionType->spaces()->detach();
            $this->unitService->clearSubscriptionType($subscriptionType->id);
            return $subscriptionType->delete();
        });
    }

    private function typeData(array $data): array
    {
        $type_data = ['name' => $data['name'], 'booking_mode' => $data['booking_mode'], 'home_order' => (int) ($data['home_order'] ?? 0)];
        foreach (array_keys(SubscriptionType::OPTIONS) as $option) {
            $type_data[$option] = !empty($data[$option]);
        }
        // a contract is a period type (one reservation per period)
        $type_data['is_contract'] = $type_data['is_contract'] && $data['booking_mode'] === 'period';
        return $type_data;
    }
}
