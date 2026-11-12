<?php

namespace Modules\PaymentModule\app\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Modules\AccountModule\app\Models\Account;
use Modules\PackageModule\app\Models\Package;
use Modules\PaymentModule\app\Contracts\Payable;
use Modules\PaymentModule\app\Models\Expense;
use Modules\PaymentModule\app\Models\Payment;
use Modules\PaymentModule\app\Models\PaymentMethod;
use Modules\ReservationModule\app\Models\Reservation;

/**
 * A Cash Out settled against a reservation / package (2026-11-12, user; Settings › General "Allow settling a Cash Out against
 * a reservation", off by default): e.g. a member's buffet paid from what they owe. Saving = the Cash Out (its normal category)
 * + a matching Cash In on the reservation / package, both with the system method "Offset" (PaymentMethodService::generated()),
 * linked both ways (expenses.offset_payment_id / payments.offset_expense_id), in the recorder's open shift: the Offset line of
 * the shift = in X, out X, net 0 — nothing to hand over. The reservation's price never changes: paid += X, remaining −= X;
 * the Income report counts the full rent, the Expenses report the Cash Out.
 * The Cash In is locked (PaymentService::isSystem()): an edit / delete of the Cash Out (its normal rules, ExpenseService)
 * updates / deletes both, logged on their shift. The settled reservation / package is fixed (to change it: delete + add again).
 * Called inside ExpenseService's transactions.
 */
class OffsetService
{
    public function __construct(private PaymentService $paymentService, private ShiftClosingService $shiftService)
    {
    }

    public function enabled($accountId): bool
    {
        return (bool) Account::settingsOf($accountId)?->cashOutOffsetEnabled();
    }

    public function method($accountId): PaymentMethod
    {
        return app(PaymentMethodService::class)->generated($accountId, 'offset');
    }

    /**
     * The "Settle against reservation" list: the account's reservations paid on their own + packages that still owe, by member
     * (searchable), ["reservation-12" => "Member · #12 Space › Unit · period — Remaining x", ...]
     */
    public function options($accountId): array
    {
        $line = fn ($kind, $payable) => [$kind . '-' . $payable->id => ($payable->member?->name ?? '-') . ' · '
            . $this->paymentService->payableName($payable) . ' — Remaining ' . number_format($payable->remaining, 2)];

        $reservations = Reservation::where('account_id', $accountId)->where('package_id', 0)->where('remaining', '>', 0)
            ->with('member', 'space', 'unit', 'plan')->orderByDesc('start_at')->get()->mapWithKeys(fn ($row) => $line('reservation', $row));
        $packages = Package::where('account_id', $accountId)->where('remaining', '>', 0)
            ->with('member')->orderByDesc('id')->get()->mapWithKeys(fn ($row) => $line('package', $row));

        return $reservations->union($packages)->sortBy(fn ($name) => mb_strtolower($name))->all();
    }

    // "reservation-12" / "package-5" → the account's reservation / package (422 under settle_for)
    public function payable($accountId, string $key): Payable
    {
        [$kind, $id] = array_pad(explode('-', $key, 2), 2, 0);
        if (!isset(PaymentService::PAYABLES[$kind])) {
            throw ValidationException::withMessages(['settle_for' => 'Choose the reservation or package to settle.']);
        }
        $payable = PaymentService::PAYABLES[$kind]::where('account_id', $accountId)->find((int) $id);
        if (!$payable || !$payable->canTakePayments()) {
            throw ValidationException::withMessages(['settle_for' => 'This reservation / package can\'t be settled (not found, or inside a package).']);
        }
        return $payable;
    }

    // what the reservation / package still owes, fresh from its payments (the row locked); + $except = an amount already on it
    private function owed(Payable $payable, float $except = 0): float
    {
        $payable->newQuery()->whereKey($payable->getKey())->lockForUpdate()->first();
        return round($payable->payableAmount() - Payment::paidTotal($payable->payments()) + $except, 2);
    }

