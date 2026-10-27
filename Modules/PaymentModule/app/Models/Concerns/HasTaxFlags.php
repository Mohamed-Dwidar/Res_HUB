<?php

namespace Modules\PaymentModule\app\Models\Concerns;

use Modules\AccountModule\app\Models\Account;

/**
 * The tax flags of a plan / income category (docs/payments-plan.md §3), set by the owner: vat_exempt (VAT standard / exempt),
 * table_tax (yes / no), price_includes_tax (yes / no). Read only when a row is saved after the tax start date (its snapshot keeps
 * them): changing them affects only new rows. Form: paymentmodule::Tax.partials.flags.
 */
trait HasTaxFlags
{
    const TAX_FLAGS = ['vat_exempt', 'table_tax', 'price_includes_tax'];

    public function initializeHasTaxFlags(): void
    {
        $this->mergeFillable(self::TAX_FLAGS);
        $this->mergeCasts(array_fill_keys(self::TAX_FLAGS, 'boolean'));
    }

    // the request rules (the fields are only on the form while VAT is on: absent = kept)
    public static function taxFlagRules(): array
    {
        return array_fill_keys(self::TAX_FLAGS, 'sometimes|boolean');
    }

    // the flags sent by the form, only those present (an absent one keeps its stored value)
    public static function taxFlagsFrom(array $data): array
    {
        return collect(self::TAX_FLAGS)->filter(fn ($flag) => array_key_exists($flag, $data))
            ->mapWithKeys(fn ($flag) => [$flag => !empty($data[$flag])])->all();
    }

    // e.g. "VAT 14% · Table tax 1% · Price includes tax" / "VAT exempt · Price excludes tax"
    public function taxSummary(Account $account): string
    {
        if (isset($this->is_revenue) && !$this->is_revenue) {
            return 'Never taxed (not counted as income)';
        }

        return collect([
            $this->vat_exempt ? 'VAT exempt' : 'VAT ' . self::taxRateText($account->vatRate()),
            $this->table_tax ? 'Table tax ' . self::taxRateText($account->tableTaxRate()) : 'No table tax',
            $this->price_includes_tax ? 'Price includes tax' : 'Price excludes tax',
        ])->implode(' · ');
    }

    public static function taxRateText(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 2), '0'), '.') . '%';
    }
}
