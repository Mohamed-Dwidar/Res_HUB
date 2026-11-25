<?php

namespace Modules\ContractModule\app\Models;

use App\Helpers\SafeMorphHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\UnitModule\app\Models\Unit;

/**
 * A contract template of an account: name + printed title + body (sanitized HTML) in Arabic (+ optional English) + the renewal
 * annex texts + default term (months, NULL = the reservation's own period) + on / off, used for the units it is assigned to
 * (contract_template_unit: one template per unit).
 */
class ContractTemplate extends Model
{
    use SafeMorphHelper, SoftDeletes;

    protected $fillable = [
        'account_id', 'name', 'kind', 'title_ar', 'title_en', 'body_ar', 'body_en', 'renewal_body_ar', 'renewal_body_en',
        'default_term_months', 'is_enabled',
    ];

    // the template type (2026-11-25): a contract, or the renewal annex printed for a renewed term; a unit has one of each
    const KINDS = ['contract' => 'Contract', 'renewal' => 'Renewal annex'];

    protected $casts = [
        'is_enabled' => 'boolean',
        'default_term_months' => 'integer',
    ];

    // the units its contracts are for (deleted units left out)
    public function units(): BelongsToMany
    {
        return $this->belongsToMany(Unit::class, 'contract_template_unit', 'contract_template_id', 'unit_id');
    }

    public function creatable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isRenewalAnnex(): bool
    {
        return $this->kind === 'renewal';
    }

    public function getKindLabelAttribute(): string
    {
        return self::KINDS[$this->kind] ?? self::KINDS['contract'];
    }

    // "1 year" / "3 months" / "The reservation's period"
    public function getTermLabelAttribute(): string
    {
        return self::termLabel($this->default_term_months);
    }

    public static function termLabel(?int $months): string
    {
        if ($months && $months % 12 === 0) {
            return $months / 12 . ' ' . ($months === 12 ? 'year' : 'years');
        }
        return $months ? $months . ' ' . ($months === 1 ? 'month' : 'months') : 'The reservation\'s period';
    }
}
