<?php

namespace Modules\ReportModule\app\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\PackageModule\app\Models\Package;
use Modules\ReservationModule\app\Models\Reservation;

/**
 * Report 3 — Income (docs/reports-plan.md §3), cash basis: payments by paid_at (a refund on its OWN date) — cash in + withholding
 * − refunds; other income only of categories counted as income (`income_categories.is_revenue` = 1: no VAT (manual), no
 * Advance repayment). Grouped in SQL by ONE of: Space › Unit | Subscription type | Plan (package payments = one "Packages" row,
 * other income = a row per category) | Payment method | Month.
 * Tax = the VAT + table tax part of each amount, from the stored split of a taxed row: a reservation / package with an invoice →
 * amount × (vat + table_tax) / tax_total; an other income payment → its own vat + table_tax (Phase C; NULL today).
 * Net = Total − Tax (before tax). A type / space / unit / plan filter keeps reservation payments only.
 */
class IncomeReport
{
    const GROUPS = ['unit' => 'Space › Unit', 'type' => 'Subscription Type', 'plan' => 'Plan', 'method' => 'Payment Method', 'month' => 'Month'];

    public function filters(array $input): array
    {
        $date = fn ($value) => $value && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
        $method = (string) ($input['method'] ?? '');
        return [
            'group' => array_key_exists($input['group'] ?? '', self::GROUPS) ? $input['group'] : 'unit',
            'date_from' => $date($input['date_from'] ?? null),
            'date_to' => $date($input['date_to'] ?? null),
            'subscription_type_id' => (int) ($input['subscription_type_id'] ?? 0),
            'space_id' => (int) ($input['space_id'] ?? 0),
            'unit_id' => (int) ($input['unit_id'] ?? 0),
            'plan_id' => (int) ($input['plan_id'] ?? 0),
            'method' => $method === 'withholding' ? 'withholding' : (string) (int) $method,
        ];
    }

    // the days of the report: the picked days (the page starts on this month: ReportDates::thisMonth()); none = all dates
    public function range(array $filters): array
    {
        if ($filters['date_from']) {
            return [Carbon::parse($filters['date_from']), Carbon::parse($filters['date_to'] ?: $filters['date_from'])];
        }
        return [null, null];
    }

    private function placeFiltered(array $filters): bool
    {
        return $filters['subscription_type_id'] || $filters['space_id'] || $filters['unit_id'] || $filters['plan_id'];
    }

