<?php

namespace Modules\ReservationModule\app\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\AccountModule\app\Models\Account;
use Modules\PaymentModule\app\Models\Payment;
use Modules\PaymentModule\app\Models\ShiftClosing;
use Modules\ReservationModule\app\Models\Reservation;
use Modules\ReservationModule\app\Models\ReservationEvent;
use Modules\UserModule\app\Models\User;

/**
 * A data fix (2026-11-06, user): reservations of members that no longer exist (old import: the member was deleted in the old
 * system, no members row at all, not even a soft deleted one) are soft deleted with the reason "Old import — member deleted".
 * Found by rule, never by ids (it runs on the server): reservation not deleted, member_id > 0, no members row with that id.
 * Not deleted, only listed (the user decides): a reservation with payments (any, its payments + their shifts shown), inside a
 * package, or with rows that would point to a deleted reservation (renewals of it, repeat copies, invoice logs, WhatsApp logs,
 * contracts). Deleted with it: its own extras (soft) and reservation_events about it alone.
 * Each deletion: a dated line in its notes + a reservation_events "orphan_deleted" (reason, amount = what it owed, by the
 * account's login). Nothing else changes (paid on its own: no package, no payments → no totals). Dry run by default (the same
 * work in a transaction, rolled back); --run commits. Safe twice (a deleted reservation no longer matches).
 */
class DeleteOrphanReservationsCommand extends Command
{
    const REASON = 'Old import — member deleted';

    protected $signature = 'reservations:delete-orphans {--account= : only this account id} {--run : apply (default = dry run, nothing saved)}';

    protected $description = 'Soft delete the reservations of members that no longer exist (dry run unless --run)';

    public function handle(): int
    {
        $run = (bool) $this->option('run');
        $this->info($run ? 'RUN: changes are saved.' : 'DRY RUN: nothing is saved (add --run to apply).');
        $accounts = Account::query()->when($this->option('account'), fn ($query, $id) => $query->whereKey((int) $id))->get();

        foreach ($accounts as $account) {
            $reservations = Reservation::with('status')
                ->where('account_id', $account->id)->where('member_id', '>', 0)
                ->whereNotExists(fn ($query) => $query->from('members')->whereColumn('members.id', 'reservations.member_id'))
                ->orderBy('member_id')->orderBy('id')->get();
            $this->line('');
            $this->info('Account #' . $account->id . ' ' . $account->name . ': ' . $reservations->count() . ' reservation(s) of '
                . $reservations->pluck('member_id')->unique()->count() . ' missing member(s).');
            if ($reservations->isEmpty()) {
                continue;
            }
            $by = User::where('userable_type', $account->getMorphClass())->where('userable_id', $account->id)->first();
            if (!$by) {
                $this->error('The account has no login (the log needs one). Account skipped.');
                continue;
            }

            DB::beginTransaction();
            try {
                [$deleted, $kept] = $this->apply($reservations, $by);
                $this->report($deleted, $kept);
                if ($run) {
                    DB::commit();
                    $this->info('Saved. Follow-up: none.');
                } else {
                    DB::rollBack();
                    $this->comment('Dry run: rolled back.');
                }
            } catch (\Throwable $e) {
                DB::rollBack();
                throw $e;
            }
        }

        return self::SUCCESS;
    }

    private function apply($reservations, User $by): array
    {
        $ids = $reservations->pluck('id')->all();
        $deleted = [];
        $kept = [];
        foreach ($reservations as $reservation) {
            $payments = Payment::withTrashed()->with('paymentMethod')
                ->where('payable_type', $reservation->getMorphClass())->where('payable_id', $reservation->id)->orderBy('id')->get();
            $blockers = $this->blockers($reservation, $ids);
            if ($payments->isNotEmpty() || $blockers) {
                $kept[] = ['reservation' => $reservation, 'payments' => $payments, 'blockers' => $blockers];
                continue;
            }

            $owed = round((float) $reservation->remaining, 2);
            DB::table('extras')->where('extrable_type', $reservation->getMorphClass())->where('extrable_id', $reservation->id)
                ->whereNull('deleted_at')->update(['deleted_at' => now()]);
            ReservationEvent::where('reservation_id', $reservation->id)
                ->where(fn ($query) => $query->where('related_reservation_id', 0)->orWhereIn('related_reservation_id', $ids))->delete();
            $line = now()->format('d-m-Y H:i') . ' — deleted: ' . self::REASON . ' (member #' . $reservation->member_id . ').';
            $reservation->forceFill(['notes' => trim(($reservation->notes ? $reservation->notes . "\n" : '') . $line)])->save();
            $reservation->delete();
            $event = new ReservationEvent([
                'account_id' => $reservation->account_id, 'reservation_id' => $reservation->id, 'related_reservation_id' => 0,
                'type' => 'orphan_deleted', 'reason' => self::REASON, 'amount' => $owed,
                'data' => ['member_id' => (int) $reservation->member_id, 'net_amount' => (float) $reservation->net_amount],
            ]);
            $event->creatable()->associate($by);
            $event->save();
            $deleted[] = $reservation;
        }

        return [$deleted, $kept];
    }

