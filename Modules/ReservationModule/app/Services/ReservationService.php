<?php

namespace Modules\ReservationModule\app\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\PackageModule\app\Models\Package;
use Modules\AccountModule\app\Models\Account;
use Modules\PackageModule\app\Services\PackageService;
use Modules\PaymentModule\app\Models\InvoiceRequestLog;
use Modules\PaymentModule\app\Services\PaymentService;
use Modules\PaymentModule\app\Services\Tax\TaxSnapshot;
use Modules\ReservationModule\app\Models\Reservation;
use Modules\ReservationModule\app\Models\ReservationEvent;
use Modules\ReservationModule\app\Models\ReservationStatus;
use Modules\ReservationModule\app\Repositories\ReservationRepository;
use Modules\PlanModule\app\Models\Plan;
use Modules\SpaceModule\app\Models\SubscriptionType;
use Modules\UnitModule\app\Models\Unit;

/**
 * Every method takes the owner account id (the account, or the account of the logged in employee).
 * The amounts are calculated here again (the form calculates them while typing), and the package totals are kept up to date,
 * so is the reservation's paid / remaining (PaymentService::refresh(): its net, or 0 inside a package / with a not counted status).
 */
class ReservationService
{
    private $reservationRepository;
    private $packageService;
    private $statusService;
    private $paymentService;

    public function __construct(ReservationRepository $reservationRepository, PackageService $packageService, ReservationStatusService $statusService, PaymentService $paymentService)
    {
        $this->reservationRepository = $reservationRepository;
        $this->packageService = $packageService;
        $this->statusService = $statusService;
        $this->paymentService = $paymentService;
    }

    // list query (paged / ordered by DataTables)
    public function listQuery($accountId, array $filters = [])
    {
        return $this->reservationRepository->forAccount($accountId)->filter($filters);
    }

    // 404 when the reservation belongs to another account
    public function findOne($accountId, $id)
    {
        return $this->reservationRepository->forAccount($accountId)->with('creatable.userable', 'repeat', 'repeats', 'unit.color')->findOrFail($id);
    }

    // $creator: the logged in user (account or employee) who makes the reservation
    // $data['renewal_of']: the period it renews (the Renew form), checked again here (renewalError())
    public function create($accountId, array $data, Model $creator)
    {
        return DB::transaction(function () use ($accountId, $data, $creator) {
            $renewalOf = !empty($data['renewal_of']) ? (int) $data['renewal_of'] : null;
            // a renewal keeps the renewed period's member, package and subscription type, never repeats (the Renew form shows them fixed)
            if ($renewalOf && ($period = Reservation::where('account_id', $accountId)->find($renewalOf))) {
                $data = array_merge($data, [
                    'member_id' => $period->member_id,
                    'package_id' => $period->package_id ?: null,
                    'subscription_type_id' => $period->subscription_type_id,
                    'is_repeat' => false,
                ]);
            }
            // pay first, then renew (user 2026-10-29): an unpaid period — an employee is refused, the owner gives a reason (a dated
            // line in the new period's notes)
            $unpaidNote = $renewalOf && isset($period) ? $this->unpaidRenewalNote($period, $data, $creator) : null;
            $unpaidDebt = $unpaidNote ? $period->renewalDebt() : 0;
            // what the member owes across their periods before this renewal (the event's total)
            $memberDebt = $unpaidNote ? round((float) Reservation::owingPeriodsOf($accountId, (int) $period->member_id)->sum('remaining'), 2) : 0;
            // the status is not chosen in the form: a new reservation gets the first active status
            $reservation = $this->reservationRepository->makeModel()->newInstance($this->reservationData($accountId, $data) + [
                'account_id' => $accountId,
                'reservation_status_id' => $this->statusService->defaultId($accountId),
                'renewal_of' => $renewalOf,
            ]);
            // a renewal carries the renewed period's anchor day (its start may be the 28th of an anchor 31), 2026-10-23
            if ($renewalOf && !empty($period?->subscription_day)) {
                $reservation->subscription_day = $period->subscription_day;
            }
            if ($renewalOf && ($error = $this->renewalError($accountId, $reservation, $renewalOf))) {
                throw ValidationException::withMessages(['renewal_of' => $error]);
            }
            if ($unpaidNote) {
                $reservation->notes = trim(($reservation->notes ? $reservation->notes . "\n" : '') . $unpaidNote);
            }
            // "Invoice requested" + the tax snapshot from the plan's current flags (the repeat copies replicate it)
            $this->applyTax($reservation, $accountId, $data, true, $renewalOf ? ($period ?? null) : null);
            $reservation->creatable()->associate($creator);
            $reservation->save();
            // the owner's override, for Payment Exceptions › Unpaid Renewals (2026-10-29)
            if ($unpaidNote) {
                $this->recordEvent($reservation, 'renewed_unpaid', trim((string) $data['unpaid_renewal_reason']), $unpaidDebt,
                    ['new_amount' => $reservation->dueAmount(), 'total' => round($memberDebt + $reservation->dueAmount(), 2)], $creator, $period->id);
            }

            // repeat: the copies until the "repeat until" date
            if ($reservation->is_repeat && !empty($data['repeat_until'])) {
                $this->createRepeats($reservation, Carbon::parse($data['repeat_until']));
            }

            $this->packageService->recalculate($reservation->package_id);
            // remaining = its net (the copies too)
            foreach ($reservation->repeats()->get()->prepend($reservation) as $saved) {
                $this->paymentService->refresh($saved);
            }
            return $reservation;
        });
    }

