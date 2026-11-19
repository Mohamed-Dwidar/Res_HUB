<?php

namespace Modules\PackageModule\app\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\AccountModule\app\Models\Account;
use Modules\PackageModule\app\Models\Package;
use Modules\PaymentModule\app\Models\InvoiceRequestLog;
use Modules\PaymentModule\app\Services\PaymentService;
use Modules\PaymentModule\app\Services\Tax\PackageTax;
use Modules\PlanModule\app\Models\Plan;
use Modules\ReservationModule\app\Models\ReservationStatus;
use Modules\PackageModule\app\Repositories\PackageRepository;

/**
 * Every method takes the owner account id (the account, or the account of the logged in employee).
 */
class PackageService
{
    private $packageRepository;

    public function __construct(PackageRepository $packageRepository)
    {
        $this->packageRepository = $packageRepository;
    }

    // list query (paged / ordered by DataTables)
    public function listQuery($accountId, array $filters = [])
    {
        return $this->packageRepository->forAccount($accountId)->filter($filters);
    }

    // the account's packages for the reservation form (no active / inactive since 2026-10-21): [id => ['name', 'member_id']]
    public function options($accountId, $currentId = null): array
    {
        // not forAccount(): its reservations count (a subquery per package) is not needed here and made the form take ~3 s
        return Package::with('member')->where('account_id', $accountId)
            // newest first (the reservation form, user 2026-10-22)
            ->orderByDesc('id')->get()
            ->mapWithKeys(fn ($package) => [$package->id => [
                // only the package name (2026-11-19, user): its member is shown under the field; still searchable by the member
                'name' => $package->name,
                'member_id' => $package->member_id,
                // the reservation form shows the package's member in its Member field (server search: the option is added)
                'member_text' => $package->member ? $package->member->name . ' ' . $package->member->phone : '',
            ]])->all();
    }

    // 404 when the package belongs to another account
    public function findOne($accountId, $id)
    {
        return $this->packageRepository->forAccount($accountId)->findOrFail($id);
    }

    /**
     * The customer package statement (PDF, PackageActions::statement()) — not a tax invoice (those come from ETA):
     * ALL its reservations by date — the cancelled like ones are listed too (red, 0 hours, 0 amount, not in the totals) —,
     * hours of the TIMED counted ones only (not a full day / contract type,
     * end after start — the same rule as the list), the extras of type "extra" (adjustments = one "balance carried over" line),
     * the payments (refunds minus, withholding apart; no collector) and the totals.
     */
    public function statement($accountId, $id): array
    {
        $package = $this->findOne($accountId, $id)->load('member.company', 'account');
        $all = $package->reservations()
            ->with('space', 'unit', 'plan', 'subscriptionType', 'status')->orderBy('start_at')->orderBy('id')->get();
        // the counted ones give the count / hours / totals; a not counted (cancelled ...) one = 0 hours
        $reservations = $all->filter(fn ($reservation) => $reservation->isCounted())->values();
        $minutes = $all->mapWithKeys(fn ($reservation) => [$reservation->id => $reservation->isCounted() ? $this->timedMinutes($reservation) : 0]);
        $extras = $package->extrasList();

        return [
            'package' => $package,
            'account' => $package->account,
            'reservations' => $reservations,
            'allReservations' => $all,
            'cancelledCount' => $all->count() - $reservations->count(),
            'minutes' => $minutes,
            'hours' => round($minutes->sum() / 60, 2),
            'reservationsTotal' => round((float) $reservations->sum('net_amount'), 2),
            'extras' => $extras->where('type', 'extra')->values(),
            'extrasTotal' => round((float) $extras->where('type', 'extra')->sum('amount'), 2),
            'adjustments' => $extras->where('type', 'adjustment'),
            'adjustmentsTotal' => round((float) $extras->where('type', 'adjustment')->sum('amount'), 2),
            'payments' => $package->payments()->with('paymentMethod')->orderBy('paid_at')->orderBy('payment_no')->get(),
            'due' => round($package->payableAmount(), 2),
            // taxes (Phase C): the package part + its taxed extras; null without an invoice
            'tax' => $package->isTaxed() ? [
                'net' => round((float) $package->tax_net + (float) $extras->sum(fn ($extra) => (float) ($extra->tax_net ?? $extra->amount)), 2),
                'vat' => round((float) $package->vat + (float) $extras->sum(fn ($extra) => (float) $extra->vat), 2),
                'table' => round((float) $package->table_tax + (float) $extras->sum(fn ($extra) => (float) $extra->table_tax), 2),
                'discount' => round((float) $package->tax_extra_discount + (float) $extras->sum(fn ($extra) => (float) $extra->tax_extra_discount), 2),
            ] : null,
        ];
    }

