<?php

namespace Modules\PaymentModule\app\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\AccountModule\app\Models\Account;
use Modules\PaymentModule\app\Models\IncomeCategory;
use Modules\PaymentModule\app\Models\Payment;
use Modules\PaymentModule\app\Services\PaymentService;
use Modules\PaymentModule\app\Services\ShiftClosingService;
use Modules\ReservationModule\app\Models\Reservation;
use Modules\ReservationModule\app\Models\ReservationEvent;
use Modules\UserModule\app\Models\User;

/**
 * A data fix (2026-11-06, the credit audit): VAT that was recorded as a reservation payment becomes Other Income of the
 * "VAT (manual)" category (not income), unlinked from the reservation.
 * Found by rule, never by ids (it runs on the server): a cash in (type payment, not deleted) of a reservation (not deleted,
 * counted status, paid on its own: package_id 0) whose notes name VAT ("VAT", "1% vat", "14%Vat" …) and whose amount fits in
 * the reservation's credit (moving it never makes the reservation owe; a reservation with two VAT payments: one after the
 * other). The others are listed as skipped, never changed.
 * Kept: id, payment_no, amount, date, method, recorder, member, shift. Then: the reservation's paid / remaining refreshed, a
 * shift_events payment_edit (before / after, Payment Exceptions › Edited; no totals touched) + a reservation_events vat_moved.
 * Dry run by default (the same work inside a transaction, rolled back); --run commits. Safe twice (a moved payment no longer
 * matches). The shift totals are compared before / after (they read amounts / methods / types only): any difference = rolled back.
 */
class MoveVatPaymentsCommand extends Command
{
    const REASON = 'VAT moved from reservation payment';

    const CATEGORY = 'VAT (manual)';

    // "VAT" as a word (1% vat, 14%Vat, VAT 1% …), not inside another word
    const VAT_NOTES = "REGEXP_LIKE(payments.notes, '(^|[^a-z])vat([^a-z]|$)', 'i')";

    protected $signature = 'payments:move-vat {--account= : only this account id} {--run : apply (default = dry run, nothing saved)}';

    protected $description = 'Turn VAT recorded as reservation payments into Other Income "VAT (manual)" (dry run unless --run)';

