<?php

namespace Modules\PaymentModule\app\Services;

use Modules\PaymentModule\app\Models\ExpenseCategory;
use Modules\PaymentModule\app\Repositories\ExpenseCategoryRepository;

/**
 * Every method takes the owner account id, so an account can only reach its own expense categories.
 */
class ExpenseCategoryService
{
    private $categoryRepository;

    public function __construct(ExpenseCategoryRepository $categoryRepository)
    {
        $this->categoryRepository = $categoryRepository;
    }

    // list query (paged / ordered by DataTables)
    public function listQuery($accountId, array $filters = [])
    {
        return $this->categoryRepository->forAccount($accountId)->filter($filters);
    }

    // [id => name] for the expense form: the active ones + the expense's current one
    public function options($accountId, $currentId = null): array
    {
        return $this->categoryRepository->forAccount($accountId)
            ->where(fn ($query) => $query->where('is_active', true)->orWhere('id', (int) $currentId))
            ->manual()->ordered()->get()->pluck('name', 'id')->all();
    }

    /**
     * The system's category of a company Cash Out (docs/hr-plan.md §6b): $key = 'advances' | 'salaries'
     * (config paymentmodule.generated_categories), made on first use (active, names from the config); one per account.
     */
    public function generated($accountId, string $key): ExpenseCategory
    {
        $names = config('paymentmodule.generated_categories.' . $key);
        abort_unless($names, 500, 'Unknown system expense category: ' . $key);
        $category = ExpenseCategory::where('account_id', $accountId)->where('generated_key', $key)->first();
        if (!$category) {
            $category = new ExpenseCategory(['account_id' => $accountId, 'name_en' => $names['en'], 'name_ar' => $names['ar'], 'is_active' => true]);
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
        return $this->categoryRepository->create($this->categoryData($data) + ['account_id' => $accountId]);
    }

    public function update($accountId, $id, array $data)
    {
        $category = $this->findOne($accountId, $id);
        $category->update($this->categoryData($data));
        return $category;
    }

    // the active switch of the list
    public function setActive($accountId, $id, bool $isActive)
    {
        $category = $this->findOne($accountId, $id);
        $category->update(['is_active' => $isActive]);
        return $category;
    }

    // soft delete; the reason when it can not be deleted (used by expenses), null when deleted
    public function deleteOne($accountId, $id): ?string
    {
        $category = $this->findOne($accountId, $id);
        if ($category->expenses_count > 0) {
            return 'This expense category is used by cash out, deactivate it instead.';
        }
        $category->delete();
        return null;
    }

    private function categoryData(array $data): array
    {
        return [
            'name_ar' => $data['name_ar'],
            'name_en' => $data['name_en'] ?? null,
            'is_active' => !empty($data['is_active']),
        ];
    }
}