    // $by: the logged in user (the "Invoice requested" log)
    public function update($accountId, $id, array $data, ?Model $by = null)
    {
        $reservation = $this->findOne($accountId, $id);

        return DB::transaction(function () use ($reservation, $accountId, $data, $by) {
            $oldPackageId = $reservation->package_id;
            $oldPlanId = (int) $reservation->plan_id;
            $wasInvoice = (bool) $reservation->invoice_requested;
            // the repeat is chosen on create only, it is kept as it is; the anchor day (subscription_day) is kept as saved, the
            // account's edit form may change it ("Billing day", sent only then; 2026-10-23)
            $reservation->fill(Arr::except($this->reservationData($accountId, $data), ['is_repeat', 'repeat_frequency', 'repeat_interval', 'subscription_day']));
            if (!empty($data['subscription_day'])) {
                $reservation->subscription_day = (int) $data['subscription_day'];
            } elseif (!$reservation->subscription_day) {
                $reservation->subscription_day = $reservation->start_at->day;
            }
            // "Renewal of #" (the account's edit form only: the key is sent only then): set / cleared, checked like a Renew
            if (array_key_exists('renewal_of', $data)) {
                $reservation->renewal_of = !empty($data['renewal_of']) ? (int) $data['renewal_of'] : null;
                if ($reservation->renewal_of && ($error = $this->renewalError($accountId, $reservation, $reservation->renewal_of))) {
                    throw ValidationException::withMessages(['renewal_of' => $error]);
                }
            }
            // the stored tax type, the plan's current flags only when the plan changed
            $this->applyTax($reservation, $accountId, $data, (int) $reservation->plan_id !== $oldPlanId, null, $by);
            $reservation->save();
            if ($wasInvoice !== (bool) $reservation->invoice_requested) {
                $this->logInvoiceChange($reservation, $wasInvoice, $by);
            }

            // moved into a package (it was paid on its own): its payments go to the package (paid through it now)
            if (!$oldPackageId && $reservation->package_id && ($package = $reservation->load('package')->package)) {
                $this->paymentService->movePayments($reservation, $package);
            }

            // the old and the new package (it can be changed)
            $this->packageService->recalculate($oldPackageId);
            if ($reservation->package_id != $oldPackageId) {
                $this->packageService->recalculate($reservation->package_id);
            }
            // the net / status / package may have changed
            $this->paymentService->refresh($reservation->load('status'));
            return $reservation;
        });
    }

    /**
     * The status menu of the reservation view; the package is calculated again (a not counted status is not in its price).
     * Not counted → counted (cancelled → confirmed ...): the slot must be free again, the same check as a new booking; a
     * period without renewal_of is linked to the period it renews (renewalCandidate(), 2026-10-27: Heba Essam #22049 was
     * confirmed but stayed unlinked → its old period showed as Not renewed); the relation `linkedRenewal` tells the caller.
     * Counted → not counted with own payments (net paid > 0): refused ("Refund first"); the account owner may force it with
     * $forceReason (kept in the notes); employees can't ($user = who changes it; null = no force).
     */
    public function changeStatus($accountId, $id, $statusId, ?string $forceReason = null, ?Model $user = null)
    {
        $reservation = $this->findOne($accountId, $id);
        $status = ReservationStatus::where('account_id', $accountId)->find($statusId);
        $restoring = $status?->is_counted && !$reservation->isCounted();
        $cancelling = $status && !$status->is_counted && $reservation->isCounted();
        if ($restoring && ($error = $this->restoreError($reservation))) {
            throw ValidationException::withMessages(['html_status' => $error]);
        }
        // a cancelled renewal back to counted: its period must not have another counted renewal meanwhile
        if ($restoring && $reservation->renewal_of
            && ($other = $this->renewedByOther($reservation->renewal_of, $reservation->id))) {
            throw ValidationException::withMessages(['html_status' => 'The period #' . $reservation->renewal_of . ' is already renewed by '
                . $this->reservationLink($other) . '. Change that one first.']);
        }
        $note = $cancelling ? $this->cancelPaidNote($reservation, $status, $forceReason, $user) : null;
        // the owner's paid cancel, for Payment Exceptions › Paid Cancelled (2026-10-29): its net paid, the status before → after
        $cancelEvent = $note ? ['paid' => $reservation->paymentsTotal(), 'from' => $reservation->status?->name, 'to' => $status->name] : null;
        // never overwrites a link
        $link = $restoring && !$reservation->renewal_of ? $this->renewalCandidate($reservation) : null;

        return DB::transaction(function () use ($reservation, $statusId, $note, $link, $cancelEvent, $forceReason, $user) {
            $reservation->update(array_filter([
                'reservation_status_id' => $statusId,
                'renewal_of' => $link?->id,
                'notes' => $note !== null ? trim($reservation->notes . "\n" . $note) : null,
            ], fn ($value) => $value !== null));
            if ($cancelEvent) {
                $this->recordEvent($reservation, 'cancelled_paid', trim((string) $forceReason), $cancelEvent['paid'],
                    ['from' => $cancelEvent['from'], 'to' => $cancelEvent['to']], $user);
            }
            // for the answer: "Linked as renewal of #…" (a relation: never saved)
            $reservation->setRelation('linkedRenewal', $link);
            $this->packageService->recalculate($reservation->package_id);
            // a not counted status (cancelled ...): nothing is due
            $this->paymentService->refresh($reservation->load('status'));
            return $reservation;
        });
    }