    // the booked minutes of a timed reservation (0: a full day / contract type, or no end after the start)
    private function timedMinutes($reservation): int
    {
        $type = $reservation->subscriptionType;
        if ($type?->isFullDay() || $type?->is_contract || !$reservation->start_at || !$reservation->end_at || $reservation->end_at->lte($reservation->start_at)) {
            return 0;
        }

        return (int) ceil($reservation->start_at->diffInSeconds($reservation->end_at) / 60);
    }

    // $creator: the logged in user who adds the package
    /**
     * Add Package: the name suggested for a member (2026-10-22): "<member name> - <m-Y>" (this month), or when the account
     * already has a package with that name "<member name> - <m-Y> - N" (the first free N from 1). Null: not one of its members.
     */
    public function suggestName($accountId, $memberId): ?string
    {
        $member = \Modules\MemberModule\app\Models\Member::where('account_id', $accountId)->find((int) $memberId);
        if (!$member) {
            return null;
        }
        $base = trim($member->name) . ' - ' . now()->format('m-Y');
        $taken = Package::where('account_id', $accountId)
            ->where(fn ($query) => $query->where('name', $base)->orWhere('name', 'like', addcslashes($base, '%_\\') . ' - %'))
            ->pluck('name')->map(fn ($name) => mb_strtolower($name))->all();
        if (!in_array(mb_strtolower($base), $taken, true)) {
            return $base;
        }
        for ($number = 1; in_array(mb_strtolower($base . ' - ' . $number), $taken, true); $number++);

        return $base . ' - ' . $number;
    }

    public function create($accountId, array $data, Model $creator)
    {
        $package = $this->packageRepository->makeModel()->newInstance(['account_id' => $accountId]);
        $package->fill($this->packageData($package, $data));
        $package->creatable()->associate($creator);
        $package->save();
        $package->recalculate();

        return $package;
    }

    // $by: the logged in user ("Invoice requested": the back-dated rule + the log)
    public function update($accountId, $id, array $data, ?Model $by = null)
    {
        $package = $this->findOne($accountId, $id);

        return DB::transaction(function () use ($package, $data, $by) {
            $oldDiscount = [(float) $package->discount_percentage, (float) $package->discount_fixed];
            $package->fill($this->packageData($package, $data));
            $wasInvoice = (bool) $package->invoice_requested;
            if ($this->canSetInvoice($package, $by) && array_key_exists('invoice_requested', $data)) {
                $package->invoice_requested = !empty($data['invoice_requested']);
            }
            // the taxes (PackageTax), the extras re-snapshotted, paid / remaining
            $package->recalculate();
            if ($wasInvoice !== (bool) $package->invoice_requested) {
                $this->followInvoice($package);
                $this->logInvoiceChange($package, $wasInvoice, $by);
            }
            // Payment Exceptions › Discounts (2026-11-17)
            \Modules\PaymentModule\app\Models\DiscountLog::recordChange($package, $oldDiscount, $by);
            return $package;
        });
    }

    /**
     * May $by change the package's "Invoice requested" (Phase C, docs/payments-plan.md §3)? Only while taxes apply to it
     * (PackageTax::applies()); a back-dated package (made before the tax start date was set) = the owner only.
     */
    public function canSetInvoice(Package $package, ?Model $by): bool
    {
        $account = Account::settingsOf($package->account_id);
        if (!PackageTax::applies($account, $package)) {
            return false;
        }
        return !($package->exists && $account?->isBackdated($package->created_at)) || ($by && app(PaymentService::class)->isOwner($by));
    }

