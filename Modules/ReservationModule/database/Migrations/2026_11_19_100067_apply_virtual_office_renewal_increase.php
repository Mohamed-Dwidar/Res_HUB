<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

// DATA migration (2026-11-19, user): Virtual Office renewal increase = percent 10, found by rule (no ids), safe on any DB:
// (1) every plan of a space named "Virtual Office" (plan_space) = the default of new subscriptions;
// (2) its reservations not cancelled (status counted), not marked left, not deleted, current / future (end ≥ today);
// (3) its periods that ended without a counted renewal and are not marked left (the Schedule "Not renewed") — a Renew takes the
//     increase from the period it renews.
// Only rows still at 'none' change (rows already set are never touched); a second run changes 0. Nothing owed changes (only the
// NEXT Renew's price), no payments:refresh. Every changed row is recorded in `data_migration_rows` → down() reverts exactly those
// (and only while they still hold percent 10).
return new class extends Migration {
    const NAME = '2026_11_19_100067_apply_virtual_office_renewal_increase';

    public function up(): void {
        if (!Schema::hasTable('data_migration_rows')) {
            Schema::create('data_migration_rows', function (Blueprint $table) {
                $table->id();
                $table->string('migration', 150);
                $table->string('table_name', 64);
                $table->unsignedBigInteger('row_id');
                $table->timestamp('created_at')->nullable();
                $table->unique(['migration', 'table_name', 'row_id']);
            });
        }

        DB::transaction(function () {
            $spaces = DB::table('spaces')->where('name_en', 'Virtual Office')->whereNull('deleted_at')->pluck('id');
            if ($spaces->isEmpty()) {
                $this->log(0, 0, 0);
                return;
            }

            $plans = DB::table('plans')
                ->whereIn('id', DB::table('plan_space')->whereIn('space_id', $spaces)->select('plan_id'))
                ->whereNull('deleted_at')->where('renewal_increase_type', 'none')
                ->lockForUpdate()->pluck('id')->all();

            $base = fn () => DB::table('reservations as r')
                ->join('reservation_statuses as s', 's.id', '=', 'r.reservation_status_id')
                ->whereIn('r.space_id', $spaces)->whereNull('r.deleted_at')->whereNull('r.left_at')
                ->where('s.is_counted', 1)->where('r.renewal_increase_type', 'none');

            $current = $base()->whereRaw('r.end_at >= CURDATE()')->lockForUpdate()->pluck('r.id')->all();
            $notRenewed = $base()->whereRaw('r.end_at < CURDATE()')
                ->whereNotExists(fn ($q) => $q->from('reservations as n')
                    ->join('reservation_statuses as ns', 'ns.id', '=', 'n.reservation_status_id')
                    ->whereColumn('n.renewal_of', 'r.id')->whereNull('n.deleted_at')->where('ns.is_counted', 1))
                ->lockForUpdate()->pluck('r.id')->all();

            $this->apply('plans', $plans);
            $this->apply('reservations', array_merge($current, $notRenewed));
            $this->log(count($plans), count($current), count($notRenewed));
        });
    }

    public function down(): void {
        if (!Schema::hasTable('data_migration_rows')) {
            return;
        }
        DB::transaction(function () {
            $reverted = [];
            foreach (['plans', 'reservations'] as $table) {
                $ids = DB::table('data_migration_rows')->where('migration', self::NAME)->where('table_name', $table)->pluck('row_id');
                $reverted[$table] = 0;
                foreach ($ids->chunk(500) as $chunk) {
                    $reverted[$table] += DB::table($table)->whereIn('id', $chunk)
                        ->where('renewal_increase_type', 'percent')->where('renewal_increase_value', 10)
                        ->update(['renewal_increase_type' => 'none', 'renewal_increase_value' => 0]);
                }
            }
            DB::table('data_migration_rows')->where('migration', self::NAME)->delete();
            $this->say('reverted: plans ' . $reverted['plans'] . ', reservations ' . $reverted['reservations']);
        });
        if (DB::table('data_migration_rows')->doesntExist()) {
            Schema::drop('data_migration_rows');
        }
    }

    // set percent 10 on the ids (no updated_at: a data fix, not an edit) + remember them for down()
    private function apply(string $table, array $ids): void {
        $now = now();
        foreach (array_chunk($ids, 500) as $chunk) {
            DB::table($table)->whereIn('id', $chunk)->where('renewal_increase_type', 'none')
                ->update(['renewal_increase_type' => 'percent', 'renewal_increase_value' => 10]);
            DB::table('data_migration_rows')->insertOrIgnore(array_map(fn ($id) => [
                'migration' => self::NAME, 'table_name' => $table, 'row_id' => $id, 'created_at' => $now,
            ], $chunk));
        }
    }

    private function log(int $plans, int $current, int $notRenewed): void {
        $this->say("plans {$plans}, reservations current / future {$current}, ended not renewed {$notRenewed}");
    }

    private function say(string $text): void {
        $text = 'Virtual Office renewal increase: ' . $text;
        Log::info($text);
        if (app()->runningInConsole()) {
            fwrite(STDOUT, '  ' . $text . PHP_EOL);
        }
    }
};
