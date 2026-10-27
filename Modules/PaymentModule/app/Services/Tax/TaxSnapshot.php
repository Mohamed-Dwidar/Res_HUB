<?php

namespace Modules\PaymentModule\app\Services\Tax;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\AccountModule\app\Models\Account;

/**
 * The tax snapshot of a row (docs/payments-plan.md §3), filled before it is saved:
 * - no snapshot (all NULL, invoice_requested 0) when taxes don't apply: created before the tax start date / VAT off — unless
 *   the row already has a tax type (saved ones never lose it);
 * - the tax TYPE (tax_vat_rate, tax_vat_exempt, tax_table_rate, tax_inclusive, tax_rounding) on every save from the start date,
 *   even without an invoice: from the plan / category's CURRENT flags when $freshType (new row, plan changed), else the stored one;
 * - the amounts (tax_net, table_tax, vat, tax_extra_discount, tax_total) only when invoice_requested and $withAmounts (a
 *   reservation inside a package: the package's taxes, Phase C), else NULL.
 */
class TaxSnapshot
{
    const TYPE = ['tax_vat_rate', 'tax_vat_exempt', 'tax_table_rate', 'tax_inclusive', 'tax_rounding'];
    const AMOUNTS = ['tax_net', 'table_tax', 'vat', 'tax_extra_discount', 'tax_total'];

    public function __construct(private TaxCalculator $calculator)
    {
    }

    // does this row get taxes (the toggle is shown)? $createdAt null = a new row (now)
    public static function applies(Account $account, Model $row): bool
    {
        return $row->tax_inclusive !== null || $account->taxesApplyTo($row->created_at ?? Carbon::now());
    }

    public function fill(Model $row, Account $account, object $source, bool $freshType, float $price, float $discount, bool $withAmounts = true): ?TaxResult
    {
        if (!self::applies($account, $row)) {
            $row->forceFill(array_fill_keys(array_merge(self::TYPE, self::AMOUNTS), null) + ['invoice_requested' => false]);
            return null;
        }

        $rule = $freshType ? null : TaxRule::fromSnapshot($row);
        if (!$rule) {
            $rule = TaxRule::for($account, $source);
            $row->forceFill($rule->toColumns());
        }

        if (!$row->invoice_requested || !$withAmounts) {
            $row->forceFill(array_fill_keys(self::AMOUNTS, null));
            return null;
        }

        $result = $this->calculator->calculate($price, $discount, $rule);
        $row->forceFill([
            'tax_net' => $result->net, 'table_tax' => $result->tableTax, 'vat' => $result->vat,
            'tax_extra_discount' => $result->extraDiscount, 'tax_total' => $result->total,
        ]);

        return $result;
    }
}
