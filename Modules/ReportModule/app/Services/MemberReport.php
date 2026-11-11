<?php

namespace Modules\ReportModule\app\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\PackageModule\app\Models\Package;
use Modules\PaymentModule\app\Models\Payment;
use Modules\ReservationModule\app\Models\Reservation;
use Modules\ReservationModule\app\Models\ReservationStatus;

/**
 * Report 8 — Members: one row per member, aggregated in SQL (account scoped, merged / deleted members left out).
 * - First visit = the first counted reservation's start or the first package's start (date_from, else created), all time;
 *   Last visit = the latest reservation that started up to now; Reservations = every reservation, all time — cancelled included
 *   (2026-11-11: a member whose reservations are all cancelled shows, commitment 0%).
 * - Paid = cash in − refunds of the member in the dates (`payments.member_id`, `Payment::PAID_SQL`; reservations, packages and other
 *   income counted as income — the Income report's rows, with tax: what the member paid).
 * - Balance = the member statement's balance as of the range end, never after the business date (charges − payments dated up
 *   to it: no future charges, 2026-11-11), `balanceDate()`.
 * - Space type → Space → Unit filters: reservations of that place only (and their payments; packages / other income left out).
 * - Commitment = counted (not cancelled) reservations ÷ all reservations, all time (the place filters apply).
 * Tabs: new = first visit in the dates | top = paid in the dates > 0, most first | inactive = regulars who stopped: at least
 * "Min. reservations" counted reservations, the latest one reaching (end, else start) between "Last visit within" days ago and
 * N days ago (a future booking keeps a member active; the dates do not apply) |
 * occupation = per occupation: members active in the dates (a reservation starting in them or a payment), new ones, paid.
 * No dates = all dates.
 */
class MemberReport
{
    const TABS = ['new' => 'New', 'top' => 'Top members', 'inactive' => 'Inactive', 'occupation' => 'By occupation'];

    const DEFAULT_DAYS = 60;

    const DEFAULT_MIN = 3;

    const COMMITMENT_BELOW = [50, 70, 90];

    const DEFAULT_WITHIN = 365;

