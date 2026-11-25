<?php

namespace Modules\ContractModule\app\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\AccountModule\app\Models\Account;
use Modules\ContractModule\app\Models\Contract;
use Modules\ContractModule\app\Services\ContractService;
use Modules\MemberModule\app\Models\Member;
use Modules\ReservationModule\app\Models\Reservation;
use Modules\ReservationModule\app\Models\ReservationStatus;
use Modules\SpaceModule\app\Models\SubscriptionType;
use Modules\UnitModule\app\Models\Unit;

/**
 * Unit numbers of existing data (2026-11-25, user; the number belongs to the CONTRACT): reports (1) members whose name holds a unit
 * number ("(V-103)", "V151" …) with their current subscription of a numbered / shared unit and its contract, (2) current subscriptions
 * of those units whose contract has no unit number (or no contract). --run fills the number taken from the name into the member's
 * current contract when it is a DRAFT with no number and the number is free in the unit (an issued contract is printed from its
 * snapshot: never changed, listed). Dry run by default; the lists also go to a CSV (storage/app/private/exports/). Safe twice.
 */
class UnitNumbersCommand extends Command
{
    const PATTERN = '/(?<![a-z])([a-z]{1,2})[ ._\-]*(\d{2,4})(?!\d)/iu';

    protected $signature = 'contracts:unit-numbers {--account=1 : the account id} {--run : fill the numbers (default = dry run)}';

    protected $description = 'Report unit numbers in member names / subscriptions without a contract unit number; --run fills draft contracts from the names';

    public function handle(ContractService $service): int
    {
        $run = (bool) $this->option('run');
        $account = Account::find((int) $this->option('account'));
        if (!$account) {
            $this->error('No such account.');
            return self::FAILURE;
        }
        $this->info(($run ? 'RUN' : 'DRY RUN (add --run to fill)') . ': account #' . $account->id . ' ' . $account->name);
        // the numbered (a prefix / first number) or shared (more than one place) units
        // the numbered units (a prefix / first number set in the unit form); none yet = the shared ones (more than one place)
        $units = Unit::where('account_id', $account->id)->where(fn ($query) => $query->whereNotNull('unit_no_prefix')->orWhereNotNull('unit_no_start'))->get()->keyBy('id');
        if ($units->isEmpty()) {
            $units = Unit::where('account_id', $account->id)->where('concurrent_usage', '>', 1)->get()->keyBy('id');
            $this->warn('No unit has a Unit No. prefix yet (unit form › Contract Templates): listing the shared units.');
        }
        $today = Reservation::businessToday()[0];
        $current = Reservation::where('account_id', $account->id)->whereIn('unit_id', $units->keys()->all() ?: [0])
            ->whereIn('subscription_type_id', SubscriptionType::idsOf('period') ?: [0])->whereNotIn('reservation_status_id', ReservationStatus::notCountedIds() ?: [0])
            ->whereNull('left_at')->where('member_id', '>', 0)->where('end_at', '>=', $today)->with('member', 'unit')->orderBy('start_at')->get();

        DB::beginTransaction();
        $rows1 = [];
        $filled = 0;
        foreach (Member::where('account_id', $account->id)->get(['id', 'name', 'phone']) as $member) {
            if (!preg_match(self::PATTERN, $member->name, $match)) {
                continue;
            }
            // the member's newest current subscription, a unit whose prefix matches the name's letters first ("V151" → the "V " unit)
            $letters = strtoupper($match[1]);
            $reservation = $current->where('member_id', $member->id)
                ->sortByDesc(fn ($row) => [strtoupper(trim((string) $row->unit?->unit_no_prefix)) === $letters ? 1 : 0, $row->start_at->format('YmdHis')])->first();
            $unit = $reservation?->unit;
            $number = ($unit && (string) $unit->unit_no_prefix !== '' ? $unit->unit_no_prefix : strtoupper($match[1]) . ' ') . $match[2];
            $contract = $reservation ? $service->coveringContract($reservation) : null;
            $contract = $contract ? Contract::find($contract->id) : null;
            $state = match (true) {
                !$reservation => 'no current subscription in a numbered / shared unit',
                !$contract => 'no contract yet (the number goes on it when it is made)',
                trim((string) $contract->unit_number) !== '' => 'contract ' . $contract->number_label . ' already has ' . $contract->unit_number,
                $contract->status !== 'draft' => 'contract ' . $contract->number_label . ' is ' . $contract->status_label . ': not changed',
                default => null,
            };
            if ($state === null) {
                $holder = $service->heldNumbers((int) $account->id, (int) $unit->id)->get(mb_strtoupper(str_replace([' ', '-', '.'], '', $number)));
                if ($holder) {
                    $state = $number . ' is held by contract ' . ($holder->contract_no ? '#' . $holder->contract_no : 'draft #' . $holder->id) . ': not filled';
                } else {
                    $contract->forceFill(['unit_number' => $number])->save();
                    $filled++;
                    $state = ($run ? 'filled' : 'would fill') . ' draft #' . $contract->id;
                }
            }
            $rows1[] = [$member->id, $member->name, $member->phone, $number, $reservation ? '#' . $reservation->id . ' ' . $unit?->name : '-', $state];
        }
        $this->line('');
        $this->info('1) Members whose name holds a unit number: ' . count($rows1));
        $this->table(['Member', 'Name', 'Phone', 'Number', 'Current subscription', 'Result'], $rows1);

        $rows2 = [];
        foreach ($current as $reservation) {
            $contract = $service->coveringContract($reservation);
            if (!$contract || trim((string) $contract->unit_number) === '') {
                $rows2[] = [$reservation->id, $reservation->member?->name, $reservation->unit?->name, $reservation->start_at->format('d-m-Y') . ' → ' . $reservation->realEnd()?->format('d-m-Y'),
                    $contract ? 'contract ' . $contract->number_label . ' without a number' : 'no contract'];
            }
        }
        $this->line('');
        $this->info('2) Current subscriptions of numbered / shared units without a contract unit number: ' . count($rows2));
        $this->table(['Reservation', 'Member', 'Unit', 'Period', 'Contract'], $rows2);

        $run ? DB::commit() : DB::rollBack();
        $file = 'exports/unit-numbers-account' . $account->id . '-' . now()->format('Ymd-His') . '.csv';
        $csv = fopen('php://temp', 'r+');
        fwrite($csv, "\xEF\xBB\xBF");
        fputcsv($csv, ['1) Members whose name holds a unit number']);
        fputcsv($csv, ['Member', 'Name', 'Phone', 'Number', 'Current subscription', 'Result']);
        foreach ($rows1 as $row) {
            fputcsv($csv, $row);
        }
        fputcsv($csv, []);
        fputcsv($csv, ['2) Current subscriptions without a contract unit number']);
        fputcsv($csv, ['Reservation', 'Member', 'Unit', 'Period', 'Contract']);
        foreach ($rows2 as $row) {
            fputcsv($csv, $row);
        }
        rewind($csv);
        Storage::disk('local')->put($file, stream_get_contents($csv));
        $this->line('');
        $this->info(($run ? 'Saved: ' . $filled . ' filled.' : 'Dry run: ' . $filled . ' would be filled, nothing saved.') . ' CSV: storage/app/private/' . $file);
        return self::SUCCESS;
    }
}
