<?php

namespace Modules\PaymentModule\app\Models;

use App\Helpers\LocalizedHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\AccountModule\app\Models\Account;

// payment methods list of an account (cash, visa, instapay ...; only the account manages it)
class PaymentMethod extends Model
{
    use LocalizedHelper, SoftDeletes;

    // read in the active language: $model->name (see App\Helpers\LocalizedHelper)
    protected array $localizedFields = ['name'];

    // requires_reference (2026-10-27): every method but cash — a refund with it needs a unique "Reference no." (cash in: optional since 2026-11-01, cash out since 2026-11-03)
    protected $fillable = ['account_id', 'name_ar', 'name_en', 'is_active', 'requires_reference', 'sort_order'];

    protected $casts = [
        'is_active' => 'boolean',
        'requires_reference' => 'boolean',
        // a system method (2026-11-12: 'offset', OffsetService): never offered in the forms, not edited / deleted (not fillable)
        'generated_key' => 'string',
        'sort_order' => 'integer',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    // the methods order (lists, drop menus)
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('id');
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

    public function isGenerated(): bool
    {
        return $this->generated_key !== null;
    }
}
