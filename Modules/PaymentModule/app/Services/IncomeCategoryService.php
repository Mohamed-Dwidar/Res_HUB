<?php

namespace Modules\PaymentModule\app\Services;

use Modules\PaymentModule\app\Models\IncomeCategory;
use Modules\PaymentModule\app\Repositories\IncomeCategoryRepository;

/**
 * Every method takes the owner account id, so an account can only reach its own income categories.
 */
class IncomeCategoryService
{
    private $categoryRepository;

    public function __construct(IncomeCategoryRepository $categoryRepository)
    {
        $this->categoryRepository = $categoryRepository;
    }

    // list query (paged / ordered by DataTables)
    public function listQuery($accountId, array $filters = [])
    {
        return $this->categoryRepository->forAccount($accountId)->filter($filters);
    }

    // [id => name] for the payment form: the active ones + the payment's current one
    public function options($accountId, $currentId = null): array
    {
        return $this->categoryRepository->forAccount($accountId)
            ->where(fn ($query) => $query->where('is_active', true)->orWhere('id', (int) $currentId))
            ->whereNull('generated_key')->ordered()->get()->pluck('name', 'id')->all();
    }

    /**
     * Add Extra (reservation / package, 2026-11-08): every active, non-system category + the system ones flagged `extra`
     * (Rounding, made here on first use) (+ the extra's current one), with its default price for the form
     * ([id => ['name', 'data' => ['price' => x]]], partials.field options).
     */
    public function extraOptions($accountId, $currentId = null): array
    {
        $keys = IncomeCategory::generatedKeysWith('extra');
        foreach ($keys as $key) {
            $this->generated($accountId, $key);
        }
        return $this->categoryRepository->forAccount($accountId)
            ->where(fn ($query) => $query->where('is_active', true)->orWhere('id', (int) $currentId))
            ->where(fn ($query) => $query->whereNull('generated_key')->orWhereIn('generated_key', $keys))->ordered()->get()
            ->mapWithKeys(fn ($item) => [$item->id => ['name' => $item->name, 'data' => ['price' => $item->default_price !== null ? number_format((float) $item->default_price, 2, '.', '') : '']]])->all();
    }

    /**
     * The system's income category (config paymentmodule.generated_income_categories): $key = 'advance_repayment' | 'vat_manual'
     * | 'rounding' — made on first use (active; income only with the `income` flag), one per account.
     */
    public function generated($accountId, string $key): IncomeCategory
    {
        $names = config('paymentmodule.generated_income_categories.' . $key);
        abort_unless($names, 500, 'Unknown system income category: ' . $key);
        $category = IncomeCategory::where('account_id', $accountId)->where('generated_key', $key)->first();
        if (!$category) {
            $category = new IncomeCategory(['account_id' => $accountId, 'name_en' => $names['en'], 'name_ar' => $names['ar'], 'is_active' => true, 'is_revenue' => !empty($names['income'])]);
            $category->generated_key = $key;
            $category->save();
        }
        return $category;
    }

    // the list filters: [id => ['name', 'active']], the active ones first (the account sees the inactive ones too, marked;
    // $activeOnly: the employees' lists)
    public function filterOptions($accountId, bool $activeOnly = false): array
    {
        return $this->categoryRepository->forAccount($accountId)->when($activeOnly, fn ($query) => $query->where('is_active', true))
            ->orderByDesc('is_active')->ordered()->get()
            ->mapWithKeys(fn ($item) => [$item->id => ['name' => $item->name, 'active' => (bool) $item->is_active]])->all();
    }

    // 404 when it belongs to another account
    public function findOne($accountId, $id)
    {
        return $this->categoryRepository->forAccount($accountId)->findOrFail($id);
    }

    public function create($accountId, array $data)
    {
        return $this->categoryRepository->create($this->categoryData($data, null) + ['account_id' => $accountId]);
    }

    public function update($accountId, $id, array $data)
    {
        $category = $this->findOne($accountId, $id);
        $category->update($this->categoryData($data, $category));
        return $category;
    }

    // the active switch of the list
    public function setActive($accountId, $id, bool $isActive)
    {
        $category = $this->findOne($accountId, $id);
        $category->update(['is_active' => $isActive]);
        return $category;
    }

    // soft delete; the reason when it can not be deleted (used by payments), null when deleted
    public function deleteOne($accountId, $id): ?string
    {
        $category = $this->findOne($accountId, $id);
        if ($category->isGenerated()) {
            return 'This is a system category: it can\'t be deleted.';
        }
        if ($category->payments_count > 0) {
            return 'This income category is used by cash in, deactivate it instead.';
        }
        $category->delete();
        return null;
    }

    // is_revenue by the rule (2026-11-08, no checkbox): a system category (generated_key) is not income unless flagged `income`
    // (Rounding), every other one is;
    // no tax flags any more (extras follow their parent's taxes; the columns keep their old values, unused)
    private function categoryData(array $data, ?IncomeCategory $category): array
    {
        $system = $category?->isGenerated() ?? false;
        return [
            'name_ar' => $data['name_ar'],
            'name_en' => $data['name_en'] ?? null,
            'is_active' => !empty($data['is_active']),
            'is_revenue' => !$system || $category->generatedFlag('income'),
            // fills Add Extra (2026-11-08; editable on each extra)
            'default_price' => ($data['default_price'] ?? '') !== '' ? round((float) $data['default_price'], 2) : null,
        ];
    }
}
