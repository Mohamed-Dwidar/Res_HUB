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
 * Columns (2026-11-12): month | quarter | half | year (the page's "Columns" select) or day (the dashboard's chart of a short period);
 * `exact` = the days as given (the dashboard: the same days as the Income / Expenses reports), else whole months.
 * The running column (the business date inside it) = "(to date)"; each column's compared amount (the page's Compare: same period
 * last year (default) / previous period = previous column / a period I pick / none, a running column on the same days) = columnCompare().
 * compare() adds a comparison period's totals to each line (rows found only there come in with zeros).
 */
class PnlReport
{
    const MAX_MONTHS = 120;

    const MAX_COLUMNS = 36;

    const COLUMNS = ['month' => 'Months', 'quarter' => 'Quarters', 'half' => 'Half-years', 'year' => 'Years'];

    // months per column
    const SPAN = ['month' => 1, 'quarter' => 3, 'half' => 6, 'year' => 12];

    public function filters(array $input): array
    {
        $date = fn ($value) => $value && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
        $columns = (string) ($input['columns'] ?? '');
        return [
            'date_from' => $date($input['date_from'] ?? null),
            'date_to' => $date($input['date_to'] ?? null),
            'columns' => array_key_exists($columns, self::COLUMNS) || $columns === 'day' ? $columns : 'month',
            'exact' => !empty($input['exact']),
            // what each column is compared with = the page's Compare (ReportPeriod): last_year | previous | custom (cmp_from / cmp_to) | none
            'col_change' => in_array($input['col_change'] ?? '', ['last_year', 'previous', 'custom'], true) ? $input['col_change'] : 'none',
            'cmp_from' => $date($input['cmp_from'] ?? null),
            'cmp_to' => $date($input['cmp_to'] ?? null),
        ];
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
            ['label' => 'Until today', 'from' => ReportDates::earliest(), 'to' => today()->toDateString()],
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
        if (!empty($filters['exact'])) {
            return [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->startOfDay()];
        }
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

    // the column a SQL period ('Y-m', or 'Y-m-d' for day columns) belongs to: [key, label]
    private function bucket(string $period, string $columns): array
    {
        $date = Carbon::parse(strlen($period) === 7 ? $period . '-01' : $period);
        return match ($columns) {
            'day' => [$period, $date->format('d M')],
            'quarter' => [$date->year . '-Q' . $date->quarter, 'Q' . $date->quarter . ' ' . $date->year],
            'half' => [$date->year . '-H' . ($date->month <= 6 ? 1 : 2), 'H' . ($date->month <= 6 ? 1 : 2) . ' ' . $date->year],
            'year' => [(string) $date->year, (string) $date->year],
            default => [$date->format('Y-m'), $date->format('M Y')],
        };
    }

    // [key => label] of the columns of the range + [SQL period => column key]
    private function columns(Carbon $from, Carbon $to, string $columns): array
    {
        $list = [];
        $map = [];
        $step = $columns === 'day' ? 'addDay' : 'addMonthNoOverflow';
        for ($period = $columns === 'day' ? $from->copy() : $from->copy()->startOfMonth(); $period->lte($to); $period->$step()) {
            $raw = $period->format($columns === 'day' ? 'Y-m-d' : 'Y-m');
            [$key, $label] = $this->bucket($raw, $columns);
            $list[$key] = $label;
            $map[$raw] = $key;
        }
        return [$list, $map];
    }

    /**
     * [error, months, income (rows), expenses (rows), totals (income / expenses / net / margin per month + total),
     *  below (given / repaid / deducted / change rows)] — a row = [key, label, months => [ym => amount], total]
     */
    public function data($accountId, array $filters): array
    {
        [$from, $to] = $this->range($filters);
        $columns = $filters['columns'] ?? 'month';
        if ($columns === 'day' && $from->diffInDays($to) > 62) {
            $columns = 'month';
        }
        if ($columns !== 'day' && $from->copy()->startOfMonth()->diffInMonths($to) >= self::MAX_MONTHS) {
            return ['error' => 'Choose at most ' . self::MAX_MONTHS . ' months.', 'months' => []];
        }
        [$months, $this->map] = $this->columns($from, $to, $columns);
        if (count($months) > self::MAX_COLUMNS) {
            return ['error' => 'At most ' . self::MAX_COLUMNS . ' columns: choose fewer months or bigger columns (quarters, half-years, years).', 'months' => []];
        }
        $range = [$from->toDateString(), $to->toDateString()];
        $fmt = $columns === 'day' ? '%Y-%m-%d' : '%Y-%m';

        // income: the Income report's rows, net before tax, by type + month
        $incomeReport = app(IncomeReport::class);
        $incomeRows = DB::query()->fromSub($incomeReport->rows($accountId, $incomeReport->filters(['group' => 'type',
                'date_from' => $range[0], 'date_to' => $range[1]])), 't')
            ->groupBy('t.group_key', 'ym')
            ->selectRaw("t.group_key as k, MAX(t.group_label) as label, MIN(t.group_sort) as sort, DATE_FORMAT(t.paid_at, '{$fmt}') as ym,
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
                DATE_FORMAT(e.spent_at, '{$fmt}') as ym, ROUND(SUM(e.amount), 2) as amount")
            ->get();

        $repaid = DB::table('payments as p')->join('income_categories as c', 'c.id', '=', 'p.income_category_id')
            ->where('p.account_id', $accountId)->whereNull('p.deleted_at')->whereNull('p.payable_type')
            ->where('c.generated_key', 'advance_repayment')
            ->whereBetween('p.paid_at', $range)
            ->groupBy('ym')
            ->selectRaw("DATE_FORMAT(p.paid_at, '{$fmt}') as ym, ROUND(SUM(IF(p.type = 'refund', -p.amount, p.amount)), 2) as amount")
            ->pluck('amount', 'ym');

        $deducted = DB::table('employee_advance_moves')
            ->where('account_id', $accountId)->whereNull('deleted_at')->where('type', 'deduct')
            ->whereBetween('period', $range)
            ->groupBy('ym')
            ->selectRaw("DATE_FORMAT(period, '{$fmt}') as ym, ROUND(SUM(amount), 2) as amount")
            ->pluck('amount', 'ym');

        $income = $this->pivot($incomeRows, $months)->sortBy('sort')->values()->all();
        $expenses = $this->sortExpenses($this->pivot($expenseRows->where('k', '!=', 'advances'), $months));

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

        $data = [
            'error' => null,
            'columns' => $columns,
            'months' => $months,
            'income' => $income,
            'expenses' => $expenses,
            'totals' => ['income' => $totalIncome, 'expenses' => $totalExpenses, 'net' => $net, 'margin' => $margin],
            'below' => [$given, $repaidLine, $deductedLine, $change],
            'running' => null,
            'col_compare' => null,
        ];

        if (!empty($filters['exact']) || $columns === 'day') {
            return $data;
        }
        $data = $this->running($data, $columns);
        return $filters['col_change'] === 'none' || ($filters['col_change'] === 'custom' && !$filters['cmp_from']) ? $data
            : $this->columnCompare($accountId, $data, $from, $to, $columns, $filters);
    }

    // Salaries first, then the shift categories, then company Cash Out of other categories; by name
    private function sortExpenses($rows): array
    {
        return collect($rows)->sortBy(fn ($row) => ($row['key'] === 'salaries' ? '0' : (str_starts_with($row['key'], 'company-') ? '2' : '1')) . mb_strtolower($row['label']))
            ->values()->all();
    }

    // a column's own first / last day (the period it names: month, quarter, half-year, year)
    private function columnBounds(string $key, string $columns): array
    {
        $from = match ($columns) {
            'quarter' => Carbon::create((int) substr($key, 0, 4), ((int) substr($key, -1) - 1) * 3 + 1, 1),
            'half' => Carbon::create((int) substr($key, 0, 4), (int) substr($key, -1) === 1 ? 1 : 7, 1),
            'year' => Carbon::create((int) $key, 1, 1),
            default => Carbon::parse($key . '-01'),
        };
        $months = ['quarter' => 3, 'half' => 6, 'year' => 12][$columns] ?? 1;
        return [$from, $from->copy()->addMonthsNoOverflow($months - 1)->endOfMonth()->startOfDay()];
    }

    /**
     * The column still running on the business date (the last one) is marked "to date" (its label + $data['running'] = [key, to]);
     * columnCompare() compares it on the same days only.
     */
    private function running(array $data, string $columns): array
    {
        [$today] = Reservation::businessToday();
        $today = $today->copy()->startOfDay();
        $key = $this->bucket($today->format('Y-m-d'), $columns)[0];
        // year keys come back from array_keys() as ints
        if (!in_array($key, array_map('strval', array_keys($data['months'])), true)) {
            return $data;
        }
        [, $end] = $this->columnBounds($key, $columns);
        if ($today->gte($end)) {
            return $data;
        }
        $data['months'][$key] .= ' (to date)';
        $data['running'] = ['key' => $key, 'to' => $today->format(Reservation::DATE_FORMAT), 'date' => $today];

        return $data;
    }

    /**
     * Each column's compared amount (the page's Compare): last_year = the same column a year earlier, previous = the column
     * before it (the first column: the one before the range) — ONE more read of the range shifted back (12 months / one column);
     * custom = the picked days, column n vs its n-th column.
     * A running column is compared on the same days (last year: up to the same date; previous: the previous column's start + the
     * same number of days). Every line gets cmp_months [key => amount] + cmp_total; margin cmp_months / cmp_total;
     * $data['col_compare'] = [mode, labels [key => compared column's label], range (the compared days)]. Lines found only in the
     * compared range come in with zero columns.
     */
    private function columnCompare($accountId, array $data, Carbon $from, Carbon $to, string $columns, array $filters): array
    {
        $mode = $filters['col_change'];
        $shift = $mode === 'last_year' ? 12 : self::SPAN[$columns];
        $cmpFrom = $from->copy()->subMonthsNoOverflow($shift);
        $cmpTo = $to->copy()->subMonthsNoOverflow($shift)->endOfMonth()->startOfDay();
        // a period I pick: its own days, its columns matched by position (never cut)
        $running = $mode === 'custom' ? null : $data['running'];
        if ($mode === 'custom') {
            [$cmpFrom, $cmpTo] = [Carbon::parse($filters['cmp_from']), Carbon::parse($filters['cmp_to'] ?: $filters['cmp_from'])];
            if ($cmpTo->lt($cmpFrom)) {
                [$cmpFrom, $cmpTo] = [$cmpTo, $cmpFrom];
            }
        } elseif ($running) {
            if ($mode === 'last_year') {
                $cmpTo = $running['date']->copy()->subYearNoOverflow();
            } else {
                [$start] = $this->columnBounds($running['key'], $columns);
                $start = max($start, $from);
                $prevStart = $start->copy()->subMonthsNoOverflow($shift);
                [, $prevEnd] = $this->columnBounds($this->bucket($prevStart->format('Y-m-d'), $columns)[0], $columns);
                $cmpTo = min($prevEnd, $prevStart->copy()->addDays((int) $start->diffInDays($running['date'])));
            }
        }
        $cmp = $this->data($accountId, ['date_from' => $cmpFrom->toDateString(), 'date_to' => $cmpTo->toDateString(),
            'columns' => $columns, 'exact' => true, 'col_change' => 'none']);
        if ($cmp['error']) {
            return $data;
        }

        // the n-th column ↔ the compared range's n-th column
        $keys = array_map('strval', array_keys($data['months']));
        $cmpKeys = array_map('strval', array_keys($cmp['months']));
        $labels = [];
        foreach ($keys as $n => $key) {
            $labels[$key] = isset($cmpKeys[$n]) ? $cmp['months'][$cmpKeys[$n]]
                . (($running['key'] ?? null) === $key ? ' (to ' . $cmpTo->format('d-m') . ')' : '') : null;
        }
        $pick = fn (?array $line) => ['cmp_months' => collect($keys)->mapWithKeys(fn ($key, $n) =>
            [$key => (float) ($line['months'][$cmpKeys[$n] ?? ''] ?? 0)])->all(), 'cmp_total' => (float) ($line['total'] ?? 0)];
        $zero = array_fill_keys(array_keys($data['months']), 0.0);

        foreach (['income', 'expenses'] as $part) {
            $other = collect($cmp[$part])->keyBy('key');
            $rows = collect($data[$part])->map(fn ($row) => $pick($other[$row['key']] ?? null) + $row);
            foreach ($other as $key => $row) {
                if (!$rows->firstWhere('key', $key)) {
                    $rows->push($pick($row) + ['key' => $key, 'label' => $row['label'], 'months' => $zero, 'total' => 0.0, 'sort' => $row['sort'] ?? '']);
                }
            }
            $data[$part] = $part === 'income' ? $rows->sortBy('sort')->values()->all() : $this->sortExpenses($rows);
        }
        foreach (['income', 'expenses', 'net'] as $line) {
            $data['totals'][$line] = $pick($cmp['totals'][$line]) + $data['totals'][$line];
        }
        $cmpMargin = $cmp['totals']['margin'];
        $data['totals']['margin_cmp'] = ['cmp_months' => collect($keys)->mapWithKeys(fn ($key, $n) => [$key => $cmpMargin[$cmpKeys[$n] ?? ''] ?? null])->all(),
            'cmp_total' => $cmpMargin['total']];
        foreach ($data['below'] as $i => $row) {
            $data['below'][$i] = $pick($cmp['below'][$i] ?? null) + $row;
        }
        $data['col_compare'] = ['mode' => $mode, 'labels' => $labels, 'range' => ReportPeriod::rangeText($cmpFrom, $cmpTo)];

        return $data;
    }

    private function line($key, $label, array $months): array
    {
        return ['key' => $key, 'label' => $label, 'months' => $months, 'total' => round(array_sum($months), 2)];
    }

    // [SQL period => column key] of the current data() call
    private array $map = [];

    // [SQL period => amount] → every column of the range (summed into its column, 0 when missing)
    private function fill($values, array $months): array
    {
        $out = array_fill_keys(array_keys($months), 0.0);
        foreach ($values as $period => $amount) {
            if (isset($this->map[$period])) {
                $out[$this->map[$period]] += (float) $amount;
            }
        }
        return array_map(fn ($amount) => round($amount, 2), $out);
    }

    // rows of (k, label, [sort], ym, amount) → one line per k
    private function pivot($rows, array $months)
    {
        return collect($rows)->groupBy('k')->map(function ($group, $key) use ($months) {
            $line = $this->line((string) $key, $group->first()->label, $this->fill($group->pluck('amount', 'ym'), $months));
            return $line + ['sort' => $group->first()->sort ?? ''];
        });
    }

    /**
     * The comparison period's totals on every line of $data: line['compare'] (+ rows found only in $cmp, with zero columns);
     * totals income / expenses / net get ['compare'], margin ['compare'] too. $data['compared'] = true.
     */
    public function compare(array $data, array $cmp): array
    {
        if ($data['error'] || $cmp['error']) {
            return $data;
        }
        $zero = array_fill_keys(array_keys($data['months']), 0.0);
        foreach (['income', 'expenses'] as $part) {
            $other = collect($cmp[$part])->keyBy('key');
            $rows = collect($data[$part])->map(fn ($row) => $row + ['compare' => (float) ($other[$row['key']]['total'] ?? 0)]);
            foreach ($other as $key => $row) {
                if (!$rows->firstWhere('key', $key)) {
                    $rows->push(['key' => $key, 'label' => $row['label'], 'months' => $zero, 'total' => 0.0, 'sort' => $row['sort'] ?? '', 'compare' => (float) $row['total']]);
                }
            }
            $data[$part] = ($part === 'income' ? $rows->sortBy('sort') : $rows)->values()->all();
        }
        foreach (['income', 'expenses', 'net'] as $key) {
            $data['totals'][$key]['compare'] = (float) $cmp['totals'][$key]['total'];
        }
        $data['totals']['margin']['compare'] = $cmp['totals']['margin']['total'];
        foreach ($data['below'] as $index => $row) {
            $data['below'][$index]['compare'] = (float) ($cmp['below'][$index]['total'] ?? 0);
        }
        $data['compared'] = true;

        return $data;
    }

    // the dates the totals box shows: "01-01-2026 → 30-11-2026"
    public function datesText(array $filters): string
    {
        [$from, $to] = $this->range($filters);
        return $from->format(Reservation::DATE_FORMAT) . ' → ' . (!empty($filters['exact']) ? $to : $to->copy()->endOfMonth())->format(Reservation::DATE_FORMAT);
    }
}