    /**
     * Every counted payment: group_key | group_sort | group_label | type | amount | tax_factor | own_tax
     * (plain SQL: payments + their reservation / package / category, soft deleted payments left out)
     */
    private function rows($accountId, array $filters)
    {
        $reservation = (new Reservation())->getMorphClass();
        $package = (new Package())->getMorphClass();
        [$from, $to] = $this->range($filters);
        $group = $filters['group'];

        $query = DB::table('payments as p')
            ->leftJoin('reservations as r', fn ($join) => $join->on('r.id', '=', 'p.payable_id')->where('p.payable_type', '=', $reservation))
            ->leftJoin('packages as k', fn ($join) => $join->on('k.id', '=', 'p.payable_id')->where('p.payable_type', '=', $package))
            ->leftJoin('income_categories as c', 'c.id', '=', 'p.income_category_id')
            ->where('p.account_id', $accountId)
            ->whereNull('p.deleted_at')
            // a reservation / package payment, or other income of a category counted as income
            ->where(fn ($query) => $query->whereIn('p.payable_type', [$reservation, $package])
                ->orWhere(fn ($query) => $query->whereNull('p.payable_type')->where('c.is_revenue', 1)))
            ->when($from, fn ($query) => $query->where('p.paid_at', '>=', $from->toDateString()))
            ->when($to, fn ($query) => $query->where('p.paid_at', '<=', $to->toDateString()));

        if ($this->placeFiltered($filters)) {
            $query->where('p.payable_type', $reservation);
            foreach (['subscription_type_id', 'space_id', 'unit_id', 'plan_id'] as $field) {
                if ($filters[$field]) {
                    $query->where('r.' . $field, $filters[$field]);
                }
            }
        }
        if ($filters['method'] === 'withholding') {
            $query->where('p.type', 'withholding');
        } elseif ($filters['method'] !== '0') {
            $query->where('p.payment_method_id', (int) $filters['method'])->where('p.type', '<>', 'withholding');
        }

        // the row a payment belongs to: place groups = the reservation's unit / type / plan, else "Packages" / the category
        $byPlace = [
            'unit' => ["CONCAT('u', r.unit_id)", "CONCAT(COALESCE(NULLIF(sp.name_en, ''), sp.name_ar, '-'), ' › ', COALESCE(NULLIF(un.name_en, ''), un.name_ar, '-'))"],
            'type' => ["CONCAT('t', r.subscription_type_id)", "COALESCE(st.name, '-')"],
            'plan' => ["CONCAT('p', r.plan_id)", "COALESCE(pl.name, '-')"],
        ];
        if (isset($byPlace[$group])) {
            [$key, $label] = $byPlace[$group];
            match ($group) {
                'unit' => $query->leftJoin('units as un', 'un.id', '=', 'r.unit_id')->leftJoin('spaces as sp', 'sp.id', '=', 'r.space_id'),
                'type' => $query->leftJoin('subscription_types as st', 'st.id', '=', 'r.subscription_type_id'),
                'plan' => $query->leftJoin('plans as pl', 'pl.id', '=', 'r.plan_id'),
            };
            $sortPlace = match ($group) {
                'unit' => "CONCAT('1-', LPAD(COALESCE(sp.sort_order, 0), 5, '0'), '-', LPAD(COALESCE(un.sort_order, 0), 5, '0'), '-', COALESCE(un.name_en, ''))",
                default => "CONCAT('1-', " . $label . ")",
            };
            $groupKey = "CASE WHEN r.id IS NOT NULL THEN {$key} WHEN k.id IS NOT NULL THEN 'packages' ELSE CONCAT('c', p.income_category_id) END";
            $groupLabel = "CASE WHEN r.id IS NOT NULL THEN {$label} WHEN k.id IS NOT NULL THEN 'Packages' ELSE CONCAT('Other income · ', COALESCE(NULLIF(c.name_en, ''), c.name_ar, '-')) END";
            $groupSort = "CASE WHEN r.id IS NOT NULL THEN {$sortPlace} WHEN k.id IS NOT NULL THEN '2' ELSE CONCAT('3-', COALESCE(c.name_en, '')) END";
        } elseif ($group === 'method') {
            $query->leftJoin('payment_methods as m', 'm.id', '=', 'p.payment_method_id');
            $groupKey = "IF(p.type = 'withholding', 'withholding', CONCAT('m', p.payment_method_id))";
            $groupLabel = "IF(p.type = 'withholding', 'Withholding (certificates)', COALESCE(NULLIF(m.name_en, ''), m.name_ar, 'No method'))";
            $groupSort = "IF(p.type = 'withholding', 'z', CONCAT(LPAD(COALESCE(m.sort_order, 0), 5, '0'), COALESCE(m.name_en, '')))";
        } else {
            $groupKey = "DATE_FORMAT(p.paid_at, '%Y-%m')";
            $groupLabel = "DATE_FORMAT(p.paid_at, '%Y-%m')";
            $groupSort = "DATE_FORMAT(p.paid_at, '%Y-%m')";
        }

        return $query->selectRaw("p.id as payment_id, p.paid_at, {$groupKey} as group_key, {$groupSort} as group_sort, {$groupLabel} as group_label, p.type, p.amount,
            CASE
                WHEN r.id IS NOT NULL AND r.invoice_requested = 1 AND r.tax_total > 0 THEN (COALESCE(r.vat, 0) + COALESCE(r.table_tax, 0)) / r.tax_total
                WHEN k.id IS NOT NULL AND k.invoice_requested = 1 AND k.tax_total > 0 THEN (COALESCE(k.vat, 0) + COALESCE(k.table_tax, 0)) / k.tax_total
                ELSE 0 END as tax_factor,
            CASE WHEN p.payable_type IS NULL THEN COALESCE(p.vat, 0) + COALESCE(p.table_tax, 0) ELSE 0 END as own_tax");
    }

    // the money columns of a set of rows (shared by the grouped rows and the totals)
    private const MONEY = "
        ROUND(SUM(IF(t.type = 'payment', t.amount, 0)), 2) as cash_in,
        ROUND(SUM(IF(t.type = 'refund', t.amount, 0)), 2) as refunds,
        ROUND(SUM(IF(t.type = 'withholding', t.amount, 0)), 2) as withholding,
        ROUND(SUM(IF(t.type = 'refund', -1, 1) * (t.amount * t.tax_factor + t.own_tax)), 2) as tax,
        ROUND(SUM(IF(t.type = 'refund', -t.amount, t.amount)), 2) as total,
        COUNT(*) as payments";

    // one row per group (DataTables / Excel): group_key, group_label, group_sort, cash_in, refunds, withholding, tax, net, total, payments
    public function query($accountId, array $filters)
    {
        $grouped = DB::query()->fromSub($this->rows($accountId, $filters), 't')
            ->groupBy('t.group_key')
            ->selectRaw('t.group_key, MAX(t.group_label) as group_label, MIN(t.group_sort) as group_sort,' . self::MONEY);

        return DB::query()->fromSub($grouped, 'g')->selectRaw('g.*, ROUND(g.total - g.tax, 2) as net');
    }

    const GROUP_LIMIT = 500;

    // one group's payments (the row popup): [label, payments (newest first, at most GROUP_LIMIT, member / payable / method loaded),
    // totals of the whole group, count]
    public function groupPayments($accountId, array $filters, string $key): array
    {
        $rows = DB::query()->fromSub($this->rows($accountId, $filters), 't')->where('t.group_key', $key);
        $totals = (clone $rows)->selectRaw(self::MONEY . ', MAX(t.group_label) as label')->first();
        $ids = (clone $rows)->orderByDesc('t.paid_at')->orderByDesc('t.payment_id')->limit(self::GROUP_LIMIT)->pluck('t.payment_id');
        $payments = \Modules\PaymentModule\app\Models\Payment::with(['member', 'payable', 'paymentMethod', 'incomeCategory'])
            ->whereIn('id', $ids)->orderByDesc('paid_at')->orderByDesc('id')->get()
            ->loadMorph('payable', [Reservation::class => ['space', 'unit.color', 'subscriptionType', 'plan']]);

        return ['label' => $totals->label, 'totals' => $totals, 'payments' => $payments, 'count' => (int) $totals->payments];
    }

    public function totals($accountId, array $filters): object
    {
        $totals = DB::query()->fromSub($this->rows($accountId, $filters), 't')->selectRaw(self::MONEY . ', COUNT(DISTINCT t.group_key) as groups_count')->first();
        foreach (['cash_in', 'refunds', 'withholding', 'tax', 'total'] as $field) {
            $totals->$field = (float) ($totals->$field ?? 0);
        }
        $totals->net = round($totals->total - $totals->tax, 2);

        return $totals;
    }

    // "01-11-2026 → 30-11-2026" / "All dates"
    public function rangeText(array $filters): string
    {
        [$from, $to] = $this->range($filters);
        $format = Reservation::DATE_FORMAT;
        if (!$from) {
            return 'All dates';
        }
        return $from->isSameDay($to) ? $from->format($format) : $from->format($format) . ' → ' . $to->format($format);
    }
}