    public function filters(array $input): array
    {
        $date = fn ($value) => $value && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
        $days = (int) ($input['days'] ?? 0);
        $days = $days >= 1 && $days <= 3650 ? $days : self::DEFAULT_DAYS;
        $min = (int) ($input['min_reservations'] ?? 0);
        $within = (int) ($input['within'] ?? 0);
        return [
            'view' => array_key_exists($input['view'] ?? '', self::TABS) ? $input['view'] : 'new',
            'search' => trim((string) ($input['search'] ?? '')),
            'date_from' => $date($input['date_from'] ?? null),
            'date_to' => $date($input['date_to'] ?? null),
            'subscription_type_id' => (int) ($input['subscription_type_id'] ?? 0),
            'space_id' => (int) ($input['space_id'] ?? 0),
            'unit_id' => (int) ($input['unit_id'] ?? 0),
            'days' => $days,
            // Commitment filter: below 50 / 70 / 90 % (0 = all)
            'commitment' => in_array((int) ($input['commitment'] ?? 0), self::COMMITMENT_BELOW) ? (int) $input['commitment'] : 0,
            'min_reservations' => $min >= 1 && $min <= 1000 ? $min : self::DEFAULT_MIN,
            // never shorter than N days + 1 (else the window is empty)
            'within' => max($within >= 1 && $within <= 7300 ? $within : self::DEFAULT_WITHIN, $days + 1),
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

    // the first day that keeps a member active (today − N days)
    public function cutoff(array $filters): Carbon
    {
        [$today] = Reservation::businessToday();
        return $today->copy()->startOfDay()->subDays($filters['days']);
    }

    // the oldest last visit the Inactive tab keeps (today − "Last visit within" days)
    public function windowStart(array $filters): Carbon
    {
        [$today] = Reservation::businessToday();
        return $today->copy()->startOfDay()->subDays($filters['within']);
    }

    // the Balance column's date: the range end, never after the business date (none / All = the business date)
    public function balanceDate(array $filters): Carbon
    {
        [$today] = Reservation::businessToday();
        $today = $today->copy()->startOfDay();
        $end = $filters['date_from'] ? Carbon::parse($filters['date_to'] ?: $filters['date_from'])->startOfDay() : $today;
        return $end->gt($today) ? $today : $end;
    }

    // a place filter is chosen (packages / other income left out)
    private function placeFiltered(array $filters): bool
    {
        return $filters['subscription_type_id'] || $filters['space_id'] || $filters['unit_id'];
    }

    private function wherePlace($query, array $filters, string $table)
    {
        foreach (['subscription_type_id', 'space_id', 'unit_id'] as $field) {
            $query->when($filters[$field], fn ($query, $id) => $query->where($table . '.' . $field, $id));
        }
        return $query;
    }

    public function rangeText(array $filters): string
    {
        if ($filters['view'] === 'inactive') {
            $format = Reservation::DATE_FORMAT;
            return 'Min. ' . $filters['min_reservations'] . ' reservations · last visit ' . $this->windowStart($filters)->format($format)
                . ' → ' . $this->cutoff($filters)->subDay()->format($format) . ' (no visit for ' . $filters['days'] . ' days)';
        }
        if (!$filters['date_from']) {
            return 'All dates';
        }
        $format = Reservation::DATE_FORMAT;
        $from = Carbon::parse($filters['date_from']);
        $to = Carbon::parse($filters['date_to'] ?: $filters['date_from']);
        return $from->isSameDay($to) ? $from->format($format) : $from->format($format) . ' → ' . $to->format($format);
    }

    // one row per member: member_id, name, phone, company, job_id, occupation, first_visit, last_visit, last_reach, reservations,
    // in_range (reservations starting in the dates), commitment (% of all reservations counted), paid, balance
    public function members($accountId, array $filters)
    {
        [$from, $to] = $this->bounds($filters);
        $type = $this->placeFiltered($filters);
        $notCounted = array_map('intval', ReservationStatus::notCountedIds());
        $c = $notCounted ? '(reservations.reservation_status_id NOT IN (' . implode(',', $notCounted) . '))' : '1';

        $reservations = $this->wherePlace(Reservation::query()->where('reservations.account_id', $accountId)->where('reservations.member_id', '>', 0), $filters, 'reservations')
            ->toBase()->groupBy('reservations.member_id')
            // every reservation, cancelled included (user 2026-11-11): first / last visit, count, in_range; commitment = counted ÷ all
            ->selectRaw("reservations.member_id, MIN(reservations.start_at) as first_start,
                MAX(IF(reservations.start_at <= ?, reservations.start_at, NULL)) as last_visit,
                MAX(COALESCE(reservations.end_at, reservations.start_at)) as last_reach, COUNT(*) as reservations,
                ROUND(SUM($c) * 100 / COUNT(*)) as commitment, COUNT(*) - SUM($c) as cancelled,
                SUM(reservations.start_at >= ? AND reservations.start_at < ?) as in_range", [now(), $from, $to]);

        $packages = Package::query()->where('packages.account_id', $accountId)->where('packages.member_id', '>', 0)
            ->toBase()->groupBy('packages.member_id')
            ->selectRaw('packages.member_id, MIN(COALESCE(packages.date_from, DATE(packages.created_at))) as first_package');

        $paid = Payment::query()->where('payments.account_id', $accountId)->where('payments.member_id', '>', 0)
            ->where('payments.paid_at', '>=', $from->toDateString())->where('payments.paid_at', '<', $to->toDateString())
            ->when($type, fn ($query) => $query->where('payments.payable_type', Reservation::class)
                ->whereIn('payments.payable_id', $this->wherePlace(DB::table('reservations')->where('account_id', $accountId), $filters, 'reservations')->select('id')),
                fn ($query) => $query->where(fn ($query) => $query->whereNotNull('payments.payable_type')
                    ->orWhereIn('payments.income_category_id', DB::table('income_categories')->where('account_id', $accountId)->where('is_revenue', 1)->select('id'))))
            ->toBase()->groupBy('payments.member_id')
            ->selectRaw('payments.member_id, ROUND(SUM(' . str_replace(['type', 'amount'], ['payments.type', 'payments.amount'], Payment::PAID_SQL) . '), 2) as paid');

        // the balance as of the range end, never after the business date (no future charges; the member statement's lines)
        $balance = app(MemberStatementReport::class)->balances($accountId, $this->balanceDate($filters)->toDateString());

        $first = $type ? 'DATE(r.first_start)'
            : 'CASE WHEN r.first_start IS NULL THEN pk.first_package WHEN pk.first_package IS NULL THEN DATE(r.first_start) ELSE LEAST(DATE(r.first_start), pk.first_package) END';

        $query = DB::table('members as m')
            ->where('m.account_id', $accountId)->whereNull('m.deleted_at')
            ->leftJoinSub($reservations, 'r', 'r.member_id', '=', 'm.id')
            ->when(!$type, fn ($query) => $query->leftJoinSub($packages, 'pk', 'pk.member_id', '=', 'm.id'))
            ->leftJoinSub($paid, 'pd', 'pd.member_id', '=', 'm.id')
            ->leftJoinSub($balance, 'bl', 'bl.member_id', '=', 'm.id')
            ->leftJoin('companies as c', 'c.id', '=', 'm.company_id')
            ->leftJoin('job_positions as j', 'j.id', '=', 'm.job_id')
            ->selectRaw("m.id as member_id, m.name, m.phone, COALESCE(NULLIF(c.name_en, ''), c.name_ar) as company, m.job_id,
                COALESCE(NULLIF(j.name_en, ''), j.name_ar) as occupation, $first as first_visit, r.last_visit, r.last_reach,
                COALESCE(r.reservations, 0) as reservations, r.commitment, COALESCE(r.cancelled, 0) as cancelled, COALESCE(r.in_range, 0) as in_range,
                COALESCE(pd.paid, 0) as paid, COALESCE(bl.balance, 0) as balance");

        if ($filters['search'] !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $filters['search']) . '%';
            $query->where(fn ($query) => $query->where('m.name', 'like', $like)->orWhere('m.phone', 'like', $like)
                ->orWhere('c.name_en', 'like', $like)->orWhere('c.name_ar', 'like', $like));
        }

        return DB::query()->fromSub($query, 't');
    }

    // the rows of a member tab (new | top | inactive)
    public function tab($accountId, array $filters, ?string $view = null)
    {
        $view ??= $filters['view'];
        [$from, $to] = $this->bounds($filters);
        $query = $this->members($accountId, $filters);
        if ($filters['commitment']) {
            $query->where('t.commitment', '<', $filters['commitment']);
        }

        return match ($view) {
            'new' => $query->where('t.first_visit', '>=', $from->toDateString())->where('t.first_visit', '<', $to->toDateString()),
            'top' => $query->where('t.paid', '>', 0),
            'inactive' => $query->where('t.reservations', '>=', $filters['min_reservations'])
                ->where('t.last_reach', '<', $this->cutoff($filters))->where('t.last_reach', '>=', $this->windowStart($filters)),
            'occupation' => $query->where(fn ($query) => $query->where('t.in_range', '>', 0)->orWhere('t.paid', '<>', 0)),
        };
    }

    // a member tab's default order
    public function order($query, string $view)
    {
        return match ($view) {
            'top' => $query->orderByDesc('t.paid')->orderBy('t.name'),
            'inactive' => $query->orderByDesc('t.last_reach')->orderBy('t.name'),
            default => $query->orderBy('t.first_visit')->orderBy('t.name'),
        };
    }

    // By occupation: members active in the dates, new ones, paid — one row per occupation (no occupation = "No occupation")
    public function occupations($accountId, array $filters)
    {
        [$from, $to] = $this->bounds($filters);
        return DB::query()->fromSub($this->tab($accountId, $filters, 'occupation'), 'o')
            ->groupBy('o.job_id')
            ->selectRaw("o.job_id, COALESCE(MAX(o.occupation), 'No occupation') as occupation, COUNT(*) as members,
                SUM(o.first_visit >= ? AND o.first_visit < ?) as new_members, ROUND(SUM(o.paid), 2) as paid", [$from->toDateString(), $to->toDateString()]);
    }

    // every tab's count + the chosen tab's totals (members, paid, balance; occupations: occupations / members / paid)
    public function counts($accountId, array $filters): array
    {
        $counts = [];
        foreach (array_keys(self::TABS) as $view) {
            $counts[$view] = $view === 'occupation'
                ? DB::query()->fromSub($this->occupations($accountId, $filters), 'x')->count()
                : (clone $this->tab($accountId, $filters, $view))->count();
        }
        return $counts;
    }

    public function totals($accountId, array $filters): object
    {
        $totals = $filters['view'] === 'occupation'
            ? DB::query()->fromSub($this->occupations($accountId, $filters), 'x')
                ->selectRaw('COUNT(*) as rows_count, COALESCE(SUM(x.members), 0) as members, COALESCE(SUM(x.new_members), 0) as new_members, ROUND(COALESCE(SUM(x.paid), 0), 2) as paid, 0 as balance')->first()
            : $this->tab($accountId, $filters)
                ->selectRaw('COUNT(*) as rows_count, COUNT(*) as members, 0 as new_members, ROUND(COALESCE(SUM(t.paid), 0), 2) as paid, ROUND(COALESCE(SUM(t.balance), 0), 2) as balance')->first();
        foreach (['paid', 'balance'] as $field) {
            $totals->$field = (float) $totals->$field;
        }

        return $totals;
    }
}
