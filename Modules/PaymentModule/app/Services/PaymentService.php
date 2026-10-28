<?php

namespace Modules\PaymentModule\app\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\AccountModule\app\Models\Account;
use Modules\MemberModule\app\Models\Member;
use Modules\PackageModule\app\Models\Package;
use Modules\PaymentModule\app\Contracts\Payable;
use Modules\PaymentModule\app\Models\IncomeCategory;
use Modules\PaymentModule\app\Models\Payment;
use Modules\PaymentModule\app\Models\PaymentMethod;
use Modules\PaymentModule\app\Models\ShiftClosing;
use Modules\PaymentModule\app\Repositories\PaymentRepository;
use Modules\ReservationModule\app\Models\Reservation;

/**
 * Payments of an account: every method takes the owner account id, so an account can only reach its own payments.
 * - amount always positive, type gives the sign (Payment::PAID_SQL); paid_amount / remaining of the payable kept by refresh()
 * - ONE closing level, the shift (docs/payments-plan.md §2b, 2026-10-13): no day lock; a payment of a CLOSED shift can still be
 *   edited / deleted by the account with a reason (ShiftClosingService::paymentChanged(): totals recalculated, logged, marked);
 *   refunds by any login (reason, not more than that payment's amount - its refunds), into the refunder's open shift
 * - payment_no: per account, MAX + 1 inside the transaction (locked), from config paymentmodule.number_start
 * The rules errors are thrown as ValidationException (422, shown under the fields by custom.js).
 * The service checks the account ownership itself (method, category, member, payable): it is also called by imports / invoices.
 */
class PaymentService
{
    // the payable kinds of the payment form => their models
    const PAYABLES = Payment::SOURCES;

    /**
     * Settings › General "Withholding tax" of the account (default the logged in user's account): OFF = no new withholding
     * (type / filter options, certificates page hidden); existing withholding records stay and are shown.
     */
    public static function withholdingOn($accountId = null): bool
    {
        $accountId ??= Auth::user()?->userable?->ownerAccountId();
        return $accountId ? (Account::settingsOf($accountId)?->withholdingEnabled() ?? true) : true;
    }

    private $paymentRepository;

    public function __construct(PaymentRepository $paymentRepository)
    {
        $this->paymentRepository = $paymentRepository;
    }

    // list query (paged / ordered by DataTables)
    public function listQuery($accountId, array $filters = [])
    {
        return $this->paymentRepository->forAccount($accountId)->filter($filters);
    }

    /**
     * The summary line of the payments list for a filtered query: the payments by method, refunds, withholding.
     * total = payments − refunds (cash); withholding apart (not cash). Methods in the account's order, only those used.
     * ['total' => float, 'methods' => [name => amount], 'refunds' => float, 'withholding' => float]
     */
    public function listSummary($query): array
    {
        $rows = (clone $query)->reorder()->setEagerLoads([])->toBase()
            ->selectRaw('type, payment_method_id, SUM(amount) AS total')->groupBy('type', 'payment_method_id')->get();

        $payments = $rows->where('type', 'payment');
        $methods = PaymentMethod::withTrashed()->whereIn('id', $payments->pluck('payment_method_id'))->ordered()->get();
        $byMethod = $methods->mapWithKeys(fn ($method) => [$method->name => round((float) $payments->where('payment_method_id', $method->id)->sum('total'), 2)]);
        // a payment whose method is gone / unknown (imported data)
        $other = round((float) $payments->whereNotIn('payment_method_id', $methods->pluck('id'))->sum('total'), 2);
        if ($other) {
            $byMethod['Other'] = $other;
        }

        $refunds = round((float) $rows->where('type', 'refund')->sum('total'), 2);

        return [
            'total' => round((float) $payments->sum('total') - $refunds, 2),
            'methods' => $byMethod->all(),
            'refunds' => $refunds,
            'withholding' => round((float) $rows->where('type', 'withholding')->sum('total'), 2),
        ];
    }

    // 404 when the payment belongs to another account
    public function findOne($accountId, $id): Payment
    {
        return $this->paymentRepository->forAccount($accountId)->with('creatable.userable')->findOrFail($id);
    }

