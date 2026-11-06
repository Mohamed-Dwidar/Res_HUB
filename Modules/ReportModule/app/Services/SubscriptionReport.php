<?php

namespace Modules\ReportModule\app\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\ReservationModule\app\Models\Reservation;
use Modules\SpaceModule\app\Models\SubscriptionType;

/**
 * Report 5 — Subscriptions (docs/reports-plan.md §5): the period reservations (booking_mode = period types; counted, with a member
 * and an end) by the EXISTING renewal rules only (Reservation::scopeRenewalStatus() / renewalDateBetween() / paidRenewalSql() /
 * renewedSql(); RENEWAL_DATE_SQL = the day after the last day; the row texts from RenewalView). Tabs:
 * - new: starts in the dates, not a renewal (renewal_of NULL);
 * - renewed: renewal date in the dates, its renewal exists AND is paid (not left);
 * - not_renewed: renewal date in the dates, no paid renewal, not left — inside it "Renewed · unpaid" = a renewal exists, not paid;
 * - ending: renewal date from the business date to N days later (the "N days" field, default the account's renewals window),
 *   not renewed-and-paid, not left — the dates field does not apply;
 * - left: renewal date in the dates, marked left.
 * No dates = all dates.
 */
class SubscriptionReport
{
    const TABS = ['new' => 'New', 'renewed' => 'Renewed', 'not_renewed' => 'Not renewed', 'ending' => 'Ending soon', 'left' => 'Left'];

    public function filters(array $input): array
    {
        $date = fn ($value) => $value && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
        $days = (int) ($input['days'] ?? 0);
        return [
            'view' => array_key_exists($input['view'] ?? '', self::TABS) ? $input['view'] : 'new',
            'search' => trim((string) ($input['search'] ?? '')),
            'date_from' => $date($input['date_from'] ?? null),
            'date_to' => $date($input['date_to'] ?? null),
            'space_id' => (int) ($input['space_id'] ?? 0),
            'unit_id' => (int) ($input['unit_id'] ?? 0),
            'plan_id' => (int) ($input['plan_id'] ?? 0),
            'days' => $days >= 1 && $days <= 365 ? $days : Reservation::renewalDaysOf(),
        ];
    }

    // [from, to) of the dates field (none = all dates)
    private function bounds(array $filters): array
    {
        if (!$filters['date_from']) {
            return [Carbon::create(2000, 1, 1), Carbon::create(2100, 1, 1)];
        }
        return [Carbon::parse($filters['date_from'])->startOfDay(), Carbon::parse($filters['date_to'] ?: $filters['date_from'])->startOfDay()->addDay()];
    }

    public function rangeText(array $filters): string
    {
        if ($filters['view'] === 'ending') {
            [$today] = Reservation::businessToday();
            return 'Renewal ' . $today->format(Reservation::DATE_FORMAT) . ' → ' . $today->copy()->addDays($filters['days'])->format(Reservation::DATE_FORMAT);
        }
        if (!$filters['date_from']) {
            return 'All dates';
        }
        $format = Reservation::DATE_FORMAT;
        $from = Carbon::parse($filters['date_from']);
        $to = Carbon::parse($filters['date_to'] ?: $filters['date_from']);
        return $from->isSameDay($to) ? $from->format($format) : $from->format($format) . ' → ' . $to->format($format);
    }

    // the period rows of the account under the place / member filters (counted, member, end)
    private function base($accountId, array $filters)
    {
        $query = Reservation::query()->where('reservations.account_id', $accountId)
            ->whereIn('reservations.subscription_type_id', SubscriptionType::idsOf('period') ?: [0])
            ->counted()->where('reservations.member_id', '>', 0)->whereNotNull('reservations.end_at');
        foreach (['space_id', 'unit_id', 'plan_id'] as $field) {
            if ($filters[$field]) {
                $query->where('reservations.' . $field, $filters[$field]);
            }
        }
        if ($filters['search'] !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $filters['search']) . '%';
            $query->whereHas('member', fn ($query) => $query->where('name', 'like', $like)->orWhere('phone', 'like', $like));
        }

        return $query;
    }

    // one tab's rows
    public function tab($accountId, array $filters, ?string $view = null)
    {
        $view ??= $filters['view'];
        [$from, $to] = $this->bounds($filters);
        $query = $this->base($accountId, $filters);

        return match ($view) {
            'new' => $query->whereNull('reservations.renewal_of')
                ->where('reservations.start_at', '>=', $from)->where('reservations.start_at', '<', $to),
            'renewed' => $query->renewalStatus('renewed', $from, $to),
            'not_renewed' => $query->whereNull('reservations.left_at')->renewalDateBetween($from, $to)
                ->whereRaw('NOT ' . Reservation::paidRenewalSql()),
            'ending' => (function () use ($query, $filters) {
                [$today] = Reservation::businessToday();
                $today = $today->copy()->startOfDay();
                return $query->whereNull('reservations.left_at')->renewalDateBetween($today, $today->copy()->addDays($filters['days'] + 1))
                    ->whereRaw('NOT ' . Reservation::paidRenewalSql());
            })(),
            'left' => $query->renewalStatus('left', $from, $to),
        };
    }

    // the list of the chosen tab (eager loaded for RenewalView and the cells), its default order
    public function list($accountId, array $filters)
    {
        return $this->tab($accountId, $filters)
            ->with(['member', 'space', 'unit.color', 'plan', 'subscriptionType', 'status', 'renewedBy'])
            ->select('reservations.*');
    }

    // the count of every tab + "Renewed · unpaid" inside Not renewed, and the chosen tab's amount / owed
    public function counts($accountId, array $filters): array
    {
        $counts = [];
        foreach (array_keys(self::TABS) as $view) {
            $counts[$view] = (clone $this->tab($accountId, $filters, $view))->count();
        }
        $counts['renewed_unpaid'] = $this->tab($accountId, $filters, 'not_renewed')
            ->whereRaw(Reservation::renewedSql())->count();

        return $counts;
    }

    public function totals($accountId, array $filters): object
    {
        $totals = $this->tab($accountId, $filters)->toBase()->selectRaw('COUNT(*) as count, ROUND(COALESCE(SUM(' . Reservation::DUE_SQL . '), 0), 2) as amount,
            ROUND(COALESCE(SUM(IF(' . Reservation::OWES_SQL . ', reservations.remaining, 0)), 0), 2) as owed')->first();
        $totals->amount = (float) $totals->amount;
        $totals->owed = (float) $totals->owed;

        return $totals;
    }

    // the RenewalView kind of a tab (what the State column says)
    public static function kindOf(string $view): string
    {
        return ['new' => 'new', 'renewed' => 'renewed', 'not_renewed' => 'upcoming', 'ending' => 'next', 'left' => 'left'][$view];
    }
}
