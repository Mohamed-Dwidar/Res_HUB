<?php

namespace Modules\ReservationModule\app\Services;

use Illuminate\Support\Carbon;
use Modules\ReservationModule\app\Models\Reservation;

/**
 * What one renewal row shows (user 2026-10-29) — the Schedule period groups (Schedule/partials/renewal-row) and the reservations
 * list Renewal column (Reservation/partials/row-renewal) use the same rules. Columns: Member | Unit | Renewal date | Price |
 * Payment | Action. $kind:
 * - new (Starts today): this period — its renewal date, its amount, "This period" + its badge, Pay while it owes;
 * - today / next / upcoming (Renews today, Next N days, the list's Upcoming): the RENEWAL of this period — no renewal: "Not renewed
 *   yet" + Renew (+ Pay first while this period owes; today: + Left); renewal owing: "Renewed · Unpaid / Partially paid" + Pay
 *   (the renewal); renewal paid: "✓ Renewed & paid"; the name opens the renewal when it exists;
 * - owing (Not renewed & Not paid): this period — "due since" its start (owing) or its renewal date (paid, ended, no renewal), its
 *   amount, Current / Ended period (+ dates) and its badge; Pay while it owes, Renew when it is paid; Left when it ended with no
 *   renewal; "Older period still owes X" ($reservation->older_due, ScheduleService::periodGroups());
 * - renewed (the list's Renewed): "✓ Renewed & paid"; left: "Left" + Undo.
 * $date = the selected day (the Schedule date / the list's last day, the business date at most); $isToday: "today" / "tomorrow".
 */
class RenewalView
{
    const BADGES = [
        'paid' => ['Paid', 'badge-status-success'],
        'partial' => ['Partially paid', 'badge-status-warning'],
        'unpaid' => ['Unpaid', 'badge-status-danger'],
        'package' => ['In package', 'badge-status-slate'],
    ];

    public static function make(Reservation $reservation, string $kind, Carbon $date, bool $isToday = true): array
    {
        $format = Reservation::DATE_FORMAT;
        $owes = fn ($row) => $row && $row->canTakePayments() && $row->remaining > 0;
        $state = fn ($row) => !$row->canTakePayments() ? 'package' : ($row->remaining <= 0 ? 'paid' : ($row->paid_amount > 0 ? 'partial' : 'unpaid'));
        $due = fn ($row) => 'Due ' . number_format($row->remaining, 2)
            . ($state($row) === 'partial' ? ' of ' . number_format($row->dueAmount(), 2) : '');
        $dates = fn ($row) => $row->start_at?->format($format) . ' → ' . ($row->realEnd()?->format($format) ?? '-');
        $renewal = $reservation->renewedBy;
        $renewsOn = $reservation->renewalDate();
        $date = $date->copy()->startOfDay();

        $view = [
            'open' => $reservation, 'datePrefix' => null, 'date' => $renewsOn, 'dateTo' => null, 'when' => self::when($renewsOn, $date, $isToday), 'whenClass' => 'text-slate-500',
            'price' => $reservation->dueAmount(), 'label' => null, 'labelDates' => null, 'badge' => null, 'badges' => [], 'check' => null,
            'note' => null, 'due' => null, 'hints' => [], 'actions' => [],
        ];

        switch ($kind) {
            case 'new':
                $view['label'] = 'This period';
                $view['labelDates'] = $dates($reservation);
                $view['badge'] = self::BADGES[$state($reservation)];
                if ($owes($reservation)) {
                    $view['due'] = $due($reservation);
                    $view['actions'][] = ['pay', $reservation, 'btn-pay'];
                }
                break;

            case 'today':
            case 'next':
            case 'upcoming':
                if ($renewal) {
                    $view['open'] = $renewal;
                    $view['price'] = $renewal->dueAmount();
                    $view['labelDates'] = $dates($renewal);
                    if ($owes($renewal)) {
                        $view['badge'] = ['Renewed & ' . self::BADGES[$state($renewal)][0], self::BADGES[$state($renewal)][1]];
                        $view['due'] = $due($renewal);
                        $view['actions'][] = ['pay', $renewal, 'btn-pay'];
                    } else {
                        $view['check'] = $state($renewal) === 'package' ? 'Renewed · In package' : 'Renewed & paid';
                    }
                } else {
                    $view['badge'] = ['Not renewed yet', 'badge-status-slate'];
                    // no Pay for the current period's debt here: it is listed (with Pay) in Not renewed & Not paid (user
                    // 2026-10-29); Renew of an unpaid period asks a reason
                    if ($reservation->canRenew()) {
                        $view['actions'][] = ['renew', $reservation, 'btn-primary'];
                    }
                    if ($kind === 'today') {
                        $view['actions'][] = ['left', $reservation, 'btn-secondary'];
                    }
                }
                break;

            case 'owing':
                // Date = the period (start → end) + "due since (N days)"; Status (centred) = two coloured badges: the renewal
                // (Renewed / Not Renewed) and the payment (Unpaid / Partially paid; none when paid), user 2026-10-29
                $owing = $owes($reservation);
                $ended = $renewsOn && $renewsOn->lte($date);
                $since = $owing ? $reservation->start_at->copy()->startOfDay() : $renewsOn;
                $days = $since ? (int) $since->diffInDays($date, false) : 0;
                $view['date'] = $reservation->start_at;
                $view['dateTo'] = $reservation->realEnd();
                $view['when'] = 'due since (' . ($days > 0 ? $days . ' ' . ($days === 1 ? 'day' : 'days') : 'today') . ')';
                $view['whenClass'] = 'text-danger';
                if ($ended && !$renewal) {
                    $view['badges'][] = ['Not Renewed', 'badge-status-pending'];
                } elseif ($reservation->renewal_of) {
                    $view['badges'][] = ['Renewed', 'badge-status-primary'];
                }
                if ($owing) {
                    $view['badges'][] = self::BADGES[$state($reservation)];
                    $view['due'] = $due($reservation);
                    $view['actions'][] = ['pay', $reservation, 'btn-pay'];
                } elseif ($ended && !$renewal && $reservation->canRenew()) {
                    $view['actions'][] = ['renew', $reservation, 'btn-primary'];
                }
                if ($ended && !$renewal) {
                    $view['actions'][] = ['left', $reservation, 'btn-secondary'];
                }
                if (($reservation->older_due ?? 0) > 0) {
                    $view['hints'][] = ['Older period still owes ' . number_format($reservation->older_due, 2), null, null];
                }
                break;

            case 'renewed':
                if ($renewal) {
                    $view['open'] = $renewal;
                    $view['price'] = $renewal->dueAmount();
                    $view['labelDates'] = $dates($renewal);
                }
                $view['check'] = 'Renewed & paid';
                break;

            case 'left':
                $view['badge'] = ['Left', 'badge-status-slate'];
                $view['actions'][] = ['undo', $reservation, 'btn-secondary'];
                break;
        }

        return $view;
    }

    // "today" / "tomorrow" / "in N days" / "N days late" (from the selected day)
    private static function when(?Carbon $on, Carbon $date, bool $isToday): ?string
    {
        if (!$on) {
            return null;
        }
        $days = (int) $date->diffInDays($on->copy()->startOfDay(), false);
        return match (true) {
            $days === 0 => $isToday ? 'today' : 'this day',
            $days === 1 && $isToday => 'tomorrow',
            $days > 0 => 'in ' . $days . ' days',
            default => abs($days) . ' ' . (abs($days) === 1 ? 'day' : 'days') . ' late',
        };
    }
}