    /**
     * Restore a not counted reservation (cancelled, pending ...) to the default counted status (Confirmed):
     * refused when its slot is taken now (the reservations holding it, as links); then like changeStatus()
     * (package recalculated, paid / remaining refreshed: the amount due comes back).
     */
    public function restore($accountId, $id)
    {
        $reservation = $this->findOne($accountId, $id);
        if ($reservation->isCounted()) {
            throw ValidationException::withMessages(['html_status' => 'This reservation is already active.']);
        }
        $status = $this->restoreStatus($accountId)
            ?? throw ValidationException::withMessages(['html_status' => 'There is no active counted status to restore to.']);

        return $this->changeStatus($accountId, $id, $status->id);
    }

    // the status a reservation is restored to: the default one when it is counted, else the first active counted one
    public function restoreStatus($accountId): ?ReservationStatus
    {
        $statuses = ReservationStatus::where('account_id', $accountId)->where('is_active', true)->where('is_counted', true);
        return (clone $statuses)->where('is_default', true)->first() ?? $statuses->ordered()->first();
    }

    // the slot of a not counted reservation is free for it again (null) / taken: the reason (HTML, links to the holders)
    public function restoreError(Reservation $reservation): ?string
    {
        $unit = Unit::find($reservation->unit_id);
        return $unit ? $this->periodsError($unit, [[$reservation->start_at, $reservation->end_at]], $reservation->id) : null;
    }

    /**
     * "Invoice requested" + the tax snapshot (docs/payments-plan.md §3; TaxSnapshot): inside a package = the package's toggle
     * (its taxes are the package's, Phase C: no amounts here); else the form's checkbox; a new row without it (taxes not
     * started: no checkbox) = the renewed period's / the plan's "Invoice by default". $freshType: the plan's CURRENT flags
     * (new, Renew, plan changed), else the stored type. Then what it costs = tax_total (dueAmount()).
     * A back-dated row (created before the start date was set, Account::isBackdated()): only the owner's checkbox counts
     * ($by), an employee's edit keeps the stored value.
     */
    private function applyTax(Reservation $reservation, $accountId, array $data, bool $freshType, ?Reservation $renewed = null, ?Model $by = null): void
    {
        $plan = Plan::find($reservation->plan_id);
        $account = Account::settingsOf($accountId);
        if ($reservation->exists && $account?->isBackdated($reservation->created_at) && !($by && $this->paymentService->isOwner($by))) {
            unset($data['invoice_requested']);
        }
        if ($reservation->package_id) {
            $reservation->invoice_requested = (bool) Package::find($reservation->package_id)?->invoice_requested;
        } elseif (array_key_exists('invoice_requested', $data)) {
            $reservation->invoice_requested = !empty($data['invoice_requested']);
        } elseif (!$reservation->exists) {
            $reservation->invoice_requested = $renewed ? (bool) $renewed->invoice_requested : (bool) $plan?->invoice_by_default;
        }

        if ($account && $plan) {
            app(TaxSnapshot::class)->fill($reservation, $account, $plan, $freshType, (float) $reservation->amount,
                (float) $reservation->discount_value, !$reservation->package_id);
        }
    }

    /**
     * The double-tax guard (2026-10-28): the member's VAT paid by hand — other-income cash ins in a category not counted as
     * income ("VAT (manual)") dated in the period ± 7 days; [[id, no, date, amount]] (newest first, at most 20).
     */
    public function manualVatPayments($accountId, $memberId, Carbon $start, Carbon $end): array
    {
        if (!$memberId) {
            return [];
        }
        $categories = \Modules\PaymentModule\app\Models\IncomeCategory::where('account_id', $accountId)->where('is_revenue', false)->pluck('id');

        return \Modules\PaymentModule\app\Models\Payment::where('account_id', $accountId)->where('member_id', $memberId)
            ->where('type', 'payment')->where(fn ($query) => $query->whereNull('payable_type')->orWhere('payable_id', 0))
            ->whereIn('income_category_id', $categories)
            ->whereBetween('paid_at', [$start->copy()->subDays(7)->toDateString(), $end->copy()->addDays(7)->toDateString()])
            ->orderByDesc('paid_at')->orderByDesc('id')->limit(20)->get()
            ->map(fn ($payment) => ['id' => $payment->id, 'no' => $payment->payment_no, 'date' => $payment->paid_at->format(Reservation::DATE_FORMAT),
                'amount' => number_format((float) $payment->amount, 2)])->all();
    }

