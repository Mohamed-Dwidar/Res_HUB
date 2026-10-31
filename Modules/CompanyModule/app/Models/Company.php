<?php

namespace Modules\CompanyModule\app\Models;

use App\Helpers\LocalizedHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\AccountModule\app\Models\Account;

class Company extends Model
{
    use LocalizedHelper, SoftDeletes;

    // read in the active language: $model->name (see App\Helpers\LocalizedHelper)
    protected array $localizedFields = ['name'];

    protected $fillable = [
        'account_id', 'name_ar', 'name_en',
        // contracts (ContractModule, 2026-10-31): a company contract needs the commercial register + the representative's name
        'commercial_register', 'tax_number', 'address', 'representative_name', 'representative_title', 'representative_national_id',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function scopeFilter($query, array $filters = [])
    {
        if (!empty($filters['search'])) {
            $search = '%' . $filters['search'] . '%';
            $query->where(function ($query) use ($search) {
                $query->where('name_ar', 'like', $search)->orWhere('name_en', 'like', $search);
            });
        }

        return $query;
    }
}
