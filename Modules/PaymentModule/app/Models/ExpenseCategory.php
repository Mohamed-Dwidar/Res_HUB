<?php

namespace Modules\PaymentModule\app\Models;

use App\Helpers\LocalizedHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\AccountModule\app\Models\Account;

// the account's expense categories (Settings › Expense Categories; only the account manages them, the expense form selects one)
class ExpenseCategory extends Model
{
    use LocalizedHelper, SoftDeletes;

    // read in the active language: $model->name (see App\Helpers\LocalizedHelper)
    protected array $localizedFields = ['name'];

    protected $fillable = ['account_id', 'name_ar', 'name_en', 'is_active'];

    // generated_key (not fillable): NULL = a normal category; 'advances' / 'salaries' = the system's (company Cash Out only)
    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    // the categories order (lists, drop menus): by name
    // the categories a person may pick in the Cash Out form (not the system's)
    public function scopeManual($query)
    {
        return $query->whereNull('expense_categories.generated_key');
    }

    public function isGenerated(): bool
    {
        return $this->generated_key !== null;
    }

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
