<?php

namespace Modules\PayrollModule\app\Models;

use App\Helpers\LocalizedHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

// an account's allowance type (HR › Allowance Types, owner): transport, meals, phone …; is_insurable = in the insurable salary
class AllowanceType extends Model
{
    use LocalizedHelper, SoftDeletes;

    protected array $localizedFields = ['name'];

    protected $fillable = ['account_id', 'name_en', 'name_ar', 'is_insurable', 'is_active'];

    protected $casts = ['is_insurable' => 'boolean', 'is_active' => 'boolean'];

    // salary rows using it (items_count = the delete check)
    public function items(): HasMany
    {
        return $this->hasMany(EmployeeSalaryItem::class);
    }
}