    /**
     * The plan's "Invoice by default" (user 2026-11-16): a package gets it from its FIRST reservation's plan — while it has no
     * counted reservation yet, was never switched by hand (no invoice_request_logs row), taxes apply and it is not back-dated.
     * Called by ReservationService before the reservation's own snapshot (it follows the package).
     */
    public function applyPlanDefault($packageId, $planId): void
    {
        $package = $packageId ? Package::find($packageId) : null;
        if (!$package || $package->invoice_requested || !Plan::whereKey($planId)->value('invoice_by_default')) {
            return;
        }
        $account = Account::settingsOf($package->account_id);
        if (!PackageTax::applies($account, $package) || $account?->isBackdated($package->created_at)
            || $package->reservations()->whereNotIn('reservation_status_id', ReservationStatus::notCountedIds())->exists()
            || InvoiceRequestLog::where('loggable_type', $package->getMorphClass())->where('loggable_id', $package->id)->exists()) {
            return;
        }
        // recalculate() (after the reservation is saved) fills the taxes
        $package->newQueryWithoutScopes()->whereKey($package->id)->toBase()->update(['invoice_requested' => true]);
    }

    // its reservations follow the switch (their own amounts stay empty: the package carries the taxes)
    private function followInvoice(Package $package): void
    {
        $package->reservations()->toBase()->update(['invoice_requested' => (bool) $package->invoice_requested]);
    }

    // a change of "Invoice requested" (Payment Exceptions › Invoice Toggled = changed after a payment)
    private function logInvoiceChange(Package $package, bool $from, ?Model $by): void
    {
        $log = new InvoiceRequestLog([
            'account_id' => $package->account_id, 'from_value' => $from, 'to_value' => (bool) $package->invoice_requested,
            'paid_amount' => (float) $package->paid_amount,
        ]);
        $log->loggable()->associate($package);
        if ($by) {
            $log->creatable()->associate($by);
        }
        $log->save();
    }

    // soft delete; the reason when it can not be deleted (it has reservations / payments), null when deleted
    public function deleteOne($accountId, $id): ?string
    {
        $package = $this->findOne($accountId, $id);
        if ($package->reservations_count > 0) {
            return 'This package has reservations, it can not be deleted. You can deactivate it.';
        }
        // its money must not be lost
        if ($package->payments()->exists()) {
            return 'This package has cash in, it can not be deleted. Refund or delete its cash in first.';
        }
        $package->delete();
        return null;
    }

    // after a reservation of the package is saved / deleted
    public function recalculate($packageId)
    {
        Package::find($packageId)?->recalculate();
    }

    // the price is the reservations total (not typed); the discount (2026-11-17, Edit only) = the form's Discount Type: a % or a
    // fixed amount (the other one = 0), before tax; the Add form sends neither: kept (none)
    private function packageData(Package $package, array $data): array
    {
        if (!array_key_exists('discount_type', $data)) {
            [$percentage, $fixed] = [(float) $package->discount_percentage, (float) $package->discount_fixed];
        } elseif ($data['discount_type'] === 'fixed') {
            [$percentage, $fixed] = [0.0, round(max(0, (float) ($data['discount_fixed'] ?? 0)), 2)];
        } elseif (!empty($data['net_typed']) && isset($data['after_discount'])) {
            // the net typed (2026-11-17): the % worked out from it
            [$percentage, $fixed] = [Package::calculate($package->reservationsTotal(), null, (float) $data['after_discount'], 'after')[0], 0.0];
        } else {
            [$percentage, $fixed] = [min(max(0, round((float) ($data['discount_percentage'] ?? 0), 2)), 100), 0.0];
        }

        return [
            'name' => $data['name'],
            'member_id' => $data['member_id'],
            'discount_percentage' => $percentage,
            'discount_fixed' => $fixed,
            'notes' => $data['notes'] ?? null,
        ];
    }
}
