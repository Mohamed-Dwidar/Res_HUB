<?php

namespace Modules\PaymentModule\app\Services;

use Modules\PaymentModule\app\Models\PaymentMethod;
use Modules\PaymentModule\app\Repositories\PaymentMethodRepository;

/**
 * Every method takes the owner account id, so an account can only reach its own payment methods.
 */
class PaymentMethodService
{
    private $methodRepository;

    public function __construct(PaymentMethodRepository $methodRepository)
    {
        $this->methodRepository = $methodRepository;
    }

    // list query (paged / ordered by DataTables)
    public function listQuery($accountId, array $filters = [])
    {
        return $this->methodRepository->forAccount($accountId)->filter($filters);
    }

    // [id => name] for the payment form: the active ones + the payment's current one
    public function options($accountId, $currentId = null): array
    {
        return $this->methodRepository->forAccount($accountId)
            ->where(fn ($query) => $query->where('is_active', true)->orWhere('id', (int) $currentId))
            ->ordered()->get()->pluck('name', 'id')->all();
    }

    // the names of the methods that need a reference no. (the forms' Reference hint)
    public function referenceNames($accountId): array
    {
        return PaymentMethod::where('account_id', $accountId)->where('requires_reference', true)->where('is_active', true)
            ->ordered()->get()->pluck('name')->all();
    }

    // the list filters: [id => ['name', 'active']], the active ones first (the account sees the inactive ones too, marked;
    // $activeOnly: the employees' lists)
    public function filterOptions($accountId, bool $activeOnly = false): array
    {
        return $this->methodRepository->forAccount($accountId)->when($activeOnly, fn ($query) => $query->where('is_active', true))
            ->orderByDesc('is_active')->ordered()->get()
            ->mapWithKeys(fn ($item) => [$item->id => ['name' => $item->name, 'active' => (bool) $item->is_active]])->all();
    }

    // 404 when it belongs to another account
    public function findOne($accountId, $id)
    {
        return $this->methodRepository->forAccount($accountId)->findOrFail($id);
    }

    public function create($accountId, array $data)
    {
        // no sort order: after the last one
        if (!isset($data['sort_order']) || $data['sort_order'] === '') {
            $data['sort_order'] = (int) $this->methodRepository->forAccount($accountId)->max('sort_order') + 1;
        }

        return $this->methodRepository->create($this->methodData($data) + ['account_id' => $accountId]);
    }

    public function update($accountId, $id, array $data)
    {
        $method = $this->findOne($accountId, $id);
        $method->update($this->methodData($data));
        return $method;
    }

    // the active switch of the list
    public function setActive($accountId, $id, bool $isActive)
    {
        $method = $this->findOne($accountId, $id);
        $method->update(['is_active' => $isActive]);
        return $method;
    }

    // soft delete; the reason when it can not be deleted (used by payments), null when deleted
    public function deleteOne($accountId, $id): ?string
    {
        $method = $this->findOne($accountId, $id);
        if ($method->payments_count > 0) {
            return 'This payment method is used by cash in, deactivate it instead.';
        }
        $method->delete();
        return null;
    }

    private function methodData(array $data): array
    {
        return [
            'name_ar' => $data['name_ar'],
            'name_en' => $data['name_en'] ?? null,
            'is_active' => !empty($data['is_active']),
            'requires_reference' => !empty($data['requires_reference']),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
    }
}
