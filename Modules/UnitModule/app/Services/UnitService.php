<?php

namespace Modules\UnitModule\app\Services;

use App\Helpers\UploaderHelper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Modules\ContractModule\app\Services\ContractService;
use Modules\MessagingModule\app\Services\MessagingService;
use Modules\SpaceModule\app\Models\Space;
use Modules\UnitModule\app\Models\Unit;
use Modules\UnitModule\app\Repositories\UnitRepository;

/**
 * Units always belong to a space of the logged in account.
 * Images are saved in public/uploads/units/{unit_id}/.
 */
class UnitService
{
    use UploaderHelper;

    private $unitRepository;

    public function __construct(UnitRepository $unitRepository)
    {
        $this->unitRepository = $unitRepository;
    }

    /**
     * The reservations list Unit filter (2026-10-22): [id => ['name', 'active', 'space_id', 'subscription_type_id']], by space
     * then unit order (custom.js shows the chosen space's units only), active first like the other filters.
     */
    public function filterOptions($accountId, bool $activeOnly = false): array
    {
        return Unit::where('units.account_id', $accountId)->when($activeOnly, fn ($query) => $query->where('units.is_active', true))
            ->join('spaces', 'spaces.id', '=', 'units.space_id')->whereNull('spaces.deleted_at')
            ->orderByDesc('units.is_active')->orderBy('spaces.sort_order')->orderBy('units.sort_order')->orderBy('units.name_en')
            ->get(['units.*'])
            ->mapWithKeys(fn ($unit) => [$unit->id => ['name' => $unit->name, 'active' => (bool) $unit->is_active,
                'space_id' => $unit->space_id, 'subscription_type_id' => $unit->subscription_type_id]])->all();
    }

    // units of the space list query (paged / ordered by DataTables)
    public function listQuery(Space $space)
    {
        return $this->unitRepository->forSpace($space->account_id, $space->id);
    }

    // 404 when the unit is not one of the account's units (any space)
    public function findForAccount($accountId, $id)
    {
        return Unit::where('account_id', $accountId)->findOrFail($id);
    }

    public function countForSpace(Space $space): int
    {
        return $this->unitRepository->forSpace($space->account_id, $space->id)->count();
    }

    // 404 when the unit is not in this space of this account
    public function findOne(Space $space, $id)
    {
        return $this->unitRepository->forSpace($space->account_id, $space->id)->findOrFail($id);
    }

    public function create(Space $space, array $data)
    {
        $unit = DB::transaction(function () use ($space, $data) {
            $unit = $this->unitRepository->create($this->unitData($data) + [
                'account_id' => $space->account_id,
                'space_id' => $space->id,
                // a new unit goes last in its space
                'sort_order' => (int) Unit::where('space_id', $space->id)->max('sort_order') + 1,
            ]);
            $unit->plans()->sync($data['plans'] ?? []);
            $this->syncMessages($unit, $data);
            $this->syncContractTemplate($unit, $data);
            return $unit;
        });

        $this->storeImages($unit, $data['images'] ?? []);
        return $unit;
    }

    /**
     * Drag and drop in the space page units list: put the unit before / after another unit of the space; the space's units are
     * numbered 1…n again in the new order (old rows all had 0). false = nothing to move (the same unit).
     */
    public function moveTo(Space $space, $id, $targetId, string $position): bool
    {
        $unit = $this->findOne($space, $id);
        $target = $this->findOne($space, $targetId);
        if ($unit->id === $target->id) {
            return false;
        }

        return DB::transaction(function () use ($space, $unit, $target, $position) {
            $ids = Unit::where('account_id', $space->account_id)->where('space_id', $space->id)->ordered()->lockForUpdate()->pluck('id')->all();
            $ids = array_values(array_diff($ids, [$unit->id]));
            $at = array_search($target->id, $ids) + ($position === 'after' ? 1 : 0);
            array_splice($ids, $at, 0, [$unit->id]);
            foreach ($ids as $place => $unitId) {
                Unit::whereKey($unitId)->toBase()->update(['sort_order' => $place + 1]);
            }
            return true;
        });
    }

