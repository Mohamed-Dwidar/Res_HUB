<?php

namespace Modules\PaymentModule\app\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Modules\PaymentModule\app\Models\Expense;
use Modules\PaymentModule\app\Models\Payment;
use Modules\PaymentModule\app\Models\PaymentMethod;
use Modules\ReservationModule\app\Models\Reservation;

/**
 * The Payments page tabs All | Cash Out (docs/payments-plan.md "Cash In / Cash Out", 2026-10-14): payments and expenses stay in
 * their own tables, the list reads them together (UNION ALL, one row per payment / expense) with each model's own filters.
 * Cash In = payments + withholding; Cash Out = expenses + refunds; withholding is shown apart (not cash). The Cash In tab is the
 * payments list itself (PaymentActions::table()).
 */
class CashListService
{
    // the page tabs: all (default) | in | out
    const TYPES = [
        'all' => 'All',
        'in' => 'Cash In',
        'out' => 'Cash Out',
    ];

    public function __construct(private PaymentService $paymentService, private ExpenseService $expenseService)
    {
    }

    /**
     * The rows of the list (a query builder on the union: kind payment | expense, id, type payment | withholding | refund | expense,
     * at_date, created_at, amount, payment_method_id), not ordered.
     * $direction all | out; $filters: search, method (an id), category (out: an expense category id | refund), date_from / date_to;
     * $tab today | month | all (on the date); $collectorId: only what this login added (employees), null = all.
     */
    public function query($accountId, string $direction, array $filters, string $tab, $collectorId = null)
    {
        $common = Arr::only($filters, ['search', 'date_from', 'date_to', 'created_by']);
        $method = ctype_digit((string) ($filters['method'] ?? '')) ? (int) $filters['method'] : null;
        $category = (string) ($filters['category'] ?? '');

        $payments = $this->paymentService->listQuery($accountId, $common + ['payment_method_id' => $method])->tab($tab)
            ->when($collectorId, fn ($query, $userId) => $query->collectedBy($userId))
            ->when($direction === 'out', fn ($query) => $query->where('type', 'refund'))
            // an expense category chosen: no refunds
            ->when($direction === 'out' && ctype_digit($category), fn ($query) => $query->whereRaw('0 = 1'))
            ->setEagerLoads([])->toBase()
            ->selectRaw("'payment' AS kind, id, type, paid_at AS at_date, created_at, amount, payment_method_id");

        $expenses = $this->expenseService->listQuery($accountId, $common + ['method' => (string) $method, 'expense_category_id' => ctype_digit($category) ? (int) $category : null])->tab($tab)
            ->when($collectorId, fn ($query, $userId) => $query->collectedBy($userId))
            // "Refunds" chosen in the category filter: refunds only
            ->when($category === 'refund', fn ($query) => $query->whereRaw('0 = 1'))
            ->setEagerLoads([])->toBase()
            ->selectRaw("'expense' AS kind, id, 'expense' AS type, spent_at AS at_date, created_at, amount, payment_method_id");

        return DB::query()->fromSub($payments->unionAll($expenses), 'cash');
    }

    // newest first (the date, then when it was added); or by the amount
    public function order($query, string $column, string $direction)
    {
        $direction = $direction === 'asc' ? 'asc' : 'desc';
        if ($column === 'amount') {
            return $query->orderByRaw("IF(type IN ('refund', 'expense'), -amount, amount) $direction")->orderBy('created_at', 'desc');
        }

        return $query->orderBy('at_date', $direction)->orderBy('created_at', $direction)->orderBy('id', $direction);
    }

    // the payments / expenses of the union rows, with what their cells show, in the rows' order: [['kind' => ..., 'model' => ...]]
    public function load($rows): array
    {
        $payments = Payment::with('member', 'paymentMethod', 'incomeCategory', 'creatable.userable')
            ->with(['payable' => fn ($morph) => $morph->morphWith([Reservation::class => ['space', 'unit.color', 'plan']])])
            ->whereIn('id', $rows->where('kind', 'payment')->pluck('id'))->get()->keyBy('id');
        $expenses = Expense::with('expenseCategory', 'paymentMethod', 'creatable.userable')
            ->whereIn('id', $rows->where('kind', 'expense')->pluck('id'))->get()->keyBy('id');

        return $rows->map(fn ($row) => ['kind' => $row->kind, 'model' => $row->kind === 'payment' ? $payments->get($row->id) : $expenses->get($row->id)])
            ->filter(fn ($item) => $item['model'])->values()->all();
    }

    /**
     * The summary line of the filtered rows: in (payments), out (refunds + expenses), net = in − out (cash), the net of each method
     * (in the account's order) and withholding apart (not cash).
     * ['in', 'out', 'refunds', 'expenses', 'net', 'withholding' => float, 'methods' => [name => ['in', 'out', 'net']]]
     */
    public function summary($query): array
    {
        $rows = (clone $query)->selectRaw('type, payment_method_id, SUM(amount) AS total')->groupBy('type', 'payment_method_id')->get();
        $sum = fn ($types, $methodId = null) => round((float) $rows->whereIn('type', (array) $types)
            ->when($methodId !== null, fn ($rows) => $rows->where('payment_method_id', $methodId))->sum('total'), 2);

        $cash = $rows->whereIn('type', ['payment', 'refund', 'expense']);
        $methods = PaymentMethod::withTrashed()->whereIn('id', $cash->pluck('payment_method_id'))->ordered()->get();
        $split = fn ($in, $out) => ['in' => $in, 'out' => $out, 'net' => round($in - $out, 2)];
        $byMethod = $methods->mapWithKeys(fn ($method) => [$method->name => $split($sum('payment', $method->id), $sum(['refund', 'expense'], $method->id))]);
        // rows whose method is gone / unknown (imported data)
        $unknown = $cash->whereNotIn('payment_method_id', $methods->pluck('id'));
        if ($unknown->isNotEmpty()) {
            $byMethod['Other'] = $split(round((float) $unknown->where('type', 'payment')->sum('total'), 2), round((float) $unknown->where('type', '!=', 'payment')->sum('total'), 2));
        }

        $in = $sum('payment');
        $refunds = $sum('refund');
        $expenses = $sum('expense');

        return [
            'in' => $in,
            'out' => round($refunds + $expenses, 2),
            'refunds' => $refunds,
            'expenses' => $expenses,
            'net' => round($in - $refunds - $expenses, 2),
            'withholding' => $sum('withholding'),
            'methods' => $byMethod->all(),
        ];
    }
}