    public function handle(PaymentService $payments, ShiftClosingService $shifts): int
    {
        $run = (bool) $this->option('run');
        $this->info($run ? 'RUN: changes are saved.' : 'DRY RUN: nothing is saved (add --run to apply).');
        $accounts = Account::query()->when($this->option('account'), fn ($query, $id) => $query->whereKey((int) $id))->get();
        $failed = false;

        foreach ($accounts as $account) {
            $category = IncomeCategory::where('account_id', $account->id)->where('name_en', self::CATEGORY)->where('is_revenue', 0)->get();
            $candidates = $this->candidates($account->id);
            $this->line('');
            $this->info('Account #' . $account->id . ' ' . $account->name . ': ' . $candidates->count() . ' VAT payment(s) on reservations.');
            if ($candidates->isEmpty()) {
                continue;
            }
            if ($category->count() !== 1) {
                $this->error('Income category "' . self::CATEGORY . '" (not counted as income) not found exactly once (' . $category->count()
                    . '): run database/fixes/2026-10-28-vat-manual-income-category.sql first. Account skipped.');
                $failed = true;
                continue;
            }
            $by = User::where('userable_type', $account->getMorphClass())->where('userable_id', $account->id)->first();
            if (!$by) {
                $this->error('The account has no login (the log needs one). Account skipped.');
                $failed = true;
                continue;
            }

            DB::beginTransaction();
            try {
                $shiftKeys = $candidates->map(fn ($payment) => $this->shiftKey($payment))->unique()->values();
                $before = $this->shiftTotals($account->id, $shiftKeys, $shifts);
                [$rows, $skipped] = $this->move($candidates, $category->first(), $by, $payments);
                $after = $this->shiftTotals($account->id, $shiftKeys, $shifts);
                $this->report($rows, $skipped);
                $same = $this->compareShifts($before, $after);
                if (!$same) {
                    DB::rollBack();
                    $this->error('Shift totals changed: rolled back, nothing saved.');
                    $failed = true;
                    continue;
                }
                if ($run) {
                    DB::commit();
                    $this->info('Saved. Follow-up: none (paid / remaining refreshed here).');
                } else {
                    DB::rollBack();
                    $this->comment('Dry run: rolled back.');
                }
            } catch (\Throwable $e) {
                DB::rollBack();
                throw $e;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    // the VAT cash ins of the account's counted reservations paid on their own (oldest first, a reservation's together)
    private function candidates(int $accountId)
    {
        return Payment::query()
            ->select('payments.*')
            ->join('reservations', 'reservations.id', '=', 'payments.payable_id')
            ->join('reservation_statuses', 'reservation_statuses.id', '=', 'reservations.reservation_status_id')
            ->where('payments.account_id', $accountId)
            ->where('payments.payable_type', (new Reservation())->getMorphClass())
            ->where('payments.type', 'payment')
            ->whereNull('reservations.deleted_at')
            ->where('reservations.package_id', 0)
            ->where('reservation_statuses.is_counted', 1)
            ->whereRaw(self::VAT_NOTES)
            ->orderBy('payments.payable_id')->orderBy('payments.id')
            ->with(['member', 'paymentMethod'])
            ->get();
    }

    // each payment: moved when it fits in the reservation's credit now, else skipped (reason)
    private function move($candidates, IncomeCategory $category, User $by, PaymentService $payments): array
    {
        $rows = [];
        $skipped = [];
        foreach ($candidates as $payment) {
            $reservation = Reservation::with('status')->lockForUpdate()->find($payment->payable_id);
            $remaining = round((float) $reservation->remaining, 2);
            $amount = round((float) $payment->amount, 2);
            $line = ['payment' => $payment->payment_no, 'member' => $payment->member?->name ?? '-', 'reservation' => $reservation->id,
                'amount' => $amount, 'notes' => (string) $payment->notes, 'remaining' => $remaining];
            if ($amount > -$remaining + 0.001) {
                $skipped[] = $line + ['why' => $remaining < 0 ? 'more than its credit ' . number_format(-$remaining, 2) : 'no credit (it would owe)'];
                continue;
            }

            $snapshot = $payments->snapshot($payment);
            $payment->forceFill(['payable_type' => null, 'payable_id' => 0, 'income_category_id' => $category->id])->save();
            $payments->refresh($reservation);
            $reservation->refresh();
            $changes = ['before' => $snapshot, 'after' => $payments->snapshot($payment->fresh(['member', 'paymentMethod']))];
            // the audit log, on the payment's own shift (closed or open); no totals touched (the amount / method are the same)
            app(ShiftClosingService::class)->logOpenChange('payment_edit', $payment, $by, self::REASON, $changes);
            $event = new ReservationEvent([
                'account_id' => $reservation->account_id, 'reservation_id' => $reservation->id, 'related_reservation_id' => 0,
                'type' => 'vat_moved', 'reason' => self::REASON, 'amount' => $amount,
                'data' => ['payment_id' => $payment->id, 'payment_no' => $payment->payment_no, 'income_category_id' => $category->id,
                    'remaining_before' => $remaining, 'remaining_after' => round((float) $reservation->remaining, 2)],
            ]);
            $event->creatable()->associate($by);
            $event->save();

            $rows[] = $line + ['after' => round((float) $reservation->remaining, 2)];
        }

        return [$rows, $skipped];
    }

    // a closed shift = its id; an open shift = "open:<recorder>"
    private function shiftKey(Payment $payment): string
    {
        return $payment->shift_closing_id ? (string) $payment->shift_closing_id : 'open:' . (int) $payment->creatable_id;
    }

    // the totals of those shifts from their payments now (ShiftClosingService::summary(), what a closing saves)
    private function shiftTotals(int $accountId, $keys, ShiftClosingService $shifts): array
    {
        $totals = [];
        foreach ($keys as $key) {
            $query = Payment::where('account_id', $accountId);
            str_starts_with($key, 'open:')
                ? $query->where('shift_closing_id', 0)->where('creatable_id', (int) substr($key, 5))
                : $query->where('shift_closing_id', (int) $key);
            $summary = $shifts->summary($accountId, $query->get());
            $totals[$key] = ['count' => $summary['count'], 'cash_total' => $summary['cash_total'],
                'withholding' => $summary['withholding']['total'], 'refunds' => $summary['refunds']['total'],
                'methods' => collect($summary['methods'])->map(fn ($method) => $method['net'])->sortKeys()->all()];
        }

        return $totals;
    }

    private function report(array $rows, array $skipped): void
    {
        $money = fn ($value) => number_format($value, 2);
        if ($rows) {
            $this->table(['Payment', 'Member', 'Reservation', 'Amount', 'Notes', 'Remaining before → after'],
                array_map(fn ($row) => [$row['payment'], mb_strimwidth($row['member'], 0, 28, '…'), '#' . $row['reservation'],
                    $money($row['amount']), mb_strimwidth($row['notes'], 0, 24, '…'),
                    $money($row['remaining']) . ' → ' . $money($row['after'])], $rows));
        }
        $perReservation = collect($rows)->groupBy('reservation');
        $this->info('To move: ' . count($rows) . ' payment(s), ' . $money(array_sum(array_column($rows, 'amount')))
            . ' on ' . $perReservation->count() . ' reservation(s); their remaining ' . $money($perReservation->sum(fn ($lines) => $lines->first()['remaining']))
            . ' → ' . $money($perReservation->sum(fn ($lines) => $lines->last()['after'])) . '.');
        if ($skipped) {
            $this->warn('Skipped (not changed): ' . count($skipped) . ' payment(s), ' . $money(array_sum(array_column($skipped, 'amount'))));
            $this->table(['Payment', 'Member', 'Reservation', 'Amount', 'Notes', 'Remaining', 'Why'],
                array_map(fn ($row) => [$row['payment'], mb_strimwidth($row['member'], 0, 28, '…'), '#' . $row['reservation'],
                    $money($row['amount']), mb_strimwidth($row['notes'], 0, 24, '…'), $money($row['remaining']), $row['why']], $skipped));
        }
    }

    private function compareShifts(array $before, array $after): bool
    {
        $changed = array_keys(array_filter($before, fn ($totals, $key) => $totals != ($after[$key] ?? null), ARRAY_FILTER_USE_BOTH));
        $this->info('Shift totals: ' . count($before) . ' shift(s) checked (count, cash total, withholding, refunds, net per method): '
            . ($changed ? 'CHANGED ' . implode(', ', $changed) : 'all identical before / after.'));

        return !$changed;
    }
}
