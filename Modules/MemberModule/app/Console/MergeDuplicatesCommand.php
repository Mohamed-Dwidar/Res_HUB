<?php

namespace Modules\MemberModule\app\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\MemberModule\app\Models\Member;
use Modules\PackageModule\app\Models\Package;
use Modules\PaymentModule\app\Services\PaymentService;
use Modules\ReservationModule\app\Models\Reservation;

/**
 * Merge the members of an account that share one phone (by phone_key, Member::phoneKey(); user 2026-10-18, to_run STEP 5c).
 * Per group: the primary = the most reservations (tie: the lowest id); the others' references (reservations, packages,
 * payments, withholding certificates — every member_id in the schema) move to it; its empty fields (email, company, national
 * number, job, source) are filled from them, never overwritten; their notes are appended. The others are soft deleted with
 * merged_into = the primary and phone_key = NULL (the unique index (account_id, phone_key) ignores them). Never a real delete.
 * Then paid / remaining of the touched reservations / packages are refreshed (PaymentService::refresh, Package::recalculate)
 * and their totals must be unchanged (a difference = the group is rolled back).
 * A group whose names differ is NOT merged (listed as skipped): one phone typed for two people needs a person to decide.
 * Safe to run again (merged members are deleted: no group is found twice).
 */
class MergeDuplicatesCommand extends Command
{
    // the member references (search the schema for member_id: these are all of them, 2026-10-18)
    const REFERENCES = ['reservations', 'packages', 'payments', 'withholding_certificates'];
    // filled on the primary when it has none (0 = none for the ids)
    const FILL = ['email', 'company_id', 'national_number', 'id_type', 'nationality', 'address', 'job_id', 'from_where'];

    protected $signature = 'members:merge-duplicates {--dry-run : print the groups and the rows to move, nothing is saved} {--account= : only this account id}';

    protected $description = 'Merge the members of an account that share one phone (phone_key): references moved to one member, the others soft deleted';

    public function handle(PaymentService $paymentService): int
    {
        $dry = (bool) $this->option('dry-run');
        $groups = Member::query()
            ->when($this->option('account'), fn ($query, $accountId) => $query->where('account_id', (int) $accountId))
            ->orderBy('id')->get()
            ->groupBy(fn ($member) => $member->account_id . '|' . Member::phoneKey($member->phone))
            ->filter(fn ($members, $key) => $members->count() > 1 && !str_ends_with($key, '|'));

        $this->info(($dry ? '[dry run] ' : '') . $groups->count() . ' group(s) of members sharing a phone');
        $merged = 0;
        $moved = array_fill_keys(self::REFERENCES, 0);

        foreach ($groups as $key => $members) {
            $counts = $members->mapWithKeys(fn ($member) => [$member->id => $this->references($member->id)]);
            $names = $members->map(fn ($member) => mb_strtolower(trim(preg_replace('/\s+/u', ' ', $member->name))))->unique();
            $this->line('');
            $this->line('phone_key ' . explode('|', $key)[1] . ' (account ' . explode('|', $key)[0] . ')');
            foreach ($members as $member) {
                $this->line(sprintf('  #%d %s [%s] created %s | %s', $member->id, $member->name, $member->phone, $member->created_at,
                    collect($counts[$member->id])->map(fn ($n, $table) => $table . ' ' . $n)->join(', ')));
            }
            if ($names->count() > 1) {
                $this->warn('  skipped: the names differ (one phone for two people?) — decide by hand');
                continue;
            }

            // the primary: the most reservations, then the lowest id
            $primary = $members->sortBy(fn ($member) => [-$counts[$member->id]['reservations'], $member->id])->first();
            $others = $members->where('id', '!=', $primary->id)->values();
            $fill = $this->fill($primary, $others);
            $this->line('  primary #' . $primary->id . '; merged into it: ' . $others->map(fn ($member) => '#' . $member->id)->join(', '));
            foreach (self::REFERENCES as $table) {
                $rows = $others->sum(fn ($member) => $counts[$member->id][$table]);
                $this->line("  move {$table}: {$rows}");
                $moved[$table] += $rows;
            }
            $this->line('  fill on the primary: ' . ($fill ? collect($fill)->map(fn ($value, $field) => $field . ' = ' . $value)->join(', ') : 'nothing'));

            if (!$dry) {
                $this->merge($primary, $others, $fill, $paymentService);
            }
            $merged += $others->count();
        }

        $this->line('');
        $this->info(($dry ? '[dry run] would merge ' : 'merged ') . $merged . ' member(s); rows moved: '
            . collect($moved)->map(fn ($n, $table) => $table . ' ' . $n)->join(', '));

        return self::SUCCESS;
    }

