<?php

namespace Modules\PaymentModule\app\Services\Tax;

use Modules\AccountModule\app\Models\Account;
use Modules\PackageModule\app\Models\Package;
use Modules\ReservationModule\app\Models\ReservationStatus;

/**
 * The taxes of a package (Phase C, docs/payments-plan.md §3), filled by Package::recalculate() before it is saved:
 * - the package's "Invoice requested" (ONE switch; its reservations follow it, they keep their own tax TYPE but no amounts);
 * - shown / used only when taxes apply to the package (Settings › General: VAT on + start date, the package made on / after it)
 *   or it already has a tax total (a saved one never loses it);
 * - invoice requested: its counted reservations' net amounts grouped by their stored tax type (VAT rate / exempt × table tax ×
 *   inclusive × rounding; reservations without a type — made before the start date — = an untaxed group), the package
 *   discount % applied on each group BEFORE tax, TaxCalculator per group; tax_groups (json) + the totals (tax_net, table_tax,
 *   vat, tax_extra_discount, tax_total = what the reservations part owes); else all NULL (owed = after_discount as before).
 * - mainRule(): the tax type of the biggest group = the package's extras (sales) rule (user 2026-11-16); no taxed group = the
 *   account's VAT rate, no table tax, price excludes tax. Adjustments / untaxed categories (Rounding) are never taxed.
 */
class PackageTax
{
    const AMOUNTS = ['tax_net', 'table_tax', 'vat', 'tax_extra_discount', 'tax_total'];

    public function __construct(private TaxCalculator $calculator)
    {
    }

    // does this package get taxes (the switch is shown)?
    public static function applies(?Account $account, Package $package): bool
    {
        return $package->tax_total !== null || ($account && $account->taxesApplyTo($package->created_at ?? now()));
    }

    public function fill(Package $package, ?Account $account): void
    {
        if (!self::applies($account, $package)) {
            $package->forceFill(['invoice_requested' => false]);
        }
        if (!$package->invoice_requested) {
            $package->forceFill(array_fill_keys(self::AMOUNTS, null) + ['tax_groups' => null]);
            return;
        }

        $groups = [];
        $source = $this->reservationGroups($package);
        $shares = $this->fixedShares($source, (float) $package->discount_percentage, (float) $package->discount_fixed);
        foreach ($source as $i => $group) {
            $groups[] = $this->groupResult($group['rule'], $group['amount'], $group['count'], (float) $package->discount_percentage, $shares[$i]);
        }
        // the biggest first (mainRule() reads the first taxed one)
        usort($groups, fn ($a, $b) => abs($b['net']) <=> abs($a['net']));

        $sum = fn ($key) => round(array_sum(array_column($groups, $key)), 2);
        $package->forceFill([
            'tax_groups' => $groups,
            'tax_net' => $sum('net'), 'table_tax' => $sum('table_tax'), 'vat' => $sum('vat'),
            'tax_extra_discount' => $sum('extra_discount'), 'tax_total' => $sum('total'),
        ]);
    }

    /**
     * The Edit Package form (nothing saved): what it would owe with / without an invoice at its CURRENT discount
     * ['with' => due incl. taxes, 'without' => after_discount + extras, 'groups' => the split], the extras included.
     */
    public function preview(Package $package, ?Account $account): array
    {
        $extras = app(\Modules\PaymentModule\app\Services\ExtraService::class);
        $copy = clone $package;
        $copy->invoice_requested = true;
        $copy->tax_total = $copy->tax_total ?? 0.0;
        $this->fill($copy, $account);

        return [
            'with' => round((float) $copy->tax_total + $extras->totalWith($package, $this->mainRule($copy, $account)), 2),
            'without' => round((float) $package->after_discount + $extras->totalWith($package, null), 2),
            'net' => (float) $copy->tax_net, 'vat' => (float) $copy->vat, 'table_tax' => (float) $copy->table_tax,
            'groups' => (array) $copy->tax_groups,
        ];
    }

    // the tax type of the package's extras (sales): its biggest taxed group, else the account's VAT, excluding tax; null = none
    public function mainRule(Package $package, ?Account $account): ?TaxRule
    {
        if (!$package->invoice_requested || $package->tax_total === null) {
            return null;
        }
        foreach ((array) $package->tax_groups as $group) {
            if (empty($group['untaxed'])) {
                return new TaxRule((float) $group['vat_rate'], (bool) $group['vat_exempt'], (float) $group['table_tax_rate'],
                    (float) $group['table_tax_rate'] > 0, (bool) $group['inclusive'], $group['rounding'] ?: 'vat');
            }
        }
        $account ??= Account::settingsOf($package->account_id);

        return $account ? new TaxRule($account->vatRate(), false, $account->tableTaxRate(), false, false, $account->taxRounding()) : null;
    }

    // [['rule' => ?TaxRule, 'amount' => Σ net_amount, 'count' => n]] of its counted reservations, one per stored tax type
    private function reservationGroups(Package $package): array
    {
        $rows = $package->reservations()->whereNotIn('reservation_status_id', ReservationStatus::notCountedIds())
            ->get(['id', 'net_amount', 'tax_vat_rate', 'tax_vat_exempt', 'tax_table_rate', 'tax_inclusive', 'tax_rounding']);

        $groups = [];
        foreach ($rows as $row) {
            $rule = TaxRule::fromSnapshot($row);
            $key = $rule ? json_encode($rule->toColumns()) : 'untaxed';
            $groups[$key] ??= ['rule' => $rule, 'amount' => 0.0, 'count' => 0];
            $groups[$key]['amount'] += (float) $row->net_amount;
            $groups[$key]['count']++;
        }

        return array_values($groups);
    }

    /**
     * The package's fixed discount (2026-11-17) shared across the groups by their amount after the %, in cents, the rest to the
     * biggest; never more than what is left (Package::calculate()). [group index => share]
     */
    private function fixedShares(array $groups, float $percentage, float $fixed): array
    {
        $after = array_map(fn ($group) => round(round($group['amount'], 2) * (100 - $percentage) / 100, 2), $groups);
        $total = array_sum($after);
        $fixed = min(max(0, $fixed), $total);
        $shares = array_fill(0, count($groups), 0.0);
        if ($fixed <= 0 || $total <= 0) {
            return $shares;
        }
        $cents = (int) round($fixed * 100);
        $given = 0;
        foreach ($after as $i => $amount) {
            $shares[$i] = (int) floor($cents * $amount / $total);
            $given += $shares[$i];
        }
        $biggest = array_keys($after, max($after))[0];
        $shares[$biggest] += $cents - $given;

        return array_map(fn ($share) => $share / 100, $shares);
    }

    // one group: the discount % then its share of the fixed discount, before tax, then its taxes (untaxed: the discounted amount)
    private function groupResult(?TaxRule $rule, float $amount, int $count, float $percentage, float $fixedShare = 0): array
    {
        $amount = round($amount, 2);
        $discount = round($amount - round($amount * (100 - $percentage) / 100, 2) + $fixedShare, 2);
        if (!$rule) {
            $net = round($amount - $discount, 2);
            return ['untaxed' => true, 'count' => $count, 'sales' => $amount, 'discount' => $discount, 'net' => $net,
                'table_tax' => 0.0, 'vat' => 0.0, 'extra_discount' => 0.0, 'total' => $net, 'label' => 'No tax (made before taxes started)'];
        }

        return ['untaxed' => false, 'count' => $count, 'label' => $rule->label()]
            + $this->calculator->calculate($amount, $discount, $rule)->toArray();
    }
}
