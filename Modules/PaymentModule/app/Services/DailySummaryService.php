<?php

namespace Modules\PaymentModule\app\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\PaymentModule\app\Models\Expense;
use Modules\PaymentModule\app\Models\Payment;
use Modules\PaymentModule\app\Models\PaymentMethod;
use Modules\UserModule\app\Models\User;

/**
 * The Daily Summary (account only, read only; docs/payments-plan.md §2b, 2026-10-13): what was collected on a date (paid_at),
 * from the payments + expenses now — no day closing, no lock (the shift is the only closing level). Payments, refunds, expenses
 * (spent_at) and withholding of every collector (the employees and the account itself); net per method = payments - refunds - expenses.
 */
class DailySummaryService
{
    /**
     * The totals of a day: ['collectors' => [user_id => ['name', 'methods' => [method_id => ['payments', 'refunds', 'expenses', 'net']], 'net']],
     *  'methods' => [method_id => ['name', 'payments', 'refunds', 'expenses', 'net']], 'refunds' / 'expenses' => ['count', 'total'],
     *  'withholding' => ['count', 'total'], 'no_method_expenses', 'cash_total' (payments - refunds, withholding apart),
     *  'expenses_total', 'net_total' (the methods' net), 'count'] — net = payments - refunds - expenses
     */
    public function summary($accountId, $date): array
    {
        $rows = Payment::where('account_id', $accountId)->where('paid_at', Carbon::parse($date)->toDateString())
            ->groupBy('creatable_type', 'creatable_id', 'payment_method_id', 'type')
            ->selectRaw('creatable_type, creatable_id, payment_method_id, type, COUNT(*) n, SUM(amount) total')
            ->get();

        $methodNames = PaymentMethod::withTrashed()->where('account_id', $accountId)->get()->pluck('name', 'id');
        $expenseRows = Expense::where('account_id', $accountId)->where('spent_at', Carbon::parse($date)->toDateString())
            ->groupBy('creatable_type', 'creatable_id', 'payment_method_id')
            ->selectRaw('creatable_type, creatable_id, payment_method_id, COUNT(*) n, SUM(amount) total')
            ->get();
        $users = User::with('userable')->whereIn('id', $rows->pluck('creatable_id')->merge($expenseRows->pluck('creatable_id'))->unique())->get()->keyBy('id');
        $summary = ['collectors' => [], 'methods' => [], 'refunds' => ['count' => 0, 'total' => 0.0], 'expenses' => ['count' => 0, 'total' => 0.0],
            'withholding' => ['count' => 0, 'total' => 0.0], 'no_method_expenses' => 0.0,
            'cash_total' => 0.0, 'expenses_total' => 0.0, 'net_total' => 0.0, 'count' => 0];
        $blank = ['payments' => 0.0, 'refunds' => 0.0, 'expenses' => 0.0, 'net' => 0.0];

        foreach ($rows as $row) {
            $summary['count'] += $row->n;
            if ($row->type === 'withholding') {
                $summary['withholding']['count'] += $row->n;
                $summary['withholding']['total'] = round($summary['withholding']['total'] + $row->total, 2);
                continue;
            }

            $userId = (int) $row->creatable_id;
            $methodId = (int) $row->payment_method_id;
            $user = $users[$userId] ?? null;
            $key = $row->type === 'refund' ? 'refunds' : 'payments';
            $signed = $row->type === 'refund' ? -$row->total : $row->total;

            $collector = &$summary['collectors'][$userId];
            $collector['name'] ??= $user?->userable?->displayName() ?? $user?->email ?? 'User #' . $userId;
            $collector['methods'][$methodId] ??= $blank;
            $line = &$collector['methods'][$methodId];
            $line[$key] = round($line[$key] + $row->total, 2);
            $line['net'] = round($line['net'] + $signed, 2);
            $collector['net'] = round(($collector['net'] ?? 0) + $signed, 2);

            $method = &$summary['methods'][$methodId];
            $method ??= ['name' => $methodNames[$methodId] ?? 'Method #' . $methodId] + $blank;
            $method[$key] = round($method[$key] + $row->total, 2);
            $method['net'] = round($method['net'] + $signed, 2);

            if ($row->type === 'refund') {
                $summary['refunds']['count'] += $row->n;
                $summary['refunds']['total'] = round($summary['refunds']['total'] + $row->total, 2);
            }
            $summary['cash_total'] = round($summary['cash_total'] + $signed, 2);
            unset($collector, $line, $method);
        }

        // expenses: their own minus column; no method (imported old method 5) = in the expenses only, in no method's cash
        foreach ($expenseRows as $row) {
            $summary['expenses']['count'] += $row->n;
            $summary['expenses']['total'] = round($summary['expenses']['total'] + $row->total, 2);
            $methodId = (int) $row->payment_method_id;
            if (!$methodId) {
                $summary['no_method_expenses'] = round($summary['no_method_expenses'] + $row->total, 2);
                continue;
            }
            $userId = (int) $row->creatable_id;
            $user = $users[$userId] ?? null;
            $collector = &$summary['collectors'][$userId];
            $collector['name'] ??= $user?->userable?->displayName() ?? $user?->email ?? 'User #' . $userId;
            $collector['methods'][$methodId] ??= $blank;
            $collector['methods'][$methodId]['expenses'] = round($collector['methods'][$methodId]['expenses'] + $row->total, 2);
            $collector['methods'][$methodId]['net'] = round($collector['methods'][$methodId]['net'] - $row->total, 2);
            $collector['net'] = round(($collector['net'] ?? 0) - $row->total, 2);

            $method = &$summary['methods'][$methodId];
            $method ??= ['name' => $methodNames[$methodId] ?? 'Method #' . $methodId] + $blank;
            $method['expenses'] = round($method['expenses'] + $row->total, 2);
            $method['net'] = round($method['net'] - $row->total, 2);
            unset($collector, $method);
        }
        $summary['expenses_total'] = $summary['expenses']['total'];
        $summary['net_total'] = round(collect($summary['methods'])->sum('net'), 2);

        return $summary;
    }

    /**
     * By Collector + the logins whose closings of the day are EMPTY (0.00, ShiftClosingService::closingsForDate(); 2026-10-27):
     * a collector line with no methods, net 0, 'empty' = true — only for logins with no cash in / out that day.
     */
    public function withEmptyClosings(array $summary, Collection $closings): array
    {
        foreach ($closings as $closing) {
            $empty = !$closing->count && !(int) ($closing->totals['expenses']['count'] ?? 0);
            if (!$empty || isset($summary['collectors'][$closing->user_id])) {
                continue;
            }
            $summary['collectors'][$closing->user_id] = [
                'name' => $closing->user?->userable?->displayName() ?? 'User #' . $closing->user_id,
                'methods' => [],
                'net' => 0.0,
                'empty' => true,
            ];
        }

        return $summary;
    }

    // the refunds dated that day (who, amount, the original payment, reason), as they were added
    public function refunds($accountId, $date): Collection
    {
        return Payment::with('creatable.userable', 'refundOf', 'paymentMethod', 'member')
            ->where('account_id', $accountId)->where('type', 'refund')->where('paid_at', Carbon::parse($date)->toDateString())
            ->orderBy('created_at')->get();
    }
}
