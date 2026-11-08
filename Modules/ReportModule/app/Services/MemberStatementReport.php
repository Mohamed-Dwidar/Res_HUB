<?php

namespace Modules\ReportModule\app\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\MemberModule\app\Models\Member;
use Modules\PackageModule\app\Models\Package;
use Modules\PaymentModule\app\Models\Extra;
use Modules\PaymentModule\app\Models\Payment;
use Modules\ReservationModule\app\Models\Reservation;

/**
 * Report 2 — Member statement (docs/reports-plan.md §2): one member's charges and payments in date order with a running balance.
 * Charges: his reservations paid on their own (package_id 0; what it costs = Reservation::DUE_SQL, a not counted status = 0 with
 * its status name), dated by the START; his packages (after_discount, dated by date_from, else the day it was made); their extras + his own reservations' extras (2026-11-08, with their taxes)
 * (dated by the extra's date; an adjustment = a balance move, may be negative). Payments of those reservations / packages:
 * cash in + / withholding + / refunds − (paid_at). Reservations inside a package = information lines at 0 (optional).
 * Balance > 0 = the member owes, < 0 = credit; the closing balance with no date limit = Σ the stored remaining of those rows
 * (= his Net in the Outstanding report, future periods included). Opening = every line before the range (SQL sums);
 * the lines of the range are one member's: read into PHP (descriptions, running balance).
 */
class MemberStatementReport
{
    public function filters(array $input): array
    {
        $date = fn ($value) => $value && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
        return [
            'member_id' => (int) ($input['member_id'] ?? 0),
            'date_from' => $date($input['date_from'] ?? null),
            'date_to' => $date($input['date_to'] ?? null),
            'package_lines' => !empty($input['package_lines']),
        ];
    }

    public function member($accountId, int $memberId): ?Member
    {
        return $memberId ? Member::withTrashed()->with('company')->where('account_id', $accountId)->find($memberId) : null;
    }

