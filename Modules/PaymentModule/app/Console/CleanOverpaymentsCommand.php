<?php

namespace Modules\PaymentModule\app\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Modules\AccountModule\app\Models\Account;
use Modules\PackageModule\app\Models\Package;
use Modules\PaymentModule\app\Exports\OverpaymentsExport;
use Modules\PaymentModule\app\Models\Payment;
use Modules\PaymentModule\app\Models\ShiftClosing;
use Modules\PaymentModule\app\Services\ExtraService;
use Modules\PaymentModule\app\Services\IncomeCategoryService;
use Modules\PaymentModule\app\Services\PaymentService;
use Modules\PaymentModule\app\Services\ShiftClosingService;
use Modules\ReservationModule\app\Models\Reservation;
use Modules\UserModule\app\Models\User;

/**
 * A data fix (2026-11-10, the credit audit): credits of reservations (paid on their own) and packages, found by rule, never by
 * ids (it runs on the server). due = paid + remaining (stored), credit = −remaining > 0.
 * 1. Card surcharge: due > 0 and a multiple of 5, credit ≤ 3% of due + 1 and ≤ 50 → the latest cash in (type payment) is
 *    reduced by the credit (paid = due) — a latest cash in of exactly the credit (the surcharge typed as its own cash in) is
 *    deleted instead (soft, delete_reason, payment_delete); a closing whose saved total already differs from its rows is never
 *    recalculated (the row → the Excel); its closed shift recalculated + "edited", logged in shift_events payment_edit
 *    (Payment Exceptions › Edited), reason "Card surcharge recorded by mistake"; an open shift: logged only.
 * 2. Rounding: due not a multiple of 5, credit ≤ 5 → an extra "Rounding" (system income category, counts as income, never
 *    taxed) of the credit. Payments / shifts untouched.
 * 3. Everything else: no change → an Excel file (member, phone, No., due, paid, credit, payments, a link to the live site).
 * Dry run by default (the same work in a transaction, rolled back; the Excel is written in both); --run commits. Safe twice
 * (a fixed row has no credit any more).
 */
class CleanOverpaymentsCommand extends Command
{
    const REASON = 'Card surcharge recorded by mistake';

    const LIVE_URL = 'https://sys.pivotcoworkingspace.com';

    const MAX_SURCHARGE = 50;

    const SAMPLES = 10;

    protected $signature = 'payments:clean-overpayments {--account= : only this account id} {--run : apply (default = dry run, nothing saved)}
        {--url=' . self::LIVE_URL . ' : the site the Excel links open}';

    protected $description = 'Card surcharge / rounding credits fixed, the rest exported to Excel (dry run unless --run)';

