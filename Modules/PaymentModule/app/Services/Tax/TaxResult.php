<?php

namespace Modules\PaymentModule\app\Services\Tax;

/**
 * One line split by TaxCalculator, 2 decimals (the ETA fields): sales (the price before tax and discount), discount (before
 * tax), net = sales − discount, table tax, VAT, extra discount (after tax, rounding 'extra_discount' only), total = what is owed
 * = net + table tax + VAT − extra discount. adjusted = the rounding option was used (no net splits the inclusive price exactly).
 */
final class TaxResult
{
    public function __construct(
        public readonly float $sales,
        public readonly float $discount,
        public readonly float $net,
        public readonly float $tableTax,
        public readonly float $vat,
        public readonly float $extraDiscount,
        public readonly float $total,
        public readonly bool $adjusted,
        public readonly TaxRule $rule,
    ) {
    }

    // the snapshot stored on a reservation / package / extra (with the rates and flags used)
    public function toArray(): array
    {
        return [
            'sales' => $this->sales, 'discount' => $this->discount, 'net' => $this->net, 'table_tax' => $this->tableTax,
            'vat' => $this->vat, 'extra_discount' => $this->extraDiscount, 'total' => $this->total, 'adjusted' => $this->adjusted,
            'vat_rate' => $this->rule->vatExempt ? 0.0 : $this->rule->vatRate, 'vat_exempt' => $this->rule->vatExempt,
            'table_tax_rate' => $this->rule->tableTax ? $this->rule->tableTaxRate : 0.0, 'inclusive' => $this->rule->inclusive,
            'rounding' => $this->rule->rounding,
        ];
    }
}
