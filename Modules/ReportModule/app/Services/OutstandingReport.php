<?php

namespace Modules\ReportModule\app\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\PackageModule\app\Models\Package;
use Modules\ReservationModule\app\Models\Reservation;

/**
 * Report 1 — Outstanding & credits (docs/reports-plan.md §1): who owes / who has credit, TODAY's stored balances
 * (`remaining`, kept by PaymentService::refresh()). Rows = reservations paid on their own (package_id 0) + packages with
 * remaining <> 0, grouped by member in SQL (never all rows into PHP).
 * Rules (user 2026-11-06): the date filter = the row's start (reservation start_at, package date_from); future rows (start after
 * the business date) are left out of Owed unless "Include future"; a credit always counts; a type / space / plan filter leaves
 * the packages out (a package has no single space / plan).
 */
class OutstandingReport
{
    const SHOW = ['owed' => 'Owed only', 'credit' => 'Credit only'];

    // the request's filters, cleaned
    public function filters(array $input): array
    {
        $date = fn ($value) => $value && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
        return [
            'search' => trim((string) ($input['search'] ?? '')),
            'date_from' => $date($input['date_from'] ?? null),
            'date_to' => $date($input['date_to'] ?? null),
            'subscription_type_id' => (int) ($input['subscription_type_id'] ?? 0),
            'space_id' => (int) ($input['space_id'] ?? 0),
            'plan_id' => (int) ($input['plan_id'] ?? 0),
            'show' => array_key_exists($input['show'] ?? '', self::SHOW) ? $input['show'] : '',
            'include_future' => !empty($input['include_future']),
        ];
    }

    // the first moment that counts as "future": the day after the business date, 00:00
    private function futureFrom(): Carbon
    {
        [$today] = Reservation::businessToday();
        return $today->copy()->startOfDay()->addDay();
    }

    private function placeFiltered(array $filters): bool
    {
        return $filters['subscription_type_id'] || $filters['space_id'] || $filters['plan_id'];
    }

    // the reservations part (Eloquent: soft deletes applied); $memberId = one member (the popup)
    public function reservations($accountId, array $filters, ?int $memberId = null)
    {
        $query = Reservation::query()
            ->where('reservations.account_id', $accountId)
            ->where('reservations.package_id', 0)
            ->where('reservations.remaining', '<>', 0);

        if ($memberId !== null) {
            $query->where('reservations.member_id', $memberId);
        }
        if ($filters['date_from']) {
            $query->where('reservations.start_at', '>=', $filters['date_from'] . ' 00:00:00');
        }
        if ($filters['date_to']) {
            $query->where('reservations.start_at', '<', Carbon::parse($filters['date_to'])->addDay()->toDateString() . ' 00:00:00');
        }
        foreach (['subscription_type_id', 'space_id', 'plan_id'] as $field) {
            if ($filters[$field]) {
                $query->where('reservations.' . $field, $filters[$field]);
            }
        }
        $this->showFilter($query, 'reservations.remaining', $filters);
        if (!$filters['include_future']) {
            $future = $this->futureFrom();
            $query->where(fn ($query) => $query->where('reservations.remaining', '<', 0)->orWhere('reservations.start_at', '<', $future));
        }

        return $query;
    }

    // the packages part; null with a type / space / plan filter
    public function packages($accountId, array $filters, ?int $memberId = null)
    {
        if ($this->placeFiltered($filters)) {
            return null;
        }

        $start = 'COALESCE(packages.date_from, DATE(packages.created_at))';
        $query = Package::query()
            ->where('packages.account_id', $accountId)
            ->where('packages.remaining', '<>', 0);

        if ($memberId !== null) {
            $query->where('packages.member_id', $memberId);
        }
        if ($filters['date_from']) {
            $query->whereRaw($start . ' >= ?', [$filters['date_from']]);
        }
        if ($filters['date_to']) {
            $query->whereRaw($start . ' <= ?', [$filters['date_to']]);
        }
        $this->showFilter($query, 'packages.remaining', $filters);
        if (!$filters['include_future']) {
            $future = $this->futureFrom()->toDateString();
            $query->where(fn ($query) => $query->where('packages.remaining', '<', 0)->orWhereRaw($start . ' < ?', [$future]));
        }

        return $query;
    }

    private function showFilter($query, string $column, array $filters): void
    {
        if ($filters['show'] === 'owed') {
            $query->where($column, '>', 0);
        } elseif ($filters['show'] === 'credit') {
            $query->where($column, '<', 0);
        }
    }

    // every row: member_id | remaining | started_at (a plain query, UNION ALL)
    private function rows($accountId, array $filters)
    {
        $rows = $this->reservations($accountId, $filters)->toBase()
            ->select('reservations.member_id', 'reservations.remaining', 'reservations.start_at as started_at');

        $packages = $this->packages($accountId, $filters);
        if ($packages) {
            $rows->unionAll($packages->toBase()
                ->selectRaw('packages.member_id, packages.remaining, COALESCE(packages.date_from, DATE(packages.created_at)) as started_at'));
        }

        return $rows;
    }

    // the rows joined to their member, the search applied (name / phone)
    private function joined($accountId, array $filters)
    {
        $query = DB::query()->fromSub($this->rows($accountId, $filters), 't')
            ->leftJoin('members as m', 'm.id', '=', 't.member_id')
            ->leftJoin('companies as c', 'c.id', '=', 'm.company_id');

        if ($filters['search'] !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $filters['search']) . '%';
            $query->where(fn ($query) => $query->where('m.name', 'like', $like)->orWhere('m.phone', 'like', $like));
        }

        return $query;
    }

    // one row per member (the DataTables source / the Excel): member_id, member_name, phone, company, owed, credit, net, periods, oldest
    public function query($accountId, array $filters)
    {
        $grouped = $this->joined($accountId, $filters)
            ->groupBy('t.member_id')
            ->selectRaw("t.member_id,
                MAX(m.name) as member_name, MAX(m.phone) as phone,
                MAX(COALESCE(NULLIF(c.name_en, ''), c.name_ar)) as company,
                SUM(GREATEST(t.remaining, 0)) as owed,
                SUM(GREATEST(-t.remaining, 0)) as credit,
                SUM(t.remaining) as net,
                COUNT(*) as periods,
                MIN(CASE WHEN t.remaining > 0 THEN t.started_at END) as oldest");

        return DB::query()->fromSub($grouped, 'o');
    }

    // the totals row: owed, credit, net, members, periods
    public function totals($accountId, array $filters): object
    {
        return $this->joined($accountId, $filters)->selectRaw('
            COALESCE(SUM(GREATEST(t.remaining, 0)), 0) as owed,
            COALESCE(SUM(GREATEST(-t.remaining, 0)), 0) as credit,
            COALESCE(SUM(t.remaining), 0) as net,
            COUNT(DISTINCT t.member_id) as members,
            COUNT(*) as periods')->first();
    }

    // the popup: one member's rows (reservations + packages), oldest first
    public function memberRows($accountId, int $memberId, array $filters): array
    {
        $reservations = $this->reservations($accountId, $filters, $memberId)
            ->with(['space', 'unit.color', 'plan', 'status'])
            ->orderBy('start_at')->get();

        $packages = $this->packages($accountId, $filters, $memberId)?->withSum('extras', 'amount')->orderBy('date_from')->get() ?? collect();

        return ['reservations' => $reservations, 'packages' => $packages];
    }
}
