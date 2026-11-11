<?php

namespace Modules\ReportModule\app\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\ReservationModule\app\Models\Reservation;

/**
 * Report 7 — Profit & Loss (simple, cash basis, monthly columns + Total):
 * Income = the Income report's Net (before tax) — the same rows (`IncomeReport::rows()`, grouped by subscription type; package
 * payments = "Packages", other income = a row per category counted as income).
 * Expenses = Cash Out by spent_at (soft deleted / voided left out): Salaries first (the system category `salaries`, shift + company),
 * then every other category (company Cash Out of another category = "Company · <category>").
 * Net profit = Income − Expenses.
 * Below the line (NOT in the profit): advances given (company Cash Out of the system category `advances`), repaid in cash (Cash In
 * of the system income category `advance_repayment`), deducted from salaries (employee_advance_moves `deduct`, by payroll month).
 */
class PnlReport
{
    const MAX_MONTHS = 36;

    public function filters(array $input): array
    {
        $date = fn ($value) => $value && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
        return ['date_from' => $date($input['date_from'] ?? null), 'date_to' => $date($input['date_to'] ?? null)];
    }

    // the page's quick ranges (no All: a P&L needs months)
    public static function presets(): array
    {
        $lastMonth = today()->subMonthNoOverflow();
        $lastYear = today()->subYear();
        return [
            ['label' => 'This month', 'from' => today()->startOfMonth()->toDateString(), 'to' => today()->endOfMonth()->toDateString()],
            ['label' => 'Last month', 'from' => $lastMonth->copy()->startOfMonth()->toDateString(), 'to' => $lastMonth->copy()->endOfMonth()->toDateString()],
            ['label' => 'This year', 'from' => today()->startOfYear()->toDateString(), 'to' => today()->endOfYear()->toDateString()],
            ['label' => 'Last year', 'from' => $lastYear->copy()->startOfYear()->toDateString(), 'to' => $lastYear->copy()->endOfYear()->toDateString()],
            ['label' => 'Last 12 months', 'from' => today()->subMonthsNoOverflow(11)->startOfMonth()->toDateString(), 'to' => today()->endOfMonth()->toDateString()],
        ];
    }

    public static function initial(): array
    {
        return [today()->startOfYear()->toDateString(), today()->endOfYear()->toDateString()];
    }

    // whole months: the first day of the from month → the last day of the to month (never past this month); none = this year
    public function range(array $filters): array
    {
        [$from, $to] = $filters['date_from'] ? [$filters['date_from'], $filters['date_to'] ?: $filters['date_from']] : self::initial();
        $from = Carbon::parse($from)->startOfMonth();
        $to = Carbon::parse($to)->endOfMonth()->startOfDay();
        $thisMonth = today()->endOfMonth()->startOfDay();
        if ($to->gt($thisMonth)) {
            $to = $from->gt($thisMonth) ? $from->copy()->endOfMonth()->startOfDay() : $thisMonth;
        }

        return [$from, $to];
    }

    public function rangeText(array $filters): string
    {
        [$from, $to] = $this->range($filters);
        return $from->format('M Y') . ($from->isSameMonth($to) ? '' : ' → ' . $to->format('M Y'));
    }

    // ['Y-m' => 'Nov 2026', ...]
    private function months(Carbon $from, Carbon $to): array
    {
        $months = [];
        for ($month = $from->copy(); $month->lte($to); $month->addMonthNoOverflow()) {
            $months[$month->format('Y-m')] = $month->format('M Y');
        }
        return $months;
    }