    /**
     * Renewing a period that still owes (2026-10-29, revised the same day: the owner AND employees): null when it is paid; else the
     * reason is required (unpaid_renewal_reason) and the notes line "Renewed before #X was paid (it owed Y) by …" is returned.
     */
    private function unpaidRenewalNote(Reservation $period, array $data, Model $creator): ?string
    {
        $debt = $period->renewalDebt();
        if ($debt <= 0) {
            return null;
        }
        $reason = trim((string) ($data['unpaid_renewal_reason'] ?? ''));
        if (mb_strlen($reason) < 3) {
            throw ValidationException::withMessages(['unpaid_renewal_reason' => 'Write why this period is renewed before it is paid.']);
        }

        return 'Renewed before #' . $period->id . ' was paid (it owed ' . number_format($debt, 2) . ') by '
            . ($creator->userable?->displayName() ?? 'the account') . ' on ' . now()->format('d-m-Y h:i A') . ': ' . $reason;
    }

    // a logged exception (reservation_events: Payment Exceptions › Unpaid Renewals / Paid Cancelled, 2026-10-29)
    private function recordEvent(Reservation $reservation, string $type, ?string $reason, float $amount, array $data, ?Model $by, int $relatedId = 0): void
    {
        $event = new ReservationEvent([
            'account_id' => $reservation->account_id, 'reservation_id' => $reservation->id, 'related_reservation_id' => $relatedId,
            'type' => $type, 'reason' => $reason, 'amount' => round($amount, 2), 'data' => $data,
        ]);
        if ($by) {
            $event->creatable()->associate($by);
        }
        $event->save();
    }

    // a change of "Invoice requested" (Payment Exceptions › Invoice Toggled = changed after a payment)
    private function logInvoiceChange(Reservation $reservation, bool $from, ?Model $by): void
    {
        $log = new InvoiceRequestLog([
            'account_id' => $reservation->account_id, 'from_value' => $from, 'to_value' => (bool) $reservation->invoice_requested,
            'paid_amount' => (float) $reservation->paid_amount,
        ]);
        $log->loggable()->associate($reservation);
        if ($by) {
            $log->creatable()->associate($by);
        }
        $log->save();
    }

    /**
     * The next period of a subscription (the "Renew" form, not saved): same member, package, type, space, unit, plan,
     * amounts; no notes; renewal_of = the subscription. A date plan: its last day = the DATE of its end (an imported end at 00:00
     * is that day, an app end is 23:59:59 of it — the next period of the imported data starts the day after in 501 / 544 cases,
     * 2026-10-14), so it starts the day after, no overlap; its end follows the plan's lease period and the carried anchor day
     * (a month / year plan, Reservation::anchoredEnd(), 2026-10-23: 31-01 → 28-02 → 31-03 …; a multi-year period renews for
     * 1 year), else the same number of days. A time based plan: a period type with a month / year plan = the same dates, with
     * the current period's start / end times; otherwise it starts when the current one ends, for the same length.
     * replicate() keeps the subscription_day.
     */
    public function renewalOf(Reservation $subscription): Reservation
    {
        $end = $subscription->realEnd() ?? $subscription->start_at;
        $timeBased = (bool) $subscription->plan?->is_time_based;
        $anchoredEnd = !$timeBased || $subscription->isPeriod()
            ? Reservation::anchoredEnd($subscription->renewalDate(), $subscription->subscription_day, $subscription->plan?->lease_period)
            : null;
        if ($timeBased && !$anchoredEnd) {
            $start = $end->copy();
            $length = $subscription->start_at->diffInSeconds($end);
            $newEnd = $length > 0 ? $start->copy()->addSeconds($length) : $start->copy();
        } else {
            $start = $subscription->renewalDate();
            $newEnd = $anchoredEnd
                ?? $start->copy()->addDays((int) $subscription->start_at->copy()->startOfDay()->diffInDays($end->copy()->startOfDay()))->endOfDay();
            if ($timeBased) {
                $start->setTimeFrom($subscription->start_at);
                $newEnd->setTimeFrom($end);
            }
        }

        // the same package (the Renew form does not change it)
        $renewal = $subscription->replicate(['paid_amount', 'remaining', 'repeat_id', 'notes', 'left_at', 'left_by']);
        $renewal->forceFill([
            'start_at' => $start,
            'end_at' => $newEnd,
            'notes' => null,
            'renewal_of' => $subscription->id,
        ]);

        return $renewal;
    }