    // every line of the member: kind | id | day | sort | charge | payment (UNION ALL, plain SQL; soft deleted rows left out)
    private function lines($accountId, int $memberId, bool $packageLines)
    {
        $reservationType = (new Reservation())->getMorphClass();
        $packageType = (new Package())->getMorphClass();
        $ownReservations = DB::table('reservations')->where('account_id', $accountId)->where('member_id', $memberId)
            ->where('package_id', 0)->whereNull('deleted_at')->select('id');
        $packages = DB::table('packages')->where('account_id', $accountId)->where('member_id', $memberId)
            ->whereNull('deleted_at')->select('id');

        $lines = DB::table('reservations')
            ->join('reservation_statuses', 'reservation_statuses.id', '=', 'reservations.reservation_status_id')
            ->where('reservations.account_id', $accountId)->where('reservations.member_id', $memberId)
            ->where('reservations.package_id', 0)->whereNull('reservations.deleted_at')
            ->selectRaw("'reservation' as kind, reservations.id, DATE(reservations.start_at) as day, 1 as sort,
                IF(reservation_statuses.is_counted = 1, " . Reservation::DUE_SQL . ", 0) as charge, 0 as payment")
            ->unionAll(DB::table('packages')->where('account_id', $accountId)->where('member_id', $memberId)->whereNull('deleted_at')
                ->selectRaw("'package', id, COALESCE(date_from, DATE(created_at)), 2, after_discount, 0"))
            ->unionAll(DB::table('extras')->where('extrable_type', $packageType)->whereIn('extrable_id', $packages)->whereNull('deleted_at')
                ->selectRaw("IF(type = 'adjustment', 'adjustment', 'extra'), id, COALESCE(date, DATE(created_at)), 3, amount, 0"))
            // the extras of his own reservations (2026-11-08): with their taxes when the invoice is requested (= extras_total)
            ->unionAll(DB::table('extras')->where('extrable_type', $reservationType)->whereIn('extrable_id', $ownReservations)->whereNull('deleted_at')
                ->selectRaw("'extra', id, COALESCE(date, DATE(created_at)), 3, COALESCE(tax_total, amount), 0"))
            ->unionAll(DB::table('payments')->whereNull('deleted_at')->where('account_id', $accountId)
                ->where(fn ($query) => $query
                    ->where(fn ($query) => $query->where('payable_type', $reservationType)->whereIn('payable_id', $ownReservations))
                    ->orWhere(fn ($query) => $query->where('payable_type', $packageType)->whereIn('payable_id', $packages)))
                ->selectRaw("'payment', id, DATE(paid_at), 5, 0, " . Payment::PAID_SQL));

        if ($packageLines) {
            $lines->unionAll(DB::table('reservations')->where('account_id', $accountId)->whereIn('package_id', $packages)
                ->whereNull('deleted_at')->selectRaw("'package_reservation', id, DATE(start_at), 4, 0, 0"));
        }

        return DB::query()->fromSub($lines, 'l');
    }

    /**
     * The statement: member, opening, lines of the range (with description fields + running balance), totals, closing.
     * Null without a member.
     */
    public function statement($accountId, array $filters): ?array
    {
        $member = $this->member($accountId, $filters['member_id']);
        if (!$member) {
            return null;
        }
        $from = $filters['date_from'];
        $to = $filters['date_to'] ?: ($from ?: null);

        $opening = $from
            ? (float) $this->lines($accountId, $member->id, false)->where('day', '<', $from)->sum(DB::raw('charge - payment'))
            : 0.0;
        $rows = $this->lines($accountId, $member->id, $filters['package_lines'])
            ->when($from, fn ($query) => $query->where('day', '>=', $from))
            ->when($to, fn ($query) => $query->where('day', '<=', $to))
            ->orderBy('day')->orderBy('sort')->orderBy('id')->get();

        $models = $this->models($rows);
        $balance = round($opening, 2);
        $lines = [];
        foreach ($rows as $row) {
            $balance = round($balance + $row->charge - $row->payment, 2);
            $lines[] = ['day' => Carbon::parse($row->day), 'kind' => $row->kind, 'model' => $models[$row->kind][$row->id] ?? null,
                'charge' => (float) $row->charge, 'payment' => (float) $row->payment, 'balance' => $balance];
        }
        $charges = round(array_sum(array_column($lines, 'charge')), 2);
        $payments = round(array_sum(array_column($lines, 'payment')), 2);

        return [
            'member' => $member,
            'from' => $from ? Carbon::parse($from) : null,
            'to' => $to ? Carbon::parse($to) : null,
            'opening' => round($opening, 2),
            'lines' => $lines,
            'charges' => $charges,
            'payments' => $payments,
            'closing' => round($opening + $charges - $payments, 2),
        ];
    }

    // the models of the lines (one query per kind, their relations eager loaded)
    private function models($rows): array
    {
        $ids = fn ($kind) => $rows->where('kind', $kind)->pluck('id')->all();
        $reservations = Reservation::withTrashed()->with(['space', 'unit', 'plan', 'status', 'subscriptionType'])
            ->whereIn('id', array_merge($ids('reservation'), $ids('package_reservation')))->get()->keyBy('id');

        $extras = Extra::withTrashed()->whereIn('id', array_merge($ids('extra'), $ids('adjustment')))->get()->keyBy('id');

        return [
            'reservation' => $reservations,
            'package_reservation' => $reservations,
            'package' => Package::withTrashed()->whereIn('id', $ids('package'))->get()->keyBy('id'),
            'extra' => $extras,
            'adjustment' => $extras,
            'payment' => Payment::withTrashed()->with('paymentMethod')->whereIn('id', $ids('payment'))->get()->keyBy('id'),
        ];
    }

    // the text of a line: [type, description] (the page, the PDF and the Excel)
    public static function describe(array $line): array
    {
        $model = $line['model'];
        $dateFormat = Reservation::DATE_FORMAT;
        $period = function ($reservation) use ($dateFormat) {
            $start = $reservation->start_at;
            $end = $reservation->end_at;
            if (!$end || $start->isSameDay($end)) {
                return $start->format($dateFormat) . ($reservation->subscriptionType?->isFullDay() ? '' : ' ' . $start->format('h:i A') . ($end ? '–' . $end->format('h:i A') : ''));
            }
            return $start->format($dateFormat) . ' – ' . $end->format($dateFormat);
        };
        $place = fn ($reservation) => trim(($reservation->space?->name ?? '-') . ' › ' . ($reservation->unit?->name ?? '-'));

        switch ($line['kind']) {
            case 'reservation':
                if (!$model) return ['Reservation', '-'];
                $text = '#' . $model->id . ' · ' . $place($model) . ($model->plan ? ' · ' . $model->plan->name : '') . ' · ' . $period($model);
                if (!$model->isCounted()) {
                    $text .= ' · ' . ($model->status?->name ?? 'Cancelled');
                } elseif ($model->isTaxed()) {
                    $text .= ' · Invoice (incl. taxes)';
                }
                return ['Reservation', $text];
            case 'package_reservation':
                if (!$model) return ['In package', '-'];
                return ['In package', '#' . $model->id . ' · ' . $place($model) . ' · ' . $period($model) . ' · package #' . $model->package_id
                    . ($model->isCounted() ? '' : ' · ' . ($model->status?->name ?? 'Cancelled'))];
            case 'package':
                if (!$model) return ['Package', '-'];
                $discount = (float) $model->discount_percentage;
                return ['Package', '#' . $model->id . ' · ' . $model->name
                    . ($discount > 0 ? ' · ' . number_format((float) $model->amount, 2) . ' − ' . rtrim(rtrim(number_format($discount, 2), '0'), '.') . '%' : '')];
            case 'extra':
            case 'adjustment':
                if (!$model) return ['Extra', '-'];
                $adjustment = $model->type === 'adjustment';
                return [$adjustment ? 'Adjustment' : 'Extra', $model->item . ($adjustment ? '' : ' · ' . rtrim(rtrim(number_format((float) $model->quantity, 2), '0'), '.') . ' × ' . number_format((float) $model->unit_price, 2))
                    . ' · ' . ($model->extrable_type === (new Reservation())->getMorphClass() ? 'reservation' : 'package') . ' #' . $model->extrable_id];
            case 'payment':
                if (!$model) return ['Cash In', '-'];
                $type = ['payment' => 'Cash In', 'withholding' => 'Withholding', 'refund' => 'Refund'][$model->type] ?? 'Cash In';
                $for = $model->payable_type === (new Package())->getMorphClass() ? 'package #' . $model->payable_id : 'reservation #' . $model->payable_id;
                return [$type, 'No. ' . $model->payment_no . ' · ' . ($model->type === 'withholding' ? 'certificate' : ($model->paymentMethod?->name ?? '-')) . ' · ' . $for];
        }

        return ['', ''];
    }
}
