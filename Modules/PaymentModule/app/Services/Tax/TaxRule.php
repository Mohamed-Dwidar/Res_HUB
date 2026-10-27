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

    // the rule stored on a row (its tax snapshot columns: tax_vat_rate, tax_vat_exempt, tax_table_rate, tax_inclusive,
    // tax_rounding); null when the row has none (created before the tax start date)
    public static function fromSnapshot(object $row): ?self
    {
        if ($row->tax_inclusive === null) {
            return null;
        }

        return new self((float) $row->tax_vat_rate, (bool) $row->tax_vat_exempt, (float) $row->tax_table_rate,
            (float) $row->tax_table_rate > 0, (bool) $row->tax_inclusive, $row->tax_rounding ?: 'vat');
    }

    // the snapshot columns of this rule (the tax TYPE)
    public function toColumns(): array
    {
        return [
            'tax_vat_rate' => $this->vatRate, 'tax_vat_exempt' => $this->vatExempt,
            'tax_table_rate' => $this->tableTax ? $this->tableTaxRate : 0, 'tax_inclusive' => $this->inclusive,
            'tax_rounding' => $this->rounding,
        ];
    }

    // e.g. "VAT 14% + table tax 1%, price includes tax" / "VAT exempt, price excludes tax"
    public function label(): string
    {
        $rate = fn ($value) => rtrim(rtrim(number_format($value, 2), '0'), '.') . '%';
        return ($this->vatExempt ? 'VAT exempt' : 'VAT ' . $rate($this->vatRate))
            . ($this->tableTax ? ' + table tax ' . $rate($this->tableTaxRate) : '')
            . ', price ' . ($this->inclusive ? 'includes' : 'excludes') . ' tax';
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