    /**
     * A new reservation pre-filled from the Schedule page (not saved): ?subscription_type_id / space_id / unit_id (only the
     * account's, a unit of that type) / start (Y-m-d H:i, or a date: the time = now). null when nothing is given.
     * The form's own checks run on save.
     */
    /**
     * Every choice of the form's place chain at once (2026-10-18; replaces the spaces / units / plans requests): the account's
     * active spaces per subscription type (spaces order), the active units per "type-space" (units order) with their capacity,
     * the active plans per unit (cheapest first) with what the form needs (amount, time based, period hours, lease period).
     * custom.js filters them in the browser ([data-place-tree] + select[data-place]); the server checks the place, availability
     * and amounts again on save. 4 queries.
     * ['spaces' => [typeId => [[id, name]]], 'units' => ["typeId-spaceId" => [[id, name, capacity]]], 'plans' => [unitId => [[id, name, amount, timeBased, periodHours, leasePeriod]]]]
     */
    public function placeTree($accountId): array
    {
        $spaces = \Modules\SpaceModule\app\Models\Space::where('account_id', $accountId)->where('is_active', true)->ordered()
            ->with('subscriptionTypes:id')->get();
        $units = Unit::where('account_id', $accountId)->where('is_active', true)->whereIn('space_id', $spaces->pluck('id'))->ordered()
            ->with(['plans' => fn ($query) => $query->where('plans.is_active', true)->orderBy('amount')->orderBy('name')])->get();

        $tree = ['spaces' => [], 'units' => [], 'plans' => []];
        foreach ($spaces as $space) {
            foreach ($space->subscriptionTypes as $type) {
                $tree['spaces'][$type->id][] = ['id' => $space->id, 'name' => \Illuminate\Support\Str::humanize($space->name)];
            }
        }
        foreach ($units as $unit) {
            $tree['units'][$unit->subscription_type_id . '-' . $unit->space_id][] = ['id' => $unit->id, 'name' => \Illuminate\Support\Str::humanize($unit->name), 'capacity' => (int) $unit->capacity];
            $tree['plans'][$unit->id] = $unit->plans->map(fn ($plan) => [
                'id' => $plan->id,
                'name' => \Illuminate\Support\Str::humanize($plan->name) . ' (' . number_format((float) $plan->amount, 2) . ' / ' . ($plan->lease_period_label ?? '-') . ')',
                'amount' => (float) $plan->amount, 'timeBased' => (int) $plan->is_time_based,
                'periodHours' => Plan::PERIOD_HOURS[$plan->lease_period] ?? 1,
                // the form sets the end from the start for one lease period (custom.js reservationEndFromPlan())
                'leasePeriod' => (string) $plan->lease_period,
                // its taxes (docs/payments-plan.md §3): the form's breakdown once taxes start (Phase B)
                'vatExempt' => (int) $plan->vat_exempt, 'tableTax' => (int) $plan->table_tax, 'inclusive' => (int) $plan->price_includes_tax,
                'invoiceDefault' => (int) $plan->invoice_by_default,
            ])->values()->all();
        }

        return $tree;
    }

    public function prefill($accountId, array $input): ?Reservation
    {
        $type = !empty($input['subscription_type_id']) ? SubscriptionType::where('account_id', $accountId)->find((int) $input['subscription_type_id']) : null;
        $unit = !empty($input['unit_id']) ? Unit::where('account_id', $accountId)->find((int) $input['unit_id']) : null;
        if ($unit && $type && (int) $unit->subscription_type_id !== $type->id) {
            $unit = null;
        }
        $spaceId = $unit?->space_id ?: (int) ($input['space_id'] ?? 0);
        $spaceId = $spaceId && \Modules\SpaceModule\app\Models\Space::where('account_id', $accountId)->whereKey($spaceId)->exists() ? $spaceId : 0;
        $start = null;
        if (!empty($input['start']) && strtotime((string) $input['start'])) {
            $start = Carbon::parse($input['start']);
            if (strlen(trim((string) $input['start'])) <= 10) {
                $start->setTimeFrom(now());
            }
        }
        if (!$type && !$unit && !$start) {
            return null;
        }

        return (new Reservation())->forceFill([
            'subscription_type_id' => $type?->id ?? (int) ($unit?->subscription_type_id ?? 0),
            'space_id' => $spaceId,
            'unit_id' => $unit?->id ?? 0,
            'start_at' => $start,
        ]);
    }

    /**
     * Why $reservation can not renew the period $periodId (null = it can): the period must be of the account, of the same
     * (period) type, start before it and end no later than the renewals window after its start (ended or ending), and have
     * no other counted renewal. Used by create (Renew), the account's "Renewal of #" and the status guard.
     */
    public function renewalError($accountId, Reservation $reservation, int $periodId): ?string
    {
        $period = Reservation::where('account_id', $accountId)->find($periodId);
        if (!$period || ($reservation->exists && $period->id === $reservation->id)) {
            return 'Reservation #' . $periodId . ' was not found.';
        }
        if ((int) $period->subscription_type_id !== (int) $reservation->subscription_type_id || !$period->isPeriod()) {
            return 'Reservation #' . $periodId . ' is not a period of the same subscription type.';
        }
        $start = $reservation->start_at;
        $days = \Modules\AccountModule\app\Models\Account::settingsOf($accountId)?->renewalDays() ?? 7;
        if (!$start || !$period->start_at->lt($start) || ($period->realEnd() ?? $period->start_at)->copy()->startOfDay()->gt($start->copy()->startOfDay()->addDays($days))) {
            return 'Reservation #' . $periodId . ' must start before this one and end before it (or within ' . $days . ' days after its start).';
        }
        if ($other = $this->renewedByOther($period->id, $reservation->id)) {
            return 'Reservation #' . $periodId . ' is already renewed by #' . $other->id . '.';
        }
        return null;
    }