    private function assertOwed(Payable $payable, float $amount, float $except = 0): void
    {
        $owed = $this->owed($payable, $except);
        if ($amount > $owed) {
            throw ValidationException::withMessages(['amount' => $owed > 0
                ? 'The amount can\'t be more than what it still owes: ' . number_format($owed, 2) . '.'
                : 'Nothing is owed on this reservation / package.']);
        }
    }

    // the Cash In of a NEW settled Cash Out (after the Cash Out is saved): same amount / date / recorder (→ the same open shift)
    public function attach(Expense $expense, Payable $payable, Model $creator): Payment
    {
        $this->assertOwed($payable, (float) $expense->amount);
        $payment = $this->paymentService->create($expense->account_id, [
            'payable' => $payable instanceof Package ? 'package' : 'reservation',
            'payable_id' => $payable->getKey(),
            'type' => 'payment',
            'amount' => $expense->amount,
            'payment_method_id' => $expense->payment_method_id,
            'paid_at' => $expense->spent_at?->format('Y-m-d'),
            'notes' => $this->note($expense),
        ], $creator, true);
        $payment->forceFill(['offset_expense_id' => $expense->id])->save();
        $expense->forceFill(['offset_payment_id' => $payment->id])->save();
        return $payment;
    }

    // after an edit of a settled Cash Out: its Cash In follows (amount, date, shift), the reservation / package refreshed, logged
    public function sync(Expense $expense, Model $user, ?string $reason): void
    {
        $payment = Payment::find($expense->offset_payment_id);
        if (!$payment) {
            return;
        }
        $payable = $payment->load('payable')->payable;
        if ((float) $payment->amount !== (float) $expense->amount && $payable instanceof Payable) {
            $this->assertOwed($payable, (float) $expense->amount, (float) $payment->amount);
        }
        $before = $this->paymentService->snapshot($payment);
        $oldClosingId = (int) $payment->shift_closing_id;
        $payment->forceFill([
            'amount' => $expense->amount,
            'paid_at' => $expense->spent_at,
            'notes' => $this->note($expense),
            // an owner date change may have moved the Cash Out to another shift (ShiftClosingService::applyDateMove())
            'shift_closing_id' => (int) $expense->shift_closing_id,
        ])->save();
        if ($payable instanceof Payable) {
            $this->paymentService->refresh($payable instanceof Reservation ? $payable->load('status') : $payable);
        }
        $this->log($payment, 'payment_edit', $user, $reason, ['before' => $before, 'after' => $this->paymentService->snapshot($payment->fresh())], $oldClosingId);
    }

    // after the delete of a settled Cash Out: its Cash In too (soft, same reason), the reservation / package refreshed, logged
    public function remove(Expense $expense, string $reason, Model $user): void
    {
        $payment = Payment::find($expense->offset_payment_id);
        if (!$payment) {
            return;
        }
        $payment->update(['delete_reason' => $reason, 'deleted_by' => $user->getKey()]);
        $payment->delete();
        $payable = $payment->load('payable')->payable;
        if ($payable instanceof Payable) {
            $this->paymentService->refresh($payable instanceof Reservation ? $payable->load('status') : $payable);
        }
        $this->log($payment, 'payment_delete', $user, $reason, ['before' => $this->paymentService->snapshot($payment), 'after' => null]);
    }

    // a closed shift: totals recalculated + logged (also the one it left after a date move); an open one: logged
    private function log(Payment $payment, string $action, Model $user, ?string $reason, array $changes, ?int $oldClosingId = null): void
    {
        $closing = $payment->shift_closing_id ? \Modules\PaymentModule\app\Models\ShiftClosing::find($payment->shift_closing_id) : null;
        $closing ? $this->shiftService->paymentChanged($closing, $action, $payment, $user, $reason ?: 'Settled Cash Out changed', $changes)
            : $this->shiftService->logOpenChange($action, $payment, $user, $reason, $changes);
        if ($oldClosingId && $oldClosingId !== (int) $payment->shift_closing_id && ($old = \Modules\PaymentModule\app\Models\ShiftClosing::find($oldClosingId))) {
            $this->shiftService->recalculate($old);
        }
    }

    private function note(Expense $expense): string
    {
        return 'Offset · Cash Out #' . $expense->expense_no . ': ' . $expense->item;
    }
}