    // what would still point to the reservation once it is deleted (the user decides)
    private function blockers(Reservation $reservation, array $ids): array
    {
        $morph = $reservation->getMorphClass();
        $checks = [
            'in package #' . $reservation->package_id => $reservation->package_id > 0 ? 1 : 0,
            'renewals of it' => Reservation::where('renewal_of', $reservation->id)->count(),
            'repeat copies' => Reservation::where('repeat_id', $reservation->id)->whereNotIn('id', $ids)->count(),
            'invoice logs' => DB::table('invoice_request_logs')->where('loggable_type', $morph)->where('loggable_id', $reservation->id)->count(),
            'WhatsApp logs' => DB::table('message_logs')->where('messageable_type', $morph)->where('messageable_id', $reservation->id)->count(),
            'contracts' => DB::table('contracts')->where('reservation_id', $reservation->id)->count(),
        ];

        return array_keys(array_filter($checks));
    }

    private function report(array $deleted, array $kept): void
    {
        $money = fn ($value) => number_format((float) $value, 2);
        $period = fn ($reservation) => $reservation->start_at?->format('d-m-Y') . ($reservation->end_at && !$reservation->end_at->isSameDay($reservation->start_at) ? ' → ' . $reservation->end_at->format('d-m-Y') : '');

        $this->table(['Reservation', 'Member id', 'Period', 'Status', 'Net', 'Owed (remaining)'],
            array_map(fn ($reservation) => ['#' . $reservation->id, '#' . $reservation->member_id, $period($reservation),
                $reservation->status?->name, $money($reservation->net_amount), $money($reservation->remaining)], $deleted));
        $this->info('To delete: ' . count($deleted) . ' reservation(s); owed removed: '
            . $money(array_sum(array_map(fn ($reservation) => (float) $reservation->remaining, $deleted))) . '.');

        if (!$kept) {
            return;
        }
        $this->warn('Kept (not deleted, you decide): ' . count($kept) . ' reservation(s).');
        $closings = ShiftClosing::with('user.userable')->whereIn('id', collect($kept)->flatMap(fn ($item) => $item['payments']->pluck('shift_closing_id'))->filter()->unique())
            ->get()->keyBy('id');
        $rows = [];
        foreach ($kept as $item) {
            $reservation = $item['reservation'];
            $why = array_merge($item['payments']->isNotEmpty() ? ['payments'] : [], $item['blockers']);
            if ($item['payments']->isEmpty()) {
                $rows[] = ['#' . $reservation->id, '#' . $reservation->member_id, $period($reservation), $money($reservation->remaining), implode(', ', $why), '-', '-'];
                continue;
            }
            foreach ($item['payments'] as $index => $payment) {
                $closing = $closings[$payment->shift_closing_id] ?? null;
                $shift = $payment->shift_closing_id
                    ? 'closing #' . $payment->shift_closing_id . ($closing ? ' · ' . ($closing->user?->userable?->name ?? 'login #' . $closing->user_id)
                        . ($closing->is_imported ? ' · Imported' : '') . ' · closed ' . $closing->closed_at?->format('d-m-Y') : '')
                    : 'open shift';
                $rows[] = $index === 0
                    ? ['#' . $reservation->id, '#' . $reservation->member_id, $period($reservation), $money($reservation->remaining), implode(', ', $why),
                        'No. ' . $payment->payment_no . ' · ' . $payment->type . ' · ' . $money($payment->amount) . ' · ' . $payment->paid_at?->format('d-m-Y')
                            . ' · ' . ($payment->paymentMethod?->name ?? '-') . ($payment->deleted_at ? ' · DELETED' : ''), $shift]
                    : ['', '', '', '', '', 'No. ' . $payment->payment_no . ' · ' . $payment->type . ' · ' . $money($payment->amount) . ' · ' . $payment->paid_at?->format('d-m-Y'), $shift];
            }
        }
        $this->table(['Reservation', 'Member id', 'Period', 'Owed', 'Why kept', 'Payments', 'Shift'], $rows);
    }
}