    /**
     * [error, months, income (rows), expenses (rows), totals (income / expenses / net / margin per month + total),
     *  below (given / repaid / deducted / change rows)] — a row = [key, label, months => [ym => amount], total]
     */
    public function data($accountId, array $filters): array
    {
        [$from, $to] = $this->range($filters);
        $months = $this->months($from, $to);
        if (count($months) > self::MAX_MONTHS) {
            return ['error' => 'Choose at most ' . self::MAX_MONTHS . ' months.', 'months' => $months];
        }
        $range = [$from->toDateString(), $to->toDateString()];

        // income: the Income report's rows, net before tax, by type + month
        $incomeReport = app(IncomeReport::class);
        $incomeRows = DB::query()->fromSub($incomeReport->rows($accountId, $incomeReport->filters(['group' => 'type',
                'date_from' => $range[0], 'date_to' => $range[1]])), 't')
            ->groupBy('t.group_key', 'ym')
            ->selectRaw("t.group_key as k, MAX(t.group_label) as label, MIN(t.group_sort) as sort, DATE_FORMAT(t.paid_at, '%Y-%m') as ym,
                ROUND(SUM(IF(t.type = 'refund', -t.amount, t.amount)) - SUM(IF(t.type = 'refund', -1, 1) * (t.amount * t.tax_factor + t.own_tax)), 2) as amount")
            ->get();

        // Cash Out by category + month (advances apart: below the line)
        $expenseRows = DB::table('expenses as e')
            ->leftJoin('expense_categories as c', 'c.id', '=', 'e.expense_category_id')
            ->where('e.account_id', $accountId)->whereNull('e.deleted_at')
            ->whereBetween('e.spent_at', $range)
            ->groupBy('k', 'ym')
            ->selectRaw("CASE WHEN c.generated_key IN ('salaries', 'advances') THEN c.generated_key
                    WHEN e.is_company = 1 THEN CONCAT('company-', e.expense_category_id) ELSE CONCAT('c', e.expense_category_id) END as k,
                MAX(CONCAT(IF(e.is_company = 1 AND COALESCE(c.generated_key, '') NOT IN ('salaries', 'advances'), 'Company · ', ''),
                    COALESCE(NULLIF(c.name_en, ''), c.name_ar, 'No category'))) as label,
                DATE_FORMAT(e.spent_at, '%Y-%m') as ym, ROUND(SUM(e.amount), 2) as amount")
            ->get();

        $repaid = DB::table('payments as p')->join('income_categories as c', 'c.id', '=', 'p.income_category_id')
            ->where('p.account_id', $accountId)->whereNull('p.deleted_at')->whereNull('p.payable_type')
            ->where('c.generated_key', 'advance_repayment')
            ->whereBetween('p.paid_at', $range)
            ->groupBy('ym')
            ->selectRaw("DATE_FORMAT(p.paid_at, '%Y-%m') as ym, ROUND(SUM(IF(p.type = 'refund', -p.amount, p.amount)), 2) as amount")
            ->pluck('amount', 'ym');

        $deducted = DB::table('employee_advance_moves')
            ->where('account_id', $accountId)->whereNull('deleted_at')->where('type', 'deduct')
            ->whereBetween('period', $range)
            ->groupBy('ym')
            ->selectRaw("DATE_FORMAT(period, '%Y-%m') as ym, ROUND(SUM(amount), 2) as amount")
            ->pluck('amount', 'ym');

        $income = $this->pivot($incomeRows, $months)->sortBy('sort')->values()->all();
        $expenses = $this->pivot($expenseRows->where('k', '!=', 'advances'), $months)
            ->sortBy(fn ($row) => ($row['key'] === 'salaries' ? '0' : (str_starts_with($row['key'], 'company-') ? '2' : '1')) . mb_strtolower($row['label']))
            ->values()->all();

        $sum = fn (array $rows) => $this->line(null, null, collect(array_keys($months))
            ->mapWithKeys(fn ($ym) => [$ym => round(collect($rows)->sum(fn ($row) => $row['months'][$ym]), 2)])->all());
        $totalIncome = $sum($income);
        $totalExpenses = $sum($expenses);
        $net = $this->line('net', 'Net profit', collect($totalIncome['months'])->map(fn ($amount, $ym) => round($amount - $totalExpenses['months'][$ym], 2))->all());
        $margin = collect($totalIncome['months'])->map(fn ($amount, $ym) => $amount > 0 ? round($net['months'][$ym] / $amount * 100, 1) : null)->all();
        $margin['total'] = $totalIncome['total'] > 0 ? round($net['total'] / $totalIncome['total'] * 100, 1) : null;

        $given = $this->line('given', 'Advances given', $this->fill($expenseRows->where('k', 'advances')->pluck('amount', 'ym'), $months));
        $repaidLine = $this->line('repaid', 'Repaid in cash', $this->fill($repaid, $months));
        $deductedLine = $this->line('deducted', 'Deducted from salaries', $this->fill($deducted, $months));
        $change = $this->line('change', 'Net advances outstanding (change)', collect($given['months'])
            ->map(fn ($amount, $ym) => round($amount - $repaidLine['months'][$ym] - $deductedLine['months'][$ym], 2))->all());

        return [
            'error' => null,
            'months' => $months,
            'income' => $income,
            'expenses' => $expenses,
            'totals' => ['income' => $totalIncome, 'expenses' => $totalExpenses, 'net' => $net, 'margin' => $margin],
            'below' => [$given, $repaidLine, $deductedLine, $change],
        ];
    }

    private function line($key, $label, array $months): array
    {
        return ['key' => $key, 'label' => $label, 'months' => $months, 'total' => round(array_sum($months), 2)];
    }

    // [ym => amount] → every month of the range (0 when missing)
    private function fill($values, array $months): array
    {
        return collect(array_keys($months))->mapWithKeys(fn ($ym) => [$ym => round((float) ($values[$ym] ?? 0), 2)])->all();
    }

    // rows of (k, label, [sort], ym, amount) → one line per k
    private function pivot($rows, array $months)
    {
        return collect($rows)->groupBy('k')->map(function ($group, $key) use ($months) {
            $line = $this->line((string) $key, $group->first()->label, $this->fill($group->pluck('amount', 'ym'), $months));
            return $line + ['sort' => $group->first()->sort ?? ''];
        });
    }

    // the dates the totals box shows: "01-01-2026 → 30-11-2026"
    public function datesText(array $filters): string
    {
        [$from, $to] = $this->range($filters);
        return $from->format(Reservation::DATE_FORMAT) . ' → ' . $to->copy()->endOfMonth()->format(Reservation::DATE_FORMAT);
    }
}