    public function handle(PaymentService $payments, ShiftClosingService $shifts, ExtraService $extras, IncomeCategoryService $categories): int
    {
        $run = (bool) $this->option('run');
        $this->info($run ? 'RUN: changes are saved.' : 'DRY RUN: nothing is saved (add --run to apply).');
        $accounts = Account::query()->when($this->option('account'), fn ($query, $id) => $query->whereKey((int) $id))->get();
        $failed = false;

        foreach ($accounts as $account) {
            $groups = $this->classify($account->id);
            $this->line('');
            $this->info('Account #' . $account->id . ' ' . $account->name . ': card surcharge ' . count($groups['card'])
                . ', rounding ' . count($groups['round']) . ', other ' . count($groups['other']) . '.');
            $by = User::where('userable_type', $account->getMorphClass())->where('userable_id', $account->id)->first();
            if (!$by) {
                $this->error('The account has no login (the log / the extras need one). Account skipped.');
                $failed = true;
                continue;
            }

            DB::beginTransaction();
            try {
                [$cardDone, $closings, $cardSkipped] = $this->fixSurcharges($groups['card'], $by, $payments, $shifts);
                [$roundDone, $roundSkipped] = $this->addRounding($groups['round'], $account->id, $by, $extras, $categories);
                $this->report('1. Card surcharge — latest cash in reduced (or deleted when it is the surcharge alone)', $cardDone);
                $this->reportClosings($closings);
                $this->report('2. Rounding — extra "Rounding" added', $roundDone);
                $other = array_merge($groups['other'], $cardSkipped, $roundSkipped);
                $wrong = array_filter(array_merge($cardDone, $roundDone), fn ($row) => abs($row['after']) > 0.001);
                if ($wrong) {
                    DB::rollBack();
                    $this->error(count($wrong) . ' row(s) not at remaining 0 after the change: rolled back, nothing saved.');
                    $failed = true;
                    continue;
                }
                $run ? DB::commit() : DB::rollBack();
                $this->info($run ? 'Saved. Follow-up: none (paid / remaining + shift totals done here).' : 'Dry run: rolled back.');
            } catch (\Throwable $e) {
                DB::rollBack();
                throw $e;
            }

            $this->exportOther($account, $other);
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    // every credit of the account, in its group (rows: kind, model, member, due, paid, credit)
    private function classify(int $accountId): array
    {
        $groups = ['card' => [], 'round' => [], 'other' => []];
        $reservations = Reservation::with('member', 'status')->where('account_id', $accountId)->where('package_id', 0)->where('remaining', '<', 0)->get();
        $packages = Package::with('member')->where('account_id', $accountId)->where('remaining', '<', 0)->get();
        foreach ([$reservations, $packages] as $list) {
            foreach ($list as $model) {
                $credit = round(-(float) $model->remaining, 2);
                $paid = round((float) $model->paid_amount, 2);
                $due = round($paid - $credit, 2);
                $cents = (int) round($due * 100);
                $five = $cents > 0 && $cents % 500 === 0;
                $row = ['model' => $model, 'kind' => $model instanceof Reservation ? 'Reservation' : 'Package', 'id' => $model->id,
                    'member' => $model->member?->name ?? ($model->member_id ? 'Member #' . $model->member_id : '-'),
                    'phone' => (string) $model->member?->phone, 'due' => $due, 'paid' => $paid, 'credit' => $credit];
                if ($five && $credit <= round($due * 0.03, 2) + 1 && $credit <= self::MAX_SURCHARGE) {
                    $groups['card'][] = $row;
                } elseif ($cents > 0 && !$five && $credit <= 5) {
                    $groups['round'][] = $row;
                } else {
                    $groups['other'][] = $row + ['why' => $cents <= 0 ? 'nothing due (cancelled / 0)' : ($five ? 'credit above the surcharge rule' : 'credit above 5')];
                }
            }
        }

        return $groups;
    }

    // group 1: the latest cash in reduced by the credit; [done rows, closings before / after, skipped rows]
    private function fixSurcharges(array $rows, User $by, PaymentService $payments, ShiftClosingService $shifts): array
    {
        $done = [];
        $skipped = [];
        $closings = [];
        $bad = [];
        foreach ($rows as $row) {
            $model = $row['model'];
            $payment = Payment::where('payable_type', $model->getMorphClass())->where('payable_id', $model->id)->where('type', 'payment')
                ->orderByDesc('paid_at')->orderByDesc('id')->lockForUpdate()->first();
            $refunded = $payment ? round((float) Payment::where('refund_of', $payment->id)->where('type', 'refund')->sum('amount'), 2) : 0;
            $newAmount = $payment ? round((float) $payment->amount - $row['credit'], 2) : 0;
            // the surcharge recorded as its own cash in (exactly the credit, not refunded): that cash in is deleted (soft, reason)
            $whole = $payment && abs($newAmount) < 0.001 && $refunded == 0;
            if (!$payment || (!$whole && ($newAmount <= 0 || $newAmount < $refunded))) {
                $skipped[] = $row + ['why' => !$payment ? 'no cash in to reduce' : 'latest cash in smaller than the credit / refunded'];
                continue;
            }

            $closing = $payment->shift_closing_id ? ShiftClosing::find($payment->shift_closing_id) : null;
            $key = $closing ? '#' . $closing->id : 'open:' . (int) $payment->creatable_id;
            // a closing whose saved total already differs from its rows is never recalculated here (the recalculation would
            // rewrite more than this fix, e.g. the owner's imported closing): the row goes to the Excel
            if ($closing && !isset($closings[$key]) && ($bad[$key] ??= abs($closing->net_total - $this->closingNet($closing, $shifts)) > 0.001)) {
                $skipped[] = $row + ['why' => 'its shift closing #' . $closing->id . ' saved total differs from its rows (not recalculated)'];
                continue;
            }
            $closings[$key] ??= ['closing' => $closing, 'user' => $payment->creatable_id, 'stored' => $closing?->net_total,
                'before' => $closing ? $this->closingNet($closing, $shifts) : 0.0, 'change' => 0.0];
            $closings[$key]['change'] -= $row['credit'];

            $before = $payments->snapshot($payment);
            $oldAmount = (float) $payment->amount;
            if ($whole) {
                $payment->forceFill(['delete_reason' => self::REASON, 'deleted_by' => $by->getKey()])->save();
                $payment->delete();
            } else {
                $payment->forceFill(['amount' => $newAmount])->save();
            }
            $payments->refresh($model instanceof Reservation ? $model->load('status') : $model);
            $action = $whole ? 'payment_delete' : 'payment_edit';
            $changes = ['before' => $before, 'after' => $whole ? null : $payments->snapshot($payment->fresh())];
            $closing ? $shifts->paymentChanged($closing->fresh(), $action, $payment, $by, self::REASON, $changes)
                : $shifts->logOpenChange($action, $payment, $by, self::REASON, $changes);

            $done[] = $row + ['payment' => $payment->payment_no, 'note' => ($whole ? 'deleted ' . number_format($oldAmount, 2) : number_format($oldAmount, 2) . ' → ' . number_format($newAmount, 2))
                . ' (' . ($closing ? 'closing #' . $closing->id : 'open shift') . ')', 'after' => round((float) $model->fresh()->remaining, 2)];
        }
        foreach ($closings as &$item) {
            $item['after'] = $item['closing'] ? $item['closing']->fresh()->net_total : null;
        }

        return [$done, $closings, $skipped];
    }

    // group 2: an extra "Rounding" of the credit, dated on the latest payment; [done rows, skipped rows]
    private function addRounding(array $rows, int $accountId, User $by, ExtraService $extras, IncomeCategoryService $categories): array
    {
        $done = [];
        $skipped = [];
        $category = $rows ? $categories->generated($accountId, 'rounding') : null;
        foreach ($rows as $row) {
            $model = $row['model'];
            if ($error = $extras->extrableError($model)) {
                $skipped[] = $row + ['why' => $error];
                continue;
            }
            $date = Payment::where('payable_type', $model->getMorphClass())->where('payable_id', $model->id)->max('paid_at') ?? today();
            $extras->create($accountId, $model, ['type' => 'extra', 'income_category_id' => $category->id, 'item' => 'Rounding',
                'quantity' => 1, 'unit_price' => $row['credit'], 'date' => $date, 'notes' => 'Overpayment cleanup (rounding)'], $by);
            $done[] = $row + ['payment' => '', 'note' => 'extra ' . number_format($row['credit'], 2), 'after' => round((float) $model->fresh()->remaining, 2)];
        }

        return [$done, $skipped];
    }

    // what a closing hands over (net of its methods), from its rows now (= what the recalculation saves before this change)
    private function closingNet(ShiftClosing $closing, ShiftClosingService $shifts): float
    {
        return round((float) $shifts->summary($closing->account_id, $closing->payments()->get(), $closing->expenses()->get())['net_total'], 2);
    }

    private function report(string $title, array $rows): void
    {
        $money = fn ($value) => number_format($value, 2);
        $this->line('');
        $this->info($title . ': ' . count($rows) . ' row(s), ' . $money(array_sum(array_column($rows, 'credit'))));
        if (!$rows) {
            return;
        }
        usort($rows, fn ($a, $b) => $b['credit'] <=> $a['credit']);
        $this->table(['Member', 'No.', 'Due', 'Paid', 'Credit', 'Change', 'Remaining after'], array_map(fn ($row) => [
            mb_strimwidth($row['member'], 0, 28, '…'), ($row['kind'] === 'Reservation' ? 'Res #' : 'Pkg #') . $row['id'],
            $money($row['due']), $money($row['paid']), $money($row['credit']),
            ($row['payment'] ? 'No. ' . $row['payment'] . ' ' : '') . $row['note'], $money($row['after']),
        ], array_slice($rows, 0, self::SAMPLES)));
        if (count($rows) > self::SAMPLES) {
            $this->line('  … and ' . (count($rows) - self::SAMPLES) . ' more.');
        }
    }

    private function reportClosings(array $closings): void
    {
        if (!$closings) {
            return;
        }
        $money = fn ($value) => number_format($value, 2);
        $users = User::whereIn('id', array_column($closings, 'user'))->with('userable')->get()->keyBy('id');
        $closed = array_filter($closings, fn ($item) => $item['closing']);
        $this->info('Shifts touched: ' . count($closed) . ' closed (recalculated, marked edited), ' . (count($closings) - count($closed)) . ' open (logged only).');
        uasort($closings, fn ($a, $b) => $a['change'] <=> $b['change']);
        $this->table(['Shift', 'Collector', 'Closed at', 'Net saved', 'Net before', 'Net after', 'Change'], array_map(fn ($item, $key) => [
            $item['closing'] ? $key . ($item['closing']->is_imported ? ' (imported)' : '') : 'open shift',
            $users[$item['user']]?->userable?->name ?? '#' . $item['user'],
            $item['closing']?->closed_at?->format('d-m-Y H:i') ?? '-',
            $item['closing'] ? $money($item['stored']) . (abs($item['stored'] - $item['before']) > 0.001 ? ' *' : '') : '-',
            $item['closing'] ? $money($item['before']) : '-', $item['closing'] ? $money($item['after']) : '-', $money($item['change']),
        ], $closings, array_keys($closings)));
        if (array_filter($closed, fn ($item) => abs($item['stored'] - $item['before']) > 0.001)) {
            $this->comment('  * the saved net differs from its rows before this change (an older difference; the recalculation fixes it too).');
        }
    }

    // group 3 (+ the skipped ones) → Excel (local disk, exports/)
    private function exportOther(Account $account, array $rows): void
    {
        $this->line('');
        $this->info('3. Everything else — no change: ' . count($rows) . ' row(s), ' . number_format(array_sum(array_column($rows, 'credit')), 2));
        if (!$rows) {
            return;
        }
        usort($rows, fn ($a, $b) => $b['credit'] <=> $a['credit']);
        $base = rtrim((string) $this->option('url'), '/');
        $lines = [];
        foreach ($rows as $row) {
            $model = $row['model'];
            $list = Payment::where('payable_type', $model->getMorphClass())->where('payable_id', $model->id)->orderBy('paid_at')->orderBy('id')->get()
                ->map(fn ($payment) => 'No. ' . $payment->payment_no . ' · ' . $payment->paid_at?->format('d-m-Y') . ' · '
                    . ($payment->type === 'refund' ? '−' : '') . number_format((float) $payment->amount, 2) . ($payment->type === 'payment' ? '' : ' (' . $payment->type . ')'))
                ->implode("\n");
            $route = $row['kind'] === 'Reservation' ? 'account.reservations.show' : 'account.packages.show';
            $lines[] = [$row['member'], $row['phone'], $row['kind'] . ' #' . $row['id'], $row['due'], $row['paid'], $row['credit'],
                $row['why'], $list, $base . route($route, $row['id'], false)];
        }
        $path = 'exports/overpayments-other-account' . $account->id . '-' . now()->format('Ymd-His') . '.xlsx';
        Excel::store(new OverpaymentsExport($lines), $path, 'local');
        $this->info('Excel: ' . Storage::disk('local')->path($path));
    }
}
