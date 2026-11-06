<?php

namespace Modules\ReportModule\app\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\PaymentModule\app\Models\Expense;
use Modules\ReservationModule\app\Models\Reservation;

/**
 * Report 4 — Expenses (docs/reports-plan.md §4): Cash Out by spent_at, in two blocks — Shift Cash Out (recorded in a login's shift,
 * `Expense::scopeInShifts()`) and Company Cash Out (`is_company`: advances, salaries; in no shift). Voided / deleted rows are left
 * out (soft deleted). Refunds are NOT here (they are the Refunds column of the Income report).
 * Views: By category (one row per block + category, SQL grouped) | Details (one row per Cash Out).
 */
class ExpenseReport
{
    const KINDS = ['shift' => 'Shift Cash Out', 'company' => 'Company Cash Out'];

    public function filters(array $input): array
    {
        $date = fn ($value) => $value && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
        return [
            'search' => trim((string) ($input['search'] ?? '')),
            'date_from' => $date($input['date_from'] ?? null),
            'date_to' => $date($input['date_to'] ?? null),
            'category_id' => (int) ($input['category_id'] ?? 0),
            'method' => (int) ($input['method'] ?? 0),
            'kind' => array_key_exists($input['kind'] ?? '', self::KINDS) ? $input['kind'] : '',
        ];
    }

    // the days: the picked days (the page starts on this month); none = all dates
    public function range(array $filters): array
    {
        if ($filters['date_from']) {
            return [Carbon::parse($filters['date_from']), Carbon::parse($filters['date_to'] ?: $filters['date_from'])];
        }
        return [null, null];
    }

    public function rangeText(array $filters): string
    {
        [$from, $to] = $this->range($filters);
        $format = Reservation::DATE_FORMAT;
        if (!$from) {
            return 'All dates';
        }
        return $from->isSameDay($to) ? $from->format($format) : $from->format($format) . ' → ' . $to->format($format);
    }

    // the account's Cash Out under the filters (Eloquent: soft deletes applied)
    public function base($accountId, array $filters)
    {
        [$from, $to] = $this->range($filters);
        $query = Expense::query()->where('expenses.account_id', $accountId)
            ->when($from, fn ($query) => $query->where('expenses.spent_at', '>=', $from->toDateString()))
            ->when($to, fn ($query) => $query->where('expenses.spent_at', '<=', $to->toDateString()))
            ->when($filters['category_id'], fn ($query, $id) => $query->where('expenses.expense_category_id', $id))
            ->when($filters['method'], fn ($query, $id) => $query->where('expenses.payment_method_id', $id))
            ->when($filters['kind'] === 'shift', fn ($query) => $query->inShifts())
            ->when($filters['kind'] === 'company', fn ($query) => $query->where('expenses.is_company', true));
        if ($filters['search'] !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $filters['search']) . '%';
            $query->where(fn ($query) => $query->where('expenses.item', 'like', $like)->orWhere('expenses.notes', 'like', $like)
                ->orWhere('expenses.expense_no', ltrim($filters['search'], '#')));
        }

        return $query;
    }

    // By category: block (Shift / Company) | category | count | amount, ordered by block then category name
    public function byCategory($accountId, array $filters)
    {
        $grouped = $this->base($accountId, $filters)->toBase()
            ->leftJoin('expense_categories as c', 'c.id', '=', 'expenses.expense_category_id')
            ->groupBy('expenses.is_company', 'expenses.expense_category_id')
            ->selectRaw("expenses.is_company, expenses.expense_category_id,
                MAX(COALESCE(NULLIF(c.name_en, ''), c.name_ar, 'No category')) as category,
                COUNT(*) as count, ROUND(SUM(expenses.amount), 2) as amount");

        return DB::query()->fromSub($grouped, 'g')
            ->selectRaw("g.*, IF(g.is_company = 1, 'Company Cash Out', 'Shift Cash Out') as block, CONCAT(g.is_company, '-', g.category) as sort_key");
    }

    // Details: every Cash Out (newest first by default), its category / method / recorder loaded
    public function details($accountId, array $filters)
    {
        return $this->base($accountId, $filters)->with(['expenseCategory', 'paymentMethod', 'creatable.userable'])->select('expenses.*');
    }

    // totals: shift, company, total, count
    public function totals($accountId, array $filters): object
    {
        $totals = $this->base($accountId, $filters)->toBase()->selectRaw("
            ROUND(COALESCE(SUM(IF(expenses.is_company = 0, expenses.amount, 0)), 0), 2) as shift,
            ROUND(COALESCE(SUM(IF(expenses.is_company = 1, expenses.amount, 0)), 0), 2) as company,
            ROUND(COALESCE(SUM(expenses.amount), 0), 2) as total,
            COUNT(*) as count,
            COUNT(DISTINCT CONCAT(expenses.is_company, '-', expenses.expense_category_id)) as categories")->first();
        foreach (['shift', 'company', 'total'] as $field) {
            $totals->$field = (float) $totals->$field;
        }

        return $totals;
    }
}