    // the member's reservations (not inside a package) / packages for the payment form:
    // [{id, name}] the ones still owed first, the name with what is left to pay
    public function payableOptions($accountId, string $kind, $memberId): array
    {
        $class = self::PAYABLES[$kind] ?? abort(404);
        $query = $class::where('account_id', $accountId)->where('member_id', (int) $memberId)
            ->orderByRaw('remaining > 0 DESC');

        if ($kind === 'reservation') {
            $query->where('package_id', 0)->with('space', 'unit', 'plan')->orderByDesc('start_at');
        } else {
            $query->orderByDesc('id');
        }

        return $query->get()->map(fn ($payable) => [
            'id' => $payable->id,
            'name' => $this->payableName($payable) . ' — Remaining ' . number_format($payable->remaining, 2),
        ])->all();
    }

    // For = Package of the Add Cash In form (no member chosen first): the account's packages, owed first then newest;
    // "#5 Package · period · Member — Remaining x" (the member is searchable too)
    public function packageOptions($accountId): array
    {
        return Package::where('account_id', $accountId)->with('member')
            ->orderByRaw('remaining > 0 DESC')->orderByDesc('id')->get()
            ->map(fn ($package) => [
                'id' => $package->id,
                'name' => $this->payableName($package) . ' · ' . ($package->member?->name ?? '-') . ' — Remaining ' . number_format($package->remaining, 2),
            ])->all();
    }

    // "#12 Space › Unit · 21-10-2026" / "#5 Package name · 01-10-2026 → 31-12-2026"; $withNumber false: without "#12 "
    // (the Cash In view / edit / refund / delete popups show the number as its own field, 2026-10-21)
    public function payableName(?Model $payable, bool $withNumber = true): string
    {
        $number = $withNumber && $payable ? '#' . $payable->getKey() . ' ' : '';
        if ($payable instanceof Reservation) {
            return $number . ($payable->space?->name ?? '-') . ' › ' . ($payable->unit?->name ?? '-') . ' · ' . $payable->period;
        }
        if ($payable instanceof Package) {
            return $number . $payable->name . ' · ' . $payable->period;
        }

        return '-';
    }

    // the account owner (edit / delete / refund); employees only add payments
    public function isOwner(Model $user): bool
    {
        return $user->userable instanceof Account;
    }

    // the reservation / package of the account (404 otherwise); $kind: reservation | package
    public function findPayable($accountId, string $kind, $id): Payable
    {
        $class = self::PAYABLES[$kind] ?? abort(404);
        return $class::where('account_id', $accountId)->findOrFail($id);
    }

