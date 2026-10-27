<?php

namespace Modules\PaymentModule\app\Services\Tax;

use Modules\AccountModule\app\Models\Account;

/**
 * The taxes of one line (docs/payments-plan.md §1): the account's rates (Settings › General › Taxes) + the flags of the plan /
 * income category (HasTaxFlags). Rates in percent (14.00). A tax snapshot keeps them, so a later change never alters it.
 */
final class TaxRule
{
    // how an inclusive price that no net can split exactly absorbs the cent (accountant, 2026-10-28: vat)
    const ROUNDINGS = [
        'vat' => 'VAT absorbs the cent',
        'net' => 'Net absorbs the cent',
        'extra_discount' => 'Extra discount (after tax)',
    ];

    public function __construct(
        public readonly float $vatRate,
        public readonly bool $vatExempt,
        public readonly float $tableTaxRate,
        public readonly bool $tableTax,
        public readonly bool $inclusive,
        public readonly string $rounding = 'vat',
    ) {
    }

    // the rule of a plan / income category ($source: vat_exempt, table_tax, price_includes_tax) with the account's rates;
    // a category not counted as income (VAT collected ...) is never taxed
    public static function for(Account $account, object $source): self
    {
        $untaxed = isset($source->is_revenue) && !$source->is_revenue;

        return new self(
            $account->vatRate(),
            $untaxed || (bool) $source->vat_exempt,
            $account->tableTaxRate(),
            !$untaxed && (bool) $source->table_tax,
            (bool) ($source->price_includes_tax ?? true),
            $account->taxRounding(),
        );
    }

    // the rates used, in hundredths of a percent (14.00% = 1400): the calculator works in whole numbers only
    public function vatBasisPoints(): int
    {
        return $this->vatExempt ? 0 : (int) round($this->vatRate * 100);
    }

    public function tableBasisPoints(): int
    {
        return $this->tableTax ? (int) round($this->tableTaxRate * 100) : 0;
    }
}
