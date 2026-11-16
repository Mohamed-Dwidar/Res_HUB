<?php

namespace Modules\PaymentModule\app\Models\Concerns;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;
use Modules\PaymentModule\app\Models\Extra;

/**
 * The extras of a payable (a package now): extras / adjustments added to what it owes (due = net + extrasTotal()).
 */
trait HasExtras
{
    public function extras(): MorphMany
    {
        return $this->morphMany(Extra::class, 'extrable');
    }

    // the extras block of its view (oldest first)
    public function extrasList(): Collection
    {
        return $this->extras()->with('incomeCategory')->orderBy('date')->orderBy('id')->get();
    }

    // Σ extras (signed), each with its taxes while the package's invoice is requested (tax_total, Phase C) else its amount;
    // lists / refreshAll() load it with the rows (scopeWithExtrasDue(): extras_sum_amount)
    public function extrasTotal(): float
    {
        if (array_key_exists('extras_sum_amount', $this->attributes)) {
            return round((float) $this->extras_sum_amount, 2);
        }
        return $this->exists ? round((float) $this->extras()->sum(DB::raw('COALESCE(tax_total, amount)')), 2) : 0;
    }

    // + extras_sum_amount = Σ COALESCE(tax_total, amount) of its extras (what extrasTotal() returns), in the same query
    public function scopeWithExtrasDue($query)
    {
        return $query->withSum('extras as extras_sum_amount', DB::raw('COALESCE(extras.tax_total, extras.amount)'));
    }
}