    public function update(Space $space, $id, array $data)
    {
        $unit = $this->findOne($space, $id);
        DB::transaction(function () use ($unit, $data) {
            $unit->update($this->unitData($data));
            $unit->plans()->sync($data['plans'] ?? []);
            $this->syncMessages($unit, $data);
            $this->syncContractTemplate($unit, $data);
        });

        $this->deleteImages($unit, $data['delete_images'] ?? []);
        $this->storeImages($unit, $data['images'] ?? []);
        return $unit;
    }

    // the active switch of the list
    public function setActive(Space $space, $id, bool $isActive)
    {
        $unit = $this->findOne($space, $id);
        $unit->update(['is_active' => $isActive]);
        return $unit;
    }

    // soft delete: the images are kept so the unit can be restored
    public function deleteOne(Space $space, $id)
    {
        return $this->findOne($space, $id)->delete();
    }

    // when the space is deleted (soft delete)
    public function deleteForSpace(Space $space)
    {
        Unit::where('space_id', $space->id)->delete();
    }

    // units keep only subscription types that are still assigned to their space
    public function syncSpaceSubscriptionTypes($spaceId, array $subscriptionTypeIds)
    {
        Unit::withTrashed()->where('space_id', $spaceId)
            ->whereNotIn('subscription_type_id', array_merge([0], $subscriptionTypeIds))
            ->update(['subscription_type_id' => 0]);
    }

    // units keep only plans that are still assigned to their space
    public function syncSpacePlans($spaceId, array $planIds)
    {
        DB::table('plan_unit')
            ->whereIn('unit_id', Unit::withTrashed()->where('space_id', $spaceId)->select('id'))
            ->whereNotIn('plan_id', array_merge([0], $planIds))
            ->delete();
    }

    // when the subscription type is deleted
    public function clearSubscriptionType($subscriptionTypeId)
    {
        Unit::withTrashed()->where('subscription_type_id', $subscriptionTypeId)->update(['subscription_type_id' => 0]);
    }

    // MessagingModule (2026-10-31): the WhatsApp messages ticked in the unit form (only when the form sent the section)
    private function syncMessages(Unit $unit, array $data): void
    {
        if (!empty($data['message_templates_sent'])) {
            app(MessagingService::class)->syncUnitTemplates((int) $unit->account_id, (int) $unit->id, $data['message_templates'] ?? []);
        }
    }

    // ContractModule (2026-10-31): the contract template chosen in the unit form (only when the form sent the section)
    private function syncContractTemplate(Unit $unit, array $data): void
    {
        if (!empty($data['contract_template_sent'])) {
            app(ContractService::class)->syncUnitTemplate((int) $unit->account_id, (int) $unit->id, ($data['contract_template_id'] ?? null) ?: null);
        }
    }

    private function unitData(array $data): array
    {
        return [
            'name_ar' => $data['name_ar'],
            'name_en' => $data['name_en'],
            // the column is not nullable: 0 = none
            'subscription_type_id' => $data['subscription_type_id'] ?? 0,
            'color_id' => $data['color_id'],
            'description_ar' => $data['description_ar'] ?? null,
            'description_en' => $data['description_en'] ?? null,
            'notes_ar' => $data['notes_ar'] ?? null,
            'notes_en' => $data['notes_en'] ?? null,
            'capacity' => $data['capacity'],
            'concurrent_usage' => $data['concurrent_usage'],
            'lease_period' => $data['lease_period'] ?? null,
            'is_active' => !empty($data['is_active']),
        ];
    }

    private function storeImages(Unit $unit, array $files)
    {
        foreach ($files as $file) {
            $name = $this->uploadImage($file, 'units/' . $unit->id, 'unit', 1200, 1200);
            $unit->images()->create(['name' => $name]);
        }
    }

    // $ids: images of this unit to remove
    private function deleteImages(Unit $unit, array $ids)
    {
        foreach ($unit->images()->whereIn('id', $ids)->get() as $image) {
            File::delete(public_path('uploads/units/' . $unit->id . '/' . $image->name));
            $image->delete();
        }
    }
}
