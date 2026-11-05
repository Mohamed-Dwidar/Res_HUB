<?php

namespace Modules\PayrollModule\app\Services;

use Modules\PayrollModule\app\Models\AdjustmentType;
use Modules\PayrollModule\app\Models\AllowanceType;
use Modules\PayrollModule\app\Models\PayrollSetting;
use Modules\PayrollModule\app\Repositories\AllowanceTypeRepository;

/**
 * The payroll setup (owner, HR sidebar): HR › Allowance Types and HR › Payroll Settings (insurance %, month days). H4 adds the
 * adjustment types here. Every method takes the owner account id.
 */
class PayrollSettingService
{
    public function __construct(private AllowanceTypeRepository $typeRepository)
    {
    }

    public function typesQuery($accountId)
    {
        return $this->typeRepository->forAccount($accountId);
    }

    public function findType($accountId, $id): AllowanceType
    {
        return $this->typeRepository->forAccount($accountId)->findOrFail($id);
    }

    public function saveType($accountId, array $data, $id = null): AllowanceType
    {
        $type = $id ? $this->findType($accountId, $id) : new AllowanceType(['account_id' => $accountId]);
        $type->fill([
            'name_en' => $data['name_en'] ?? null,
            'name_ar' => $data['name_ar'] ?? null,
            'is_insurable' => !empty($data['is_insurable']),
            'is_active' => !empty($data['is_active']),
        ])->save();
        return $type;
    }

    public function setTypeActive($accountId, $id, bool $active): AllowanceType
    {
        $type = $this->findType($accountId, $id);
        $type->update(['is_active' => $active]);
        return $type;
    }

    // soft delete; false while salary rows use it (switch it off instead)
    public function deleteType($accountId, $id): bool
    {
        $type = $this->findType($accountId, $id);
        return $type->items_count > 0 ? false : (bool) $type->delete();
    }

    // ------------------------------------------------------------------ adjustment types (H4)

    public function adjustmentTypesQuery($accountId)
    {
        return AdjustmentType::withCount('adjustments')->where('account_id', $accountId);
    }

    public function findAdjustmentType($accountId, $id): AdjustmentType
    {
        return $this->adjustmentTypesQuery($accountId)->findOrFail($id);
    }

    public function saveAdjustmentType($accountId, array $data, $id = null): AdjustmentType
    {
        $type = $id ? $this->findAdjustmentType($accountId, $id) : new AdjustmentType(['account_id' => $accountId]);
        $type->fill([
            'name_en' => $data['name_en'] ?? null,
            'name_ar' => $data['name_ar'] ?? null,
            'kind' => $data['kind'],
            'is_active' => !empty($data['is_active']),
        ])->save();
        return $type;
    }

    public function setAdjustmentTypeActive($accountId, $id, bool $active): AdjustmentType
    {
        $type = $this->findAdjustmentType($accountId, $id);
        $type->update(['is_active' => $active]);
        return $type;
    }

    // soft delete; false while bonuses / deductions use it (cancelled ones count too: switch it off instead)
    public function deleteAdjustmentType($accountId, $id): bool
    {
        $type = $this->findAdjustmentType($accountId, $id);
        return \Modules\PayrollModule\app\Models\EmployeeAdjustment::withTrashed()->where('adjustment_type_id', $type->id)->exists() ? false : (bool) $type->delete();
    }

    // ------------------------------------------------------------------ settings

    public function settings($accountId): PayrollSetting
    {
        return PayrollSetting::of($accountId);
    }

    public function saveSettings($accountId, array $data): PayrollSetting
    {
        $settings = PayrollSetting::firstOrNew(['account_id' => $accountId]);
        $settings->fill([
            'insurance_employee_percent' => $this->percent($data['insurance_employee_percent'] ?? null),
            'insurance_company_percent' => $this->percent($data['insurance_company_percent'] ?? null),
            'month_days' => (int) $data['month_days'],
            'advance_warn_percent' => round((float) $data['advance_warn_percent'], 2),
            'first_payroll_month' => !empty($data['first_payroll_month']) ? \Carbon\Carbon::parse($data['first_payroll_month'])->startOfMonth()->toDateString() : null,
        ])->save();
        return $settings;
    }

    private function percent($value): ?float
    {
        return $value === null || $value === '' ? null : round((float) $value, 2);
    }
}
