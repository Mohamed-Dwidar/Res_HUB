<?php

namespace Modules\ContractModule\app\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\AccountModule\app\Models\Account;
use Modules\ContractModule\app\Models\ContractTemplate;
use Modules\ContractModule\app\Services\ContractService;
use Modules\SpaceModule\app\Models\Space;
use Modules\UnitModule\app\Models\Unit;
use Modules\UserModule\app\Models\User;

/**
 * The account's office rental templates (2026-11-25, user): the contract (the paper contract's clauses, spelling slips corrected;
 * database/templates/office-rental.ar.html) and its renewal annex as its own "Renewal annex" template (the paper annex;
 * office-rental-renewal.ar.html); the tables / logo / signatures = the PDF layout (Contract/pdf). Both assigned to the units of the
 * spaces named "Virtual Office" / "Private Office". Dry run by default; --run saves. Safe twice: an existing template of that name keeps
 * its text and settings (the owner's edits), a unit that already has a template of that type keeps it. The annex text this command
 * once put inside the contract template (renewal_body_ar) is cleared when it is still that same text.
 */
class SeedTemplateCommand extends Command
{
    const TEMPLATES = [
        'contract' => ['name' => 'Office rental', 'title' => 'عقد ايجار مساحة عمل مكتبية', 'file' => 'office-rental.ar.html'],
        'renewal' => ['name' => 'Office rental — Renewal annex', 'title' => 'ملحق تجديد عقد ايجار مساحة عمل مكتبية', 'file' => 'office-rental-renewal.ar.html'],
    ];

    const SPACES = ['Virtual Office', 'Private Office'];

    protected $signature = 'contracts:seed-template {--account=1 : the account id} {--run : apply (default = dry run, nothing saved)}';

    protected $description = 'Create the office rental contract + renewal annex templates and assign them to the Virtual / Private Office units (dry run unless --run)';

    public function handle(ContractService $contractService): int
    {
        $run = (bool) $this->option('run');
        $account = Account::find((int) $this->option('account'));
        if (!$account) {
            $this->error('No such account.');
            return self::FAILURE;
        }
        $this->info(($run ? 'RUN' : 'DRY RUN (add --run to apply)') . ': account #' . $account->id . ' ' . $account->name);
        $text = fn ($file) => ContractService::sanitize(file_get_contents(dirname(__DIR__, 2) . '/database/templates/' . $file));
        $by = User::where('userable_type', $account->getMorphClass())->where('userable_id', $account->id)->first();
        $spaces = Space::where('account_id', $account->id)->whereIn('name_en', self::SPACES)->pluck('id');
        $units = Unit::where('account_id', $account->id)->whereIn('space_id', $spaces)->with('space')->ordered()->get();

        DB::beginTransaction();
        foreach (self::TEMPLATES as $kind => $spec) {
            $body = $text($spec['file']);
            $template = ContractTemplate::where('account_id', $account->id)->where('name', $spec['name'])->first();
            if ($template) {
                $this->line(ContractTemplate::KINDS[$kind] . ' template "' . $spec['name'] . '" #' . $template->id . ' exists: its text and settings are kept.');
            } else {
                $template = new ContractTemplate(['account_id' => $account->id, 'name' => $spec['name'], 'kind' => $kind, 'title_ar' => $spec['title'],
                    'body_ar' => $body, 'default_term_months' => null, 'is_enabled' => true]);
                if ($by) {
                    $template->creatable()->associate($by);
                }
                $template->save();
                $this->line(ContractTemplate::KINDS[$kind] . ' template "' . $spec['name'] . '" created: "' . $spec['title'] . '", ' . mb_strlen(strip_tags($body)) . ' characters.');
            }
            if ($kind === 'contract' && $template->renewal_body_ar) {
                $annex = $text(self::TEMPLATES['renewal']['file']);
                $older = ContractService::sanitize(str_replace('جنيه {contract_value_period}', '{currency} {contract_value_period}', file_get_contents(dirname(__DIR__, 2) . '/database/templates/' . self::TEMPLATES['renewal']['file'])));
                if (in_array($template->renewal_body_ar, [$annex, $older], true)) {
                    $template->update(['renewal_body_ar' => null]);
                    $this->line('  its old annex text cleared (now the Renewal annex template).');
                }
            }

            $taken = DB::table('contract_template_unit')->where('kind', $kind)->whereIn('unit_id', $units->pluck('id'))->pluck('contract_template_id', 'unit_id');
            $rows = [];
            foreach ($units as $unit) {
                $current = $taken[$unit->id] ?? null;
                if ($current === null) {
                    DB::table('contract_template_unit')->insert(['contract_template_id' => $template->id, 'unit_id' => $unit->id, 'kind' => $kind, 'account_id' => $account->id]);
                }
                $rows[] = [$unit->id, $unit->space?->name_en, $unit->name, $current === null ? 'assigned' : ((int) $current === (int) $template->id ? 'already assigned' : 'kept: template #' . $current)];
            }
            $this->table(['Unit', 'Space', 'Name', ContractTemplate::KINDS[$kind]], $rows);
        }

        // the lessor data printed on the contracts (Settings › Contracts): only reported
        foreach ($contractService->lessorDefaults((int) $account->id) as $field => $value) {
            if ($value === '') {
                $this->warn('Settings › Contracts: ' . $field . ' is empty.');
            }
        }

        $run ? DB::commit() : DB::rollBack();
        $this->info($run ? 'Saved.' : 'Dry run: rolled back.');
        return self::SUCCESS;
    }
}