    // [table => rows] pointing to the member (soft deleted rows too: they move as well)
    private function references(int $memberId): array
    {
        return collect(self::REFERENCES)->mapWithKeys(fn ($table) => [$table => DB::table($table)->where('member_id', $memberId)->count()])->all();
    }

    // the primary's empty fields filled from the others (the first one that has it), + the notes appended
    private function fill(Member $primary, Collection $others): array
    {
        $fill = [];
        foreach (self::FILL as $field) {
            $empty = in_array($field, ['company_id', 'job_id']) ? !(int) $primary->{$field} : blank($primary->{$field});
            if (!$empty) {
                continue;
            }
            $from = $others->first(fn ($member) => in_array($field, ['company_id', 'job_id']) ? (int) $member->{$field} : filled($member->{$field}));
            if ($from) {
                $fill[$field] = $from->{$field};
            }
        }
        $notes = $others->pluck('notes')->filter(fn ($note) => filled($note) && $note !== $primary->notes);
        if ($notes->isNotEmpty()) {
            $fill['notes'] = mb_substr(collect([$primary->notes])->filter()->concat($notes)->join(' | '), 0, 255);
        }

        return $fill;
    }

    private function merge(Member $primary, Collection $others, array $fill, PaymentService $paymentService): void
    {
        $ids = $others->pluck('id')->all();
        DB::transaction(function () use ($primary, $ids, $fill, $paymentService) {
            // the reservations / packages whose money could change: the moved ones + the packages of the moved reservations
            $reservationIds = DB::table('reservations')->whereIn('member_id', $ids)->pluck('id');
            $packageIds = DB::table('packages')->whereIn('member_id', $ids)->pluck('id')
                ->merge(DB::table('reservations')->whereIn('id', $reservationIds)->where('package_id', '>', 0)->pluck('package_id'))->unique();
            $totals = fn () => [
                DB::table('reservations')->whereIn('id', $reservationIds)->selectRaw('ROUND(COALESCE(SUM(paid_amount), 0), 2) p, ROUND(COALESCE(SUM(remaining), 0), 2) r')->first(),
                DB::table('packages')->whereIn('id', $packageIds)->selectRaw('ROUND(COALESCE(SUM(paid_amount), 0), 2) p, ROUND(COALESCE(SUM(remaining), 0), 2) r')->first(),
            ];
            $before = $totals();

            foreach (self::REFERENCES as $table) {
                DB::table($table)->whereIn('member_id', $ids)->update(['member_id' => $primary->id]);
            }
            if ($fill) {
                DB::table('members')->where('id', $primary->id)->update($fill + ['updated_at' => now()]);
            }
            // soft deleted, merged_into = the primary, phone_key NULL (out of the unique index); the query builder: no model events
            DB::table('members')->whereIn('id', $ids)->update(['merged_into' => $primary->id, 'phone_key' => null, 'deleted_at' => now(), 'updated_at' => now()]);

            Reservation::whereIn('id', $reservationIds)->get()->each(fn ($reservation) => $paymentService->refresh($reservation));
            Package::whereIn('id', $packageIds)->get()->each(fn ($package) => $package->recalculate());

            $after = $totals();
            if ($before != $after) {
                throw new \RuntimeException('Paid / remaining changed while merging into #' . $primary->id . ': ' . json_encode([$before, $after]) . ' — rolled back');
            }
        });
    }
}