    /**
     * The period $reservation most likely renews (null = none), the rule of reservations:link-renewals and the 2026-10-27 audit:
     * a counted, not deleted period of the same account + member + type + space, starting before it, its last day 7 days
     * before … 15 days after its start, not renewed by another counted reservation; same unit first, then the nearest gap,
     * then the newest. Only for a period type with a member.
     */
    public function renewalCandidate(Reservation $reservation): ?Reservation
    {
        if (!$reservation->isPeriod() || $reservation->member_id <= 0 || !$reservation->start_at) {
            return null;
        }
        $start = $reservation->start_at->toDateString();
        $gap = 'DATEDIFF(?, ' . Reservation::END_DATE_SQL . ')';

        return Reservation::query()->counted()
            ->where('account_id', $reservation->account_id)->where('member_id', $reservation->member_id)
            ->where('subscription_type_id', $reservation->subscription_type_id)->where('space_id', $reservation->space_id)
            ->whereKeyNot($reservation->id)->where('start_at', '<', $reservation->start_at)
            ->whereRaw("$gap BETWEEN -7 AND 15", [$start])
            ->whereNotExists(fn ($query) => $query->selectRaw(1)->from('reservations as next')
                ->whereColumn('next.renewal_of', 'reservations.id')->where('next.id', '<>', $reservation->id)->whereNull('next.deleted_at')
                ->whereNotIn('next.reservation_status_id', ReservationStatus::notCountedIds() ?: [0]))
            ->orderByRaw('unit_id = ? DESC', [(int) $reservation->unit_id])
            ->orderByRaw("ABS($gap)", [$start])->orderByDesc('id')
            ->first();
    }