    /**
     * A payment (type payment / withholding; refunds: refund()).
     * $data: payable (reservation | package | empty = other income), payable_id, income_category_id, member_id (other income),
     *        type, amount, payment_method_id, paid_at, reference, notes
     */
    public function create($accountId, array $data, Model $creator): Payment
    {
        $type = $data['type'] ?? 'payment';
        if (!in_array($type, ['payment', 'withholding'], true)) {
            throw ValidationException::withMessages(['type' => 'Only a cash in or a withholding can be added here.']);
        }
        if ($type === 'withholding' && !self::withholdingOn($accountId)) {
            throw ValidationException::withMessages(['type' => 'Withholding tax is turned off (Settings › General).']);
        }

        $payable = !empty($data['payable']) ? $this->findPayable($accountId, $data['payable'], $data['payable_id'] ?? 0) : null;
        if ($payable && !$payable->canTakePayments()) {
            throw ValidationException::withMessages(['payable_id' => 'This reservation is inside a package, add the cash in to the package.']);
        }
        if (!$payable) {
            $this->assertCategory($accountId, $data['income_category_id'] ?? 0);
        }
        if (!$payable && !empty($data['member_id']) && !Member::where('account_id', $accountId)->whereKey((int) $data['member_id'])->exists()) {
            throw ValidationException::withMessages(['member_id' => 'This member is not one of yours.']);
        }

        $amount = $this->assertAmount($data['amount'] ?? 0);
        if ($type === 'payment') {
            $this->assertMethod($accountId, $data['payment_method_id'] ?? 0);
        }

        $paidAt = $this->recordDate($accountId, $data['paid_at'] ?? null, $creator);

        return DB::transaction(function () use ($accountId, $data, $creator, $type, $payable, $amount, $paidAt) {
            // the number first: its lock (nextNumber()) keeps two saves of the same reference apart
            $number = $this->nextNumber($accountId);
            $this->assertReference($accountId, $type === 'payment' ? (int) $data['payment_method_id'] : 0, $data['reference'] ?? null);
            // a reservation: never more than what it still owes (cash in or withholding); a package may be paid
            // beyond its due (the rest = the member's credit, user 2026-10-21)
            if ($payable instanceof Reservation) {
                $this->assertNotMoreThanOwed($payable, $amount);
            }
            $payment = new Payment([
                'account_id' => $accountId,
                'payment_no' => $number,
                'payable_type' => $payable ? $payable->getMorphClass() : null,
                'payable_id' => $payable ? $payable->getKey() : 0,
                // other income only (a reservation / package gives its member)
                'income_category_id' => $payable ? 0 : (int) $data['income_category_id'],
                'member_id' => $payable ? $payable->payableMemberId() : (int) ($data['member_id'] ?? 0),
                'type' => $type,
                'amount' => $amount,
                // withholding: no money received (a certificate instead)
                'payment_method_id' => $type === 'withholding' ? 0 : (int) $data['payment_method_id'],
                'paid_at' => $paidAt,
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
            $payment->creatable()->associate($creator);
            $payment->save();

            if ($payable) {
                $this->refresh($payable);
            }

            return $payment;
        });
    }

    /**
     * The owner's edit: amount, date, method, income category (other income), reference, notes.
     * The payable / member / type are kept (a payment for another reservation = delete it and add a new one).
     * In a CLOSED shift: $data['change_reason'] required; the shift's totals are recalculated and the change logged (before / after).
     */
    /**
     * Who may edit a payment (2026-10-28): the owner any (a closed shift: with a reason); an employee only their OWN cash in /
     * withholding while it is still in their OPEN shift (not a refund, not after closing). editError() = why not.
     */
    public function canEdit(Payment $payment, Model $user): bool
    {
        return $this->editError($payment, $user) === null;
    }

    public function editError(Payment $payment, Model $user): ?string
    {
        if ($this->isOwner($user)) {
            return null;
        }
        if ($payment->trashed() || $payment->type === 'refund') {
            return 'Only the account owner can change a refund.';
        }
        if ($payment->creatable_type !== $user->getMorphClass() || (int) $payment->creatable_id !== (int) $user->getKey()) {
            return 'You can change only the cash in you recorded.';
        }
        if ($payment->shift_closing_id) {
            return 'This cash in is in a closed shift: only the account owner can change it.';
        }
        return null;
    }

    // $user: the owner, or an employee for their own cash in of their open shift (canEdit(); the date stays as recorded)
    public function update($accountId, $id, array $data, Model $user): Payment
    {
        $payment = $this->findOne($accountId, $id);
        if ($error = $this->editError($payment, $user)) {
            abort(403, $error);
        }
        $owner = $this->isOwner($user);
        if (!$owner) {
            $data['paid_at'] = $payment->paid_at;
        }

        $amount = $this->assertAmount($data['amount'] ?? 0);
        if (!$owner) {
            $this->assertEmployeeAmount($payment, $amount);
        }
        // withholding: no money received, no method
        if ($payment->type !== 'withholding') {
            $this->assertMethod($accountId, $data['payment_method_id'] ?? 0);
        }
        if (!$payment->payable_type) {
            $this->assertCategory($accountId, $data['income_category_id'] ?? 0);
        }
        $paidAt = Carbon::parse($data['paid_at'] ?? $payment->paid_at)->startOfDay();
        // Reference no.: checked when the method or the reference changes (old rows without one stay as they are)
        $methodId = $payment->type === 'withholding' ? 0 : (int) $data['payment_method_id'];
        $reference = trim((string) ($data['reference'] ?? ''));
        if ($methodId !== (int) $payment->payment_method_id || ($reference !== '' && $reference !== (string) $payment->reference)) {
            $this->assertReference($accountId, $methodId, $reference, $payment->getKey());
        }
        $closing = $this->closedShiftOf($payment);
        $reason = $closing ? $this->assertReason($data['change_reason'] ?? '', 'change_reason', 'Write why this payment of a closed shift is changed.') : null;
        // a refund: still not more than its payment's amount (linked), or the money received of its reservation / package (imported)
        if ($payment->type === 'refund' && ($payment->refund_of || $payment->payable_type)) {
            $this->assertRefundable($payment->refund_of ? Payment::withTrashed()->find($payment->refund_of) ?? $payment : $payment, $amount, $payment);
        }

        return DB::transaction(function () use ($payment, $data, $amount, $paidAt, $closing, $reason, $user) {
            $before = $this->snapshot($payment);
            $payment->update([
                'amount' => $amount,
                'paid_at' => $paidAt,
                'payment_method_id' => $payment->type === 'withholding' ? 0 : (int) $data['payment_method_id'],
                'income_category_id' => $payment->payable_type ? 0 : (int) $data['income_category_id'],
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
            $this->refreshPayableOf($payment);
            // the audit log: every edit (2026-10-27), a closed shift also gets its totals recalculated
            $changes = ['before' => $before, 'after' => $this->snapshot($payment)];
            $closing ? $this->shifts()->paymentChanged($closing, 'payment_edit', $payment, $user, $reason, $changes)
                : $this->shifts()->logOpenChange('payment_edit', $payment, $user, trim((string) ($data['change_reason'] ?? '')) ?: null, $changes);

            return $payment;
        });
    }

    // the owner's delete: soft delete with the reason and who deleted it (kept for the audit report); in a closed shift:
    // its totals recalculated without it + logged
    public function delete($accountId, $id, string $reason, Model $user): Payment
    {
        $this->assertOwner($user);
        $payment = $this->findOne($accountId, $id);
        $reason = $this->assertReason($reason, 'delete_reason', 'Write why this payment is deleted.');
        $closing = $this->closedShiftOf($payment);

        return DB::transaction(function () use ($payment, $reason, $user, $closing) {
            $payment->update(['delete_reason' => $reason, 'deleted_by' => $user->getKey()]);
            $payment->delete();
            $this->refreshPayableOf($payment);
            $changes = ['before' => $this->snapshot($payment), 'after' => null];
            $closing ? $this->shifts()->paymentChanged($closing, 'payment_delete', $payment, $user, $reason, $changes)
                : $this->shifts()->logOpenChange('payment_delete', $payment, $user, $reason, $changes);

            return $payment;
        });
    }

    /**
     * A refund of a payment, by the account OR an employee (2026-10-13): a new refund record (positive amount, type refund,
     * refund_of = the payment) for the same payable / income, paid back with a method, created by the refunder → it joins THEIR
     * open shift (the cash leaves their drawer). Reason required. Not more than this payment's amount - its earlier refunds.
     * The payment in a closed shift: that shift gets the log entry + "Edited after closing" only (its totals don't change).
     * $data: amount, payment_method_id, paid_at, reason, reference, notes
     */
    public function refund($accountId, $id, array $data, Model $user): Payment
    {
        $payment = $this->findOne($accountId, $id);
        if ($payment->type !== 'payment') {
            throw ValidationException::withMessages(['amount' => 'Only a payment can be refunded (not a refund or a withholding).']);
        }

        $amount = $this->assertAmount($data['amount'] ?? 0);
        $this->assertMethod($accountId, $data['payment_method_id'] ?? 0);
        $reason = $this->assertReason($data['reason'] ?? '', 'reason', 'Write why the money is given back.');
        $this->assertRefundable($payment, $amount);
        $paidAt = $this->recordDate($accountId, $data['paid_at'] ?? null, $user);
        $closing = $this->closedShiftOf($payment);

        return DB::transaction(function () use ($accountId, $payment, $data, $user, $amount, $paidAt, $reason, $closing) {
            // the number first (its lock); a method that needs a reference: the real one (the transfer no.), not "Refund of #N"
            $number = $this->nextNumber($accountId);
            $this->assertReference($accountId, (int) $data['payment_method_id'], $data['reference'] ?? null);
            $refund = new Payment([
                'account_id' => $accountId,
                'payment_no' => $number,
                'payable_type' => $payment->payable_type,
                'payable_id' => $payment->payable_id,
                'income_category_id' => $payment->income_category_id,
                'member_id' => $payment->member_id,
                'type' => 'refund',
                'amount' => $amount,
                'payment_method_id' => (int) $data['payment_method_id'],
                'refund_of' => $payment->getKey(),
                'paid_at' => $paidAt,
                'reference' => ($data['reference'] ?? null) ?: 'Refund of #' . $payment->payment_no,
                'notes' => $data['notes'] ?? null,
                'reason' => $reason,
            ]);
            $refund->creatable()->associate($user);
            $refund->save();
            $this->refreshPayableOf($refund);
            if ($closing) {
                $this->shifts()->paymentChanged($closing, 'payment_refund', $payment, $user, $reason, ['before' => $this->snapshot($payment), 'after' => $this->snapshot($refund)]);
            }

            return $refund;
        });
    }

    /**
     * The most that can be refunded now (the refund form); $except: a refund being edited (its amount is not counted).
     * A payment: its amount - its refunds (refund_of). An imported refund with no link (edited): the money received of its
     * reservation / package (payments - refunds), or its own amount for other income.
     */
    public function refundable(Payment $payment, ?Payment $except = null): float
    {
        if ($payment->type === 'payment') {
            $refunded = Payment::where('refund_of', $payment->getKey())
                ->when($except, fn ($query) => $query->whereKeyNot($except->getKey()))->sum('amount');
            return round($payment->amount - (float) $refunded, 2);
        }

        $query = $payment->payable_type
            ? Payment::where('account_id', $payment->account_id)->whereIn('type', ['payment', 'refund'])
                ->where('payable_type', $payment->payable_type)->where('payable_id', $payment->payable_id)
                ->when($except, fn ($query) => $query->whereKeyNot($except->getKey()))
            : null;

        return round($query ? Payment::paidTotal($query) : $payment->amount, 2);
    }

    /**
     * A reservation moved into a package is paid through the package: its payments (all types, the deleted ones too)
     * move to the package; their amount, date, method and number don't change, so a closed day's totals stay right.
     * Both are refreshed. Returns the number of payments moved.
     */
    public function movePayments(Payable $from, Payable $to): int
    {
        $payments = Payment::withTrashed()->where('payable_type', $from->getMorphClass())->where('payable_id', $from->getKey())->get();
        $moved = Payment::withTrashed()->whereKey($payments->modelKeys())
            ->update(['payable_type' => $to->getMorphClass(), 'payable_id' => $to->getKey()]);
        // the audit log (2026-10-27): one payment_move per payment (from → to), by the login that changed the reservation
        if ($by = Auth::user()) {
            foreach ($payments as $payment) {
                $this->shifts()->logOpenChange('payment_move', $payment, $by, null, [
                    'before' => ['payable_type' => class_basename($from), 'payable_id' => $from->getKey()],
                    'after' => ['payable_type' => class_basename($to), 'payable_id' => $to->getKey()],
                ]);
            }
        }

        $this->refresh($from instanceof Reservation ? $from->load('status') : $from);
        $this->refresh($to);

        return $moved;
    }

    /**
     * paid_amount / remaining of a reservation / package from its payments (remaining < 0 = member credit).
     * Not payable on its own (a reservation inside a package): due = 0.
     * $paid: the payments total when already known (refreshAll()), null = calculated here.
     */
    public function refresh(Payable $payable, ?float $paid = null): void
    {
        $paid = round($paid ?? Payment::paidTotal($payable->payments()), 2);
        $due = $payable->canTakePayments() ? $payable->payableAmount() : 0;
        $values = ['paid_amount' => $paid, 'remaining' => round($due - $paid, 2)];

        if (round((float) $payable->paid_amount, 2) === $values['paid_amount'] && round((float) $payable->remaining, 2) === $values['remaining']) {
            return;
        }

        // only these two columns: no updated_at, no model events
        $payable->forceFill($values);
        $payable->newQueryWithoutScopes()->whereKey($payable->getKey())->toBase()->update($values);
    }

    // refresh() of every reservation / package (php artisan payments:refresh); the number of rows changed
    public function refreshAll($accountId = null): int
    {
        $changed = 0;
        foreach (self::PAYABLES as $class) {
            $model = new $class;
            // the payments totals of all of them in one query: [payable_id => paid]
            $paid = Payment::where('payable_type', $model->getMorphClass())
                ->when($accountId, fn ($query) => $query->where('account_id', $accountId))
                ->groupBy('payable_id')
                ->selectRaw('payable_id, SUM(' . Payment::PAID_SQL . ') AS paid')
                ->pluck('paid', 'payable_id');

            $class::query()->when($accountId, fn ($query) => $query->where('account_id', $accountId))
                // the reservation status / the package extras give their due (payableAmount())
                ->when($class === Reservation::class, fn ($query) => $query->with('status'))
                ->when($class === Package::class, fn ($query) => $query->withSum('extras', 'amount'))
                ->chunkById(500, function ($payables) use ($paid, &$changed) {
                    foreach ($payables as $payable) {
                        $before = [$payable->paid_amount, $payable->remaining];
                        $this->refresh($payable, (float) ($paid[$payable->getKey()] ?? 0));
                        $changed += $before !== [$payable->paid_amount, $payable->remaining] ? 1 : 0;
                    }
                });
        }

        return $changed;
    }

    private function assertOwner(Model $user): void
    {
        if (!$this->isOwner($user)) {
            abort(403, 'Only the account owner can change, delete or refund payments.');
        }
    }

    /**
     * A NEW cash in / withholding of a reservation (2026-10-21; packages not checked: paid beyond the due = credit): not more than it still owes (due - paid, fresh from
     * its payments, the row locked so two cash ins at once can't both pass). Only create(): saved payments (edits) and the
     * imported data (to_run inserts directly) are not checked. Inside DB::transaction().
     */
    private function assertNotMoreThanOwed(Payable $payable, float $amount): void
    {
        $payable->newQuery()->whereKey($payable->getKey())->lockForUpdate()->first();
        $owed = round($payable->payableAmount() - Payment::paidTotal($payable->payments()), 2);
        if ($amount <= $owed) {
            return;
        }
        $what = $payable instanceof Package ? 'package' : 'reservation';
        throw ValidationException::withMessages(['amount' => $owed > 0
            ? 'The amount can\'t be more than what this ' . $what . ' still owes: ' . number_format($owed, 2) . '.'
            : 'Nothing is owed on this ' . $what . ' (fully paid).']);
    }

    // the amount (> 0, 2 decimals)
    private function assertAmount($amount): float
    {
        $amount = round((float) $amount, 2);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'The amount must be more than 0.']);
        }
        return $amount;
    }

    /**
     * The date of a new cash in / refund (2026-10-27, fraud prevention): the business date of the recording time for everyone
     * but the account owner, who may give another date (reports only: the row always joins the recorder's open shift).
     */
    public function recordDate($accountId, $date, Model $user): Carbon
    {
        return $date && $this->isOwner($user)
            ? Carbon::parse($date)->startOfDay()
            : (Account::settingsOf($accountId)?->businessDate() ?? today())->copy();
    }

    /**
     * Reference no. (2026-10-27): a method with "needs reference" (every method but cash) → required, and unique per account +
     * method among the payments not deleted (cash in, refunds); $ignoreId = the payment being edited. Throws under "reference".
     */
    public function assertReference($accountId, int $methodId, ?string $reference, $ignoreId = null): void
    {
        $method = $methodId ? PaymentMethod::where('account_id', $accountId)->find($methodId) : null;
        if (!$method?->requires_reference) {
            return;
        }
        $reference = trim((string) $reference);
        if ($reference === '') {
            throw ValidationException::withMessages(['reference' => 'Write the reference no. of this ' . $method->name . ' payment.']);
        }
        $taken = Payment::where('account_id', $accountId)->where('payment_method_id', $methodId)->where('reference', $reference)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))->first(['id', 'payment_no', 'type']);
        if ($taken) {
            throw ValidationException::withMessages(['reference' => 'This ' . $method->name . ' reference is already used by '
                . ($taken->type === 'refund' ? 'refund' : 'cash in') . ' #' . $taken->payment_no . '.']);
        }
    }

    private function assertMethod($accountId, $methodId): void
    {
        if (empty($methodId)) {
            throw ValidationException::withMessages(['payment_method_id' => 'Choose the payment method.']);
        }
        if (!PaymentMethod::where('account_id', $accountId)->whereKey((int) $methodId)->exists()) {
            throw ValidationException::withMessages(['payment_method_id' => 'This payment method is not one of yours.']);
        }
    }

    private function assertCategory($accountId, $categoryId): void
    {
        if (empty($categoryId)) {
            throw ValidationException::withMessages(['income_category_id' => 'Choose the income category of this payment.']);
        }
        if (!IncomeCategory::where('account_id', $accountId)->whereKey((int) $categoryId)->exists()) {
            throw ValidationException::withMessages(['income_category_id' => 'This income category is not one of yours.']);
        }
    }

    private function assertRefundable(Payment $payment, float $amount, ?Payment $except = null): void
    {
        $max = $this->refundable($payment, $except);
        if ($amount > $max) {
            throw ValidationException::withMessages(['amount' => 'Not more than ' . number_format(max(0, $max), 2) . ' can be refunded (what was paid on this payment, less its refunds).']);
        }
    }

    // an employee's edit (2026-10-28): not below what was already refunded of it; a reservation's cash in not above what it
    // still owes with this payment's old amount (the "never more than owed" rule of a new cash in; packages may go to credit)
    private function assertEmployeeAmount(Payment $payment, float $amount): void
    {
        $refunded = round((float) Payment::where('refund_of', $payment->getKey())->sum('amount'), 2);
        if ($amount < $refunded) {
            throw ValidationException::withMessages(['amount' => 'Not less than ' . number_format($refunded, 2) . ' (already refunded of it).']);
        }
        $payable = $payment->load('payable')->payable;
        if ($payable instanceof Reservation && $amount > (float) $payment->amount) {
            $payable->newQuery()->whereKey($payable->getKey())->lockForUpdate()->first();
            $owed = round($payable->load('status')->payableAmount() - Payment::paidTotal($payable->payments()) + (float) $payment->amount, 2);
            if ($amount > $owed) {
                throw ValidationException::withMessages(['amount' => 'The amount can\'t be more than ' . number_format(max(0, $owed), 2) . ' (what this reservation owes with this cash in).']);
            }
        }
    }

    // the reservation / package of a payment after it changed (other income: nothing)
    private function refreshPayableOf(Payment $payment): void
    {
        $payable = $payment->load('payable')->payable;
        if ($payable instanceof Payable) {
            $this->refresh($payable instanceof Reservation ? $payable->load('status') : $payable);
        }
    }

    // the CLOSED shift a payment is in (null = an open shift): changing it needs a reason and is logged on that shift
    public function closedShiftOf(Payment $payment): ?ShiftClosing
    {
        return $payment->shift_closing_id ? ShiftClosing::find($payment->shift_closing_id) : null;
    }

    // a required reason, trimmed (422 under $field when empty)
    private function assertReason($reason, string $field, string $message): string
    {
        $reason = trim((string) $reason);
        if ($reason === '') {
            throw ValidationException::withMessages([$field => $message]);
        }
        return $reason;
    }

    // what the shift log keeps of a payment (before / after a change)
    private function snapshot(Payment $payment): array
    {
        return [
            'id' => $payment->getKey(),
            'payment_no' => $payment->payment_no,
            'type' => $payment->type,
            'amount' => (float) $payment->amount,
            'payment_method_id' => (int) $payment->payment_method_id,
            'method' => $payment->paymentMethod?->name,
            'income_category_id' => (int) $payment->income_category_id,
            'paid_at' => $payment->paid_at?->format('Y-m-d'),
            'reference' => $payment->reference,
            'notes' => $payment->notes,
        ];
    }

    // ShiftClosingService needs this service (constructor): resolved when used, not injected
    private function shifts(): ShiftClosingService
    {
        return app(ShiftClosingService::class);
    }

    // the next payment number of the account (call inside a transaction: the rows read are locked until it ends);
    // never below config paymentmodule.number_start (the numbers below it are the old imported payments)
    public function nextNumber($accountId): int
    {
        $last = (int) Payment::withTrashed()->where('account_id', $accountId)->lockForUpdate()->max('payment_no');
        return max($last + 1, (int) config('paymentmodule.number_start', 1));
    }
}
