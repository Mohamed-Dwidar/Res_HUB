<?php

namespace Modules\PaymentModule\app\Models;

use App\Helpers\LocalizedHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\AccountModule\app\Models\Account;
use Modules\PaymentModule\app\Models\Concerns\HasTaxFlags;

// other income categories of an account (payments without a reservation / package; only the account manages it)
class IncomeCategory extends Model
{
    // + vat_exempt, table_tax, price_includes_tax: the taxes of its extras (docs/payments-plan.md §3)
    use HasTaxFlags, LocalizedHelper, SoftDeletes;

    // read in the active language: $model->name (see App\Helpers\LocalizedHelper)
    protected array $localizedFields = ['name'];

    protected $fillable = ['account_id', 'name_ar', 'name_en', 'is_active', 'is_revenue', 'default_price'];

    // generated_key (not fillable): NULL = a normal category; else a system one (config paymentmodule.generated_income_categories:
    // advance_repayment, vat_manual) — never income, not in the Cash In / Add Extra forms, its Cash In locked
    public function isGenerated(): bool
    {
        return $this->generated_key !== null;
    }

    protected $casts = [
        'is_active' => 'boolean',
        // false = not income: set by the rule (2026-11-08) = a system category (generated_key); the income reports leave it out
        'is_revenue' => 'boolean',
        // fills the Add Extra form (2026-11-08)
        'default_price' => 'float',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    // the categories order (lists, drop menus): by name
    public function scopeOrdered($query)
    {
        return $query->orderByLocalized('name')->orderBy('id');
    }

    public function scopeFilter($query, array $filters = [])
    {
        if (!empty($filters['search'])) {
            $search = '%' . $filters['search'] . '%';
            $query->where(fn ($query) => $query->where('name_ar', 'like', $search)->orWhere('name_en', 'like', $search));
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        return $query;
    }
}