    /**
     * Cancelling (counted → not counted) a reservation that kept its own payments (net paid > 0): refused with "Refund first";
     * the account owner may force it with a reason → the line added to the notes (null = no payments, nothing to add).
     * Payments of a package reservation are on the package: not checked here.
     */
    private function cancelPaidNote(Reservation $reservation, ReservationStatus $status, ?string $reason, ?Model $user): ?string
    {
        $paid = $reservation->paymentsTotal();
        if ($paid <= 0) {
            return null;
        }
        $message = 'This reservation has payments of ' . number_format($paid, 2) . '. Refund first.';
        $reason = trim((string) $reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['cancel_paid' => $message]);
        }
        if (!$user || !$this->paymentService->isOwner($user)) {
            throw ValidationException::withMessages(['cancel_paid' => $message . ' Only the account owner can cancel it with its payments.']);
        }
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 255) {
            throw ValidationException::withMessages(['cancel_paid' => 'Write why it is cancelled with its payments (3 to 255 characters).']);
        }

        return '[' . now()->format(Reservation::DATE_FORMAT . ' ' . Reservation::TIME_FORMAT) . '] ' . $status->name
            . ' with payments of ' . number_format($paid, 2) . ' by ' . ($user->userable?->displayName() ?? 'the account owner') . ': ' . $reason;
    }

    // the counted reservation (not $ignoreId) that already renews the period, null when none
    private function renewedByOther(int $periodId, $ignoreId = null): ?Reservation
    {
        return Reservation::where('renewal_of', $periodId)->counted()->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))->first();
    }

    // a link to the reservation's view (new tab), for the "html_" errors
    private function reservationLink(Reservation $reservation): string
    {
        $area = request()->routeIs('employee.*') ? 'employee' : 'account';
        return '<a href="' . e(route($area . '.reservations.show', $reservation->id)) . '" target="_blank" rel="noopener" class="font-medium underline">#'
            . $reservation->id . '</a>';
    }

    /**
     * Mark a not renewed period "left" (the member did not renew; off the Schedule page's Not renewed list) — $by = the user,
     * or undo it. Only a counted period of a period type that is not renewed; undo only when marked.
     */
    public function markLeft($accountId, $id, Model $by): Reservation
    {
        $reservation = $this->findOne($accountId, $id);
        if (!$reservation->isPeriod() || !$reservation->isCounted() || $reservation->left_at || $reservation->renewedBy()->exists()) {
            throw ValidationException::withMessages(['reservation' => 'Only an active, not renewed subscription period can be marked as left.']);
        }
        $reservation->forceFill(['left_at' => now(), 'left_by' => $by->getKey()])->save();
        return $reservation;
    }

    public function undoLeft($accountId, $id): Reservation
    {
        $reservation = $this->findOne($accountId, $id);
        if (!$reservation->left_at) {
            throw ValidationException::withMessages(['reservation' => 'This period is not marked as left.']);
        }
        $reservation->forceFill(['left_at' => null, 'left_by' => 0])->save();
        return $reservation;
    }

    // soft delete; the reason when it can not be deleted (it has payments: their money must not be lost), null when deleted
    public function deleteOne($accountId, $id): ?string
    {
        $reservation = $this->findOne($accountId, $id);
        if ($reservation->payments()->exists()) {
            return 'This reservation has cash in, it can not be deleted. Refund or delete its cash in first, or move it into a package (its cash in moves with it).';
        }

        DB::transaction(function () use ($reservation) {
            $reservation->delete();
            $this->packageService->recalculate($reservation->package_id);
        });
        return null;
    }

    // the copies of a repeated reservation: same data, moved to each repeat start (same duration), repeat_id = the original;
    // a period type: each copy renews the one before it (renewal_of: the first copy → the original), so the chain is renewed;
    // a period type repeated by its plan's lease period (monthly / yearly): each end by the original's anchor day
    // (Reservation::anchoredEnd(), 2026-10-23: no gaps / overlaps at month ends) and the copies keep its subscription_day
    private function createRepeats(Reservation $reservation, Carbon $until): int
    {
        $starts = Reservation::repeatStarts($reservation->start_at, $reservation->repeat_frequency, (int) $reservation->repeat_interval, $until, Reservation::MAX_REPEATS);
        $duration = $reservation->end_at ? $reservation->start_at->diffInSeconds($reservation->end_at) : null;
        $previousId = $reservation->isPeriod() ? $reservation->id : null;
        $lease = $reservation->plan?->is_time_based ? null : $reservation->plan?->lease_period;
        $anchored = $previousId && ($reservation->repeat_frequency === 'monthly' && $lease === 'month'
            || $reservation->repeat_frequency === 'yearly' && $lease === 'year');

        foreach ($starts as $start) {
            $copy = $reservation->replicate();
            $copy->start_at = $start;
            $copy->end_at = $anchored ? Reservation::anchoredEnd($start, $reservation->subscription_day, $lease)
                : ($duration !== null ? $start->copy()->addSeconds($duration) : null);
            $copy->subscription_day = $anchored ? $reservation->subscription_day : $start->day;
            $copy->is_repeat = false;
            $copy->repeat_frequency = 'monthly';
            $copy->repeat_interval = 1;
            $copy->repeat_id = $reservation->id;
            $copy->renewal_of = $previousId;
            $copy->save();
            $previousId = $previousId ? $copy->id : null;
        }

        return count($starts);
    }

    // [start, end] of the form data: a time based plan = the date + the time, else the whole days (the end is required; no end =
    // a defensive fallback only)
    public function period(array $data): array
    {
        $timeBased = (bool) Plan::find($data['plan_id'] ?? null)?->is_time_based;

        $startAt = $timeBased && !empty($data['start_time'])
            ? Carbon::parse($data['start_at'] . ' ' . $data['start_time'])
            : Carbon::parse($data['start_at'])->startOfDay();
        $endAt = empty($data['end_at']) ? null
            : ($timeBased && !empty($data['end_time']) ? Carbon::parse($data['end_at'] . ' ' . $data['end_time']) : Carbon::parse($data['end_at'])->endOfDay());

        return [$startAt, $endAt];
    }

    // (the reason is HTML: escaped texts + links to the reservations)
    // the unit must have a free place in the period (and in every repeat on create): the reason when it does not, null when free.
    // The unit concurrent usage = how many reservations can use it at the same time (1 = only one): the period is refused when,
    // at some moment of it, the unit already has that many reservations at the same time.
    // Not counted: the reservation itself ($ignoreId, edit), the deleted ones and the not counted statuses (cancelled ...)
    public function availabilityError(array $data, $ignoreId = null): ?string
    {
        $unit = Unit::find($data['unit_id'] ?? null);
        if (!$unit) {
            return null;
        }
        [$startAt, $endAt] = $this->period($data);

        // the periods to check: the reservation + its repeats (create only)
        $periods = [[$startAt, $endAt]];
        $type = SubscriptionType::find($data['subscription_type_id'] ?? null);
        if (!$ignoreId && !empty($data['is_repeat']) && $type?->can_repeat && !empty($data['repeat_until'])) {
            $duration = $endAt ? $startAt->diffInSeconds($endAt) : null;
            foreach (Reservation::repeatStarts($startAt, $data['repeat_frequency'] ?? 'monthly', (int) ($data['repeat_interval'] ?? 1), Carbon::parse($data['repeat_until']), Reservation::MAX_REPEATS) as $start) {
                $periods[] = [$start, $duration !== null ? $start->copy()->addSeconds($duration) : null];
            }
        }

        return $this->periodsError($unit, $periods, $ignoreId);
    }

    /**
     * The form's live check (2026-10-18, {area}.reservations.availability): the unit (of the account) in the period of $data
     * (period(): the plan decides full days or times), the reservation itself left out on edit. The same rule as the save check
     * (availabilityError(): counted, overlapping, the busiest moment vs the unit's concurrent usage), repeats not included.
     * ['limit' => concurrent usage, 'used' => at the busiest moment, 'full' => bool, 'items' => [[member, period]] (max 3), 'more' => n]
     */
    public function availability($accountId, array $data, $ignoreId = null): ?array
    {
        $unit = Unit::where('account_id', $accountId)->find($data['unit_id'] ?? null);
        if (!$unit || empty($data['start_at']) || empty($data['end_at'])) {
            return null;
        }
        [$startAt, $endAt] = $this->period($data);
        if ($endAt && $endAt->lte($startAt)) {
            return null;
        }
        $limit = max(1, (int) $unit->concurrent_usage);
        $overlapping = Reservation::overlapping($unit->id, $startAt, $endAt)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->with('member', 'plan')->orderBy('start_at')->get();
        $busiest = $this->busiestMoment($overlapping, $startAt, $endAt);
        // an exclusive unit: every reservation in the way; a shared one: the ones at the busiest moment
        $shown = $limit > 1 ? collect($busiest) : $overlapping;

        return [
            'limit' => $limit,
            'used' => count($busiest),
            'full' => count($busiest) >= $limit,
            'items' => $shown->take(3)->map(fn ($reservation) => ['member' => $reservation->member?->name ?? '-', 'period' => $reservation->period])->values()->all(),
            'more' => max(0, $shown->count() - 3),
        ];
    }

    // the first period (the reservation, then its repeats) where the unit is full: the reason (HTML), null when all are free
    private function periodsError(Unit $unit, array $periods, $ignoreId = null): ?string
    {
        $limit = max(1, (int) $unit->concurrent_usage);
        foreach ($periods as $index => [$start, $end]) {
            $overlapping = Reservation::overlapping($unit->id, $start, $end)
                ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
                ->with('member', 'plan')->orderBy('start_at')->get();
            // the busiest moment of the period: full when it already has "concurrent usage" reservations at the same time
            $busiest = $this->busiestMoment($overlapping, $start, $end);
            if (count($busiest) >= $limit) {
                // HTML (shown as HTML by custom.js for the "html_" errors): every text is escaped, the reservations are links to their edit page (new tab)
                $area = request()->routeIs('employee.*') ? 'employee' : 'account';
                $repeat = $index ? ' for the repeat of ' . e($start->format(Reservation::DATE_FORMAT)) : '';
                $taken = collect($busiest)->map(fn ($reservation) => '<a href="' . e(route($area . '.reservations.edit', $reservation->id)) . '" target="_blank" rel="noopener" class="font-medium underline">'
                    . e($reservation->period . ' (' . ($reservation->member?->name ?? '-') . ')') . '</a>')->join(', ');
                return $limit > 1
                    ? 'The unit "' . e($unit->name) . '" is full' . $repeat . ': it can be used by ' . $limit . ' reservations at the same time, reserved by ' . $taken . '.'
                    : 'The unit "' . e($unit->name) . '" is already reserved' . $repeat . ': ' . $taken . '.';
            }
        }

        return null;
    }

    // the reservations at the same time at the busiest moment inside start -> end (end null = continues):
    // each one counts from its start to its end inside the period, one ending when another starts is not at the same time
    private function busiestMoment($reservations, $start, $end): array
    {
        $events = [];
        foreach ($reservations as $reservation) {
            $from = max($reservation->start_at->getTimestamp(), $start->getTimestamp());
            $to = min($reservation->end_at ? $reservation->end_at->getTimestamp() : PHP_INT_MAX, $end ? $end->getTimestamp() : PHP_INT_MAX);
            if ($from < $to) {
                $events[] = [$from, 1, $reservation];
                $events[] = [$to, -1, $reservation];
            }
        }
        // by time; at the same time the ends first (back to back is not at the same time)
        usort($events, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        $now = [];
        $busiest = [];
        foreach ($events as [, $change, $reservation]) {
            if ($change > 0) {
                $now[$reservation->id] = $reservation;
                if (count($now) > count($busiest)) {
                    $busiest = $now;
                }
            } else {
                unset($now[$reservation->id]);
            }
        }

        return array_values($busiest);
    }

    private function reservationData($accountId, array $data): array
    {
        $plan = Plan::find($data['plan_id']);
        $timeBased = (bool) $plan?->is_time_based;
        // repeat only when the subscription type allows it
        $type = SubscriptionType::find($data['subscription_type_id']);
        $isRepeat = !empty($data['is_repeat']) && $type?->can_repeat;
        [$startAt, $endAt] = $this->period($data);
        // a time based plan: the plan amount x the periods from the start to the end (no end: one period, fallback)
        if ($timeBased) {
            $data['amount'] = $endAt ? $plan->amountBetween($startAt, $endAt) : (float) $plan->amount;
        }

        // a package reservation is always for the package's member
        $packageId = $data['package_id'] ?? null;
        $memberId = $data['member_id'] ?? null;
        if ($packageId) {
            $memberId = Package::where('account_id', $accountId)->findOrFail($packageId)->member_id;
        }

        [$discountValue, $discountPercentage, $netAmount] = Reservation::calculate(
            (float) $data['amount'],
            isset($data['discount_percentage']) ? (float) $data['discount_percentage'] : null,
            isset($data['discount_value']) ? (float) $data['discount_value'] : null,
            $data['discount_type'] ?? 'percentage',
            isset($data['net_amount']) ? (float) $data['net_amount'] : null
        );

        return [
            'subscription_type_id' => $data['subscription_type_id'],
            'space_id' => $data['space_id'],
            'unit_id' => $data['unit_id'],
            'plan_id' => $data['plan_id'],
            // the columns are not nullable: 0 = none
            'package_id' => $packageId ?: 0,
            'member_id' => $memberId,
            'start_at' => $startAt,
            'end_at' => $endAt,
            // the day of the month of the start (1 - 31)
            'subscription_day' => $startAt->day,
            'is_repeat' => $isRepeat,
            'repeat_frequency' => $isRepeat ? $data['repeat_frequency'] : 'monthly',
            'repeat_interval' => $isRepeat ? (int) $data['repeat_interval'] : 1,
            // a unit for 1 person (capacity 1, or 0 = not set) is always 1 person (the form hides the field)
            'number_of_peoples' => (int) Unit::find($data['unit_id'])?->capacity <= 1 ? 1 : $data['number_of_peoples'],
            'amount' => round((float) $data['amount'], 2),
            'discount_percentage' => $discountPercentage,
            'discount_value' => $discountValue,
            'net_amount' => $netAmount,
            'notes' => $data['notes'] ?? null,
        ];
    }
}
