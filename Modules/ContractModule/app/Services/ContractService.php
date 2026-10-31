<?php

namespace Modules\ContractModule\app\Services;

use HTMLPurifier;
use HTMLPurifier_Config;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Modules\AccountModule\app\Models\Account;
use Modules\ContractModule\app\Models\ContractSetting;
use Modules\ContractModule\app\Models\ContractTemplate;
use Modules\ContractModule\app\Repositories\ContractTemplateRepository;
use Modules\MemberModule\app\Models\Member;
use Modules\ReservationModule\app\Models\Reservation;
use Modules\SpaceModule\app\Models\Space;
use Modules\SpaceModule\app\Models\SubscriptionType;
use Modules\UnitModule\app\Models\Unit;

/**
 * Contracts (ContractModule, 2026-10-31, docs/contracts-plan.md). Every method takes the owner account id.
 * Phase 1: Settings › Contracts (the lessor data + number_start), the templates (name, title, body + renewal annex in Arabic /
 * English as sanitized rich text, default term, on / off) and their units (one template per unit: chosen from the template form
 * or the unit form, as the WhatsApp messages), the placeholders and their values.
 * Every saved HTML goes through sanitize() (HTMLPurifier, an allowlist mpdf renders well); the editor (custom.js, TinyMCE) has the
 * same allowlist, so what the owner sees is what is saved.
 */
class ContractService
{
    // the tags / attributes a contract text may keep (HTMLPurifier HTML.Allowed); the editor's valid_elements = the same (custom.js)
    const ALLOWED_HTML = 'p[style|dir],br,strong,b,em,i,u,ol[style|dir],ul[style|dir],li[style|dir],h2[style|dir],h3[style|dir],'
        . 'h4[style|dir],table[style|dir],thead,tbody,tr,td[style|dir|colspan|rowspan],th[style|dir|colspan|rowspan],span[style|dir]';

    // the only CSS kept (on the tags above): alignment and direction
    const ALLOWED_CSS = ['text-align', 'direction'];

    // the template texts (sanitized on save; the placeholders checked)
    const BODIES = ['body_ar', 'body_en', 'renewal_body_ar', 'renewal_body_en'];

    const ID_TYPES_AR = ['national_id' => 'بطاقة رقم قومي', 'passport' => 'جواز سفر'];

    const LEASE_PERIODS_AR = ['hour' => 'ساعة', 'day' => 'يوم', 'week' => 'أسبوع', 'month' => 'شهر', 'year' => 'سنة'];

    // read once per request
    private static array $settings = [];
    private static array $templates = [];
    private static ?HTMLPurifier $purifier = null;

    private $templateRepository;

    public function __construct(ContractTemplateRepository $templateRepository)
    {
        $this->templateRepository = $templateRepository;
    }

    // ---- settings

    // the account's settings, the defaults until the first save (the lessor = the account's name / address)
    public function settings(int $accountId): ContractSetting
    {
        if (!isset(self::$settings[$accountId])) {
            $account = Account::settingsOf($accountId);
            self::$settings[$accountId] = ContractSetting::where('account_id', $accountId)->first()
                ?? new ContractSetting(['account_id' => $accountId, 'lessor_name_ar' => $account?->name_ar, 'lessor_name_en' => $account?->name_en,
                    'property_address_ar' => $account?->address, 'number_start' => 1]);
        }
        return self::$settings[$accountId];
    }

    public function saveSettings(int $accountId, array $data): ContractSetting
    {
        $settings = $this->settings($accountId);
        $settings->fill($data)->save();
        unset(self::$settings[$accountId]);
        return $settings;
    }

    // ---- templates, assigned to units

    // the account's templates (by name), each with unit_ids = its units (2 queries, once per request)
    public function templates(int $accountId): Collection
    {
        if (!isset(self::$templates[$accountId])) {
            $units = DB::table('contract_template_unit')->where('account_id', $accountId)->get(['contract_template_id', 'unit_id'])
                ->groupBy('contract_template_id');
            self::$templates[$accountId] = $this->templateRepository->forAccount($accountId)->get()
                ->each(fn ($template) => $template->unit_ids = $units->get($template->id, collect())->pluck('unit_id')->map(fn ($id) => (int) $id)->all())
                ->sortBy(fn ($template) => mb_strtolower($template->name))->values();
        }
        return self::$templates[$accountId];
    }

    // the unit's template (on or off), null = none
    public function unitTemplate(int $accountId, int $unitId): ?ContractTemplate
    {
        return $unitId ? $this->templates($accountId)->first(fn ($template) => in_array($unitId, $template->unit_ids, true)) : null;
    }

    // 404 when the template belongs to another account
    public function findTemplate(int $accountId, $id): ContractTemplate
    {
        return $this->templateRepository->forAccount($accountId)->with(['units' => fn ($query) => $query->with('space')])->findOrFail($id);
    }

    /**
     * Duplicate (the templates table): an UNSAVED template with the original's texts, titles and term, named "<name> (copy)", on
     * (the new-template default), no units (a unit has one template) — it only fills the New Template form; saving = createTemplate().
     * 404 when the original belongs to another account.
     */
    public function duplicateOf(int $accountId, $id): ContractTemplate
    {
        $original = $this->findTemplate($accountId, $id);
        return new ContractTemplate([
            'name' => mb_substr($original->name . ' (copy)', 0, 100),
            'title_ar' => $original->title_ar,
            'title_en' => $original->title_en,
            'body_ar' => $original->body_ar,
            'body_en' => $original->body_en,
            'renewal_body_ar' => $original->renewal_body_ar,
            'renewal_body_en' => $original->renewal_body_en,
            'default_term_months' => $original->default_term_months,
            'is_enabled' => true,
        ]);
    }

    public function createTemplate(int $accountId, array $data, Model $creator): ContractTemplate
    {
        return $this->saveTemplate($accountId, new ContractTemplate(['account_id' => $accountId]), $data, $creator);
    }

    public function updateTemplate(int $accountId, $id, array $data): ContractTemplate
    {
        return $this->saveTemplate($accountId, $this->findTemplate($accountId, $id), $data);
    }

    // soft delete + its units (they get no contract until another template is chosen)
    public function deleteTemplate(int $accountId, $id): void
    {
        $template = $this->findTemplate($accountId, $id);
        DB::transaction(function () use ($template) {
            DB::table('contract_template_unit')->where('contract_template_id', $template->id)->delete();
            $template->delete();
        });
        unset(self::$templates[$accountId]);
    }

    /**
     * The template form: name, title, texts (sanitized again here: the service may be called outside the form; an unknown
     * placeholder = 422 under its text), term, on / off, its units (the account's own, not deleted; a unit that already has another
     * template = 422 under units). The account's templates are locked while checking (two saves can't take the same unit).
     */
    private function saveTemplate(int $accountId, ContractTemplate $template, array $data, ?Model $creator = null): ContractTemplate
    {
        $bodies = [];
        foreach (self::BODIES as $field) {
            $bodies[$field] = self::sanitize($data[$field] ?? null) ?: null;
            if ($unknown = self::unknownPlaceholders($bodies[$field])) {
                throw ValidationException::withMessages([$field => self::unknownMessage($unknown)]);
            }
        }
        if (!$bodies['body_ar']) {
            throw ValidationException::withMessages(['body_ar' => 'The Arabic text is required.']);
        }

        DB::transaction(function () use ($accountId, $template, $data, $creator, $bodies) {
            ContractTemplate::where('account_id', $accountId)->lockForUpdate()->pluck('id');
            $unitIds = $this->accountUnitIds($accountId, $data['units'] ?? []);
            if ($error = $this->templateUnitsError($accountId, $unitIds, $template->id)) {
                throw ValidationException::withMessages(['units' => $error]);
            }
            $template->fill($bodies + [
                'name' => $data['name'],
                'title_ar' => $data['title_ar'],
                'title_en' => ($data['title_en'] ?? null) ?: null,
                'default_term_months' => ($data['default_term_months'] ?? null) ?: null,
                'is_enabled' => (bool) ($data['is_enabled'] ?? false),
            ]);
            if ($creator) {
                $template->creatable()->associate($creator);
            }
            $template->save();
            // the deleted units' rows stay (a restored unit keeps its template)
            DB::table('contract_template_unit')->where('contract_template_id', $template->id)
                ->whereIn('unit_id', Unit::where('account_id', $accountId)->select('id'))->delete();
            DB::table('contract_template_unit')->insert(array_map(fn ($unitId) => ['contract_template_id' => $template->id, 'unit_id' => $unitId, 'account_id' => $accountId], $unitIds));
        });
        unset(self::$templates[$accountId]);
        return $template;
    }

    // the given ids that are the account's units (not deleted)
    private function accountUnitIds(int $accountId, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        return $ids ? Unit::where('account_id', $accountId)->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all() : [];
    }

    /**
     * The template form's check: the units that already have another template (one per unit), null = none.
     * 'Office 3 (Private Office) already has "Office rental".'
     */
    public function templateUnitsError(int $accountId, array $unitIds, ?int $exceptId = null): ?string
    {
        if (!$unitIds) {
            return null;
        }
        $taken = DB::table('contract_template_unit')->join('contract_templates', 'contract_templates.id', '=', 'contract_template_unit.contract_template_id')
            ->where('contract_template_unit.account_id', $accountId)->whereNull('contract_templates.deleted_at')
            ->when($exceptId, fn ($query) => $query->where('contract_templates.id', '!=', $exceptId))
            ->whereIn('contract_template_unit.unit_id', $unitIds)->get(['contract_template_unit.unit_id', 'contract_templates.name'])->keyBy('unit_id');
        if ($taken->isEmpty()) {
            return null;
        }
        $units = Unit::whereIn('id', $taken->keys())->with('space')->get();
        return $units->map(fn ($unit) => $unit->name . ' (' . $unit->space?->name . ') already has "' . $taken[$unit->id]->name . '".')
            ->push('A unit has one contract template: untick these units, or choose another template for them from that template / the unit form first.')->implode(' ');
    }

    // the unit form's check: the chosen template is one of the account's, null = fine (none chosen = no contracts for the unit)
    public function unitTemplateError(int $accountId, $templateId): ?string
    {
        return $templateId && !$this->templateRepository->forAccount($accountId)->whereKey((int) $templateId)->exists()
            ? 'The chosen contract template was not found, reload the page.' : null;
    }

    /**
     * The unit form's save (UnitService create / update, inside its transaction): the unit's template = $templateId (null = none;
     * the account's templates locked while checking, 422 under contract_template_id).
     */
    public function syncUnitTemplate(int $accountId, int $unitId, $templateId): void
    {
        ContractTemplate::where('account_id', $accountId)->lockForUpdate()->pluck('id');
        if ($error = $this->unitTemplateError($accountId, $templateId)) {
            throw ValidationException::withMessages(['contract_template_id' => $error]);
        }
        DB::table('contract_template_unit')->where('unit_id', $unitId)->where('account_id', $accountId)->delete();
        if ($templateId) {
            DB::table('contract_template_unit')->insert(['contract_template_id' => (int) $templateId, 'unit_id' => $unitId, 'account_id' => $accountId]);
        }
        unset(self::$templates[$accountId]);
    }

    // the unit form's select: [id => name (Off)] + the chosen id
    public function unitFormData(int $accountId, ?int $unitId): array
    {
        $templates = $this->templates($accountId);
        return [
            'options' => $templates->mapWithKeys(fn ($template) => [$template->id => $template->name . ($template->is_enabled ? '' : ' (Off)')])->all(),
            'selected' => $unitId ? $this->unitTemplate($accountId, $unitId)?->id : null,
        ];
    }

    // the template form's units: the account's spaces (order) with their units (order), deleted ones left out
    public function spacesWithUnits(int $accountId): Collection
    {
        return Space::where('account_id', $accountId)->ordered()
            ->with(['units' => fn ($query) => $query->where('account_id', $accountId)->ordered()])->get()
            ->filter(fn ($space) => $space->units->isNotEmpty())->values();
    }

    // the template form's hints: {unit id: "other template's name"} (custom.js marks them; the save refuses them)
    public function unitsTaken(int $accountId, ?int $exceptId = null): array
    {
        $taken = [];
        foreach ($this->templates($accountId) as $template) {
            if ($template->id !== $exceptId) {
                foreach ($template->unit_ids as $unitId) {
                    $taken[$unitId] = $template->name;
                }
            }
        }
        return $taken;
    }

    // ---- texts

    /**
     * A contract text as stored: only the allowed tags (ALLOWED_HTML) and CSS (ALLOWED_CSS) — no scripts, event handlers, links,
     * images or other styles. A <div> (the old DMS texts, pasted texts) becomes a paragraph (a removed div would run its text into
     * the next one). Empty paragraphs left by that are removed. '' when nothing is left.
     */
    public static function sanitize(?string $html): string
    {
        $html = trim((string) $html);
        if ($html === '') {
            return '';
        }
        $html = preg_replace(['~<div\b[^>]*>~i', '~</div\s*>~i'], ['<p>', '</p>'], $html);
        $clean = trim(self::purifier()->purify($html));
        // nothing but empty paragraphs / spaces (also non-breaking ones) = empty
        $text = preg_replace('/[\s\x{00A0}]+/u', '', html_entity_decode(strip_tags($clean), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        return $text === '' && !str_contains($clean, '<table') ? '' : $clean;
    }

    private static function purifier(): HTMLPurifier
    {
        if (!self::$purifier) {
            $cache = storage_path('app/purifier');
            File::ensureDirectoryExists($cache);
            $config = HTMLPurifier_Config::createDefault();
            $config->set('Core.Encoding', 'UTF-8');
            $config->set('HTML.Doctype', 'XHTML 1.0 Transitional');
            $config->set('HTML.Allowed', self::ALLOWED_HTML);
            $config->set('CSS.AllowedProperties', self::ALLOWED_CSS);
            $config->set('AutoFormat.RemoveEmpty', true);
            $config->set('Cache.SerializerPath', $cache);
            self::$purifier = new HTMLPurifier($config);
        }
        return self::$purifier;
    }

    // every placeholder name (config contractmodule.placeholders, all groups)
    public static function placeholderNames(): array
    {
        return array_keys(array_merge(...array_values(config('contractmodule.placeholders'))));
    }

    // the {names} of a text that are not placeholders (the template form refuses them); tags removed first
    public static function unknownPlaceholders(?string $html): array
    {
        preg_match_all('/\{(\w+)\}/u', html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8'), $matches);
        return array_values(array_diff(array_unique($matches[1]), self::placeholderNames()));
    }

    public static function unknownMessage(array $unknown): string
    {
        return 'Unknown placeholder(s): {' . implode('}, {', $unknown) . '}. Use the ones listed under the texts.';
    }

    // {name} → its value, HTML-escaped (the text is HTML); an unknown {name} stays as typed
    public static function render(?string $html, array $variables): string
    {
        return preg_replace_callback('/\{(\w+)\}/u', fn ($match) => array_key_exists($match[1], $variables)
            ? e($variables[$match[1]]) : $match[0], (string) $html);
    }

    /**
     * The placeholder values for a reservation (config contractmodule.placeholders), in $lang (ar | en: names, labels).
     * $contract: the contract's own values (contract_no, contract_date, start_date, end_date, term_months, activity, deposit,
     * party_type, previous_contract_no, previous_contract_date); missing = taken from the reservation (the preview's sample).
     */
    public function variables(Reservation $reservation, string $lang = 'ar', array $contract = []): array
    {
        $accountId = (int) $reservation->account_id;
        $account = Account::settingsOf($accountId);
        $settings = $this->settings($accountId);
        $member = $reservation->member;
        $company = $member && $member->company_id ? $member->company : null;
        $ar = $lang === 'ar';
        $local = fn ($model, $field) => $model ? (string) (($ar ? $model->{$field . '_ar'} : $model->{$field . '_en'}) ?: ($model->{$field . '_ar'} ?: $model->{$field . '_en'})) : '';
        $format = Reservation::DATE_FORMAT;
        $price = $reservation->dueAmount();
        $leasePeriod = (string) $reservation->plan?->lease_period;
        $months = $contract['term_months'] ?? null;
        $start = $contract['start_date'] ?? $reservation->start_at;
        $end = $contract['end_date'] ?? ($months && $start ? $start->copy()->addMonthsNoOverflow($months)->subDay() : $reservation->realEnd());
        $termAmount = $months && $leasePeriod === 'month' ? $price * $months : ($months && $leasePeriod === 'year' ? $price * $months / 12 : $price);
        $taxed = $reservation->isTaxed();
        $money = fn ($amount) => number_format((float) $amount, 2);
        $idType = $member?->national_number ? ($member->id_type ?: 'national_id') : null;
        $isCompany = ($contract['party_type'] ?? 'individual') === 'company' && $company;

        return [
            'contract_no' => (string) ($contract['contract_no'] ?? ''),
            'contract_date' => ($contract['contract_date'] ?? now())->format($format),
            'start_date' => (string) $start?->format($format),
            'end_date' => (string) $end?->format($format),
            'term' => $months ? self::term($months, $lang) : (string) $reservation->period,
            'activity' => (string) ($contract['activity'] ?? ''),
            'deposit' => isset($contract['deposit']) && $contract['deposit'] !== null ? $money($contract['deposit']) : '',
            'previous_contract_no' => (string) ($contract['previous_contract_no'] ?? ''),
            'previous_contract_date' => (string) ($contract['previous_contract_date'] ?? ''),

            'lessor_name' => (string) (($ar ? $settings->lessor_name_ar : $settings->lessor_name_en) ?: ($settings->lessor_name_ar ?: $settings->lessor_name_en)),
            'lessor_phone' => (string) $account?->phone,
            'lessor_commercial_register' => (string) $settings->commercial_register,
            'lessor_tax_number' => (string) $settings->tax_number,
            'lessor_representative' => (string) $settings->representative_name,
            'lessor_representative_title' => (string) $settings->representative_title,
            'lessor_representative_national_id' => (string) $settings->representative_national_id,
            'property_address' => (string) (($ar ? $settings->property_address_ar : $settings->property_address_en) ?: ($settings->property_address_ar ?: $settings->property_address_en)),

            'tenant_name' => $isCompany ? $local($company, 'name') : (string) $member?->name,
            'member_name' => (string) $member?->name,
            'member_phone' => (string) $member?->phone,
            'member_email' => (string) $member?->email,
            'member_id_type' => $idType ? ($ar ? self::ID_TYPES_AR[$idType] : Member::ID_TYPES[$idType]) : '',
            'member_id_number' => (string) $member?->national_number,
            'member_nationality' => (string) $member?->nationality,
            'member_address' => (string) $member?->address,
            'member_job' => $member && $member->job_id ? $local($member->job, 'name') : '',
            'company_name' => $local($company, 'name'),
            'company_commercial_register' => (string) $company?->commercial_register,
            'company_tax_number' => (string) $company?->tax_number,
            'company_address' => (string) $company?->address,
            'company_representative' => (string) $company?->representative_name,
            'company_representative_title' => (string) $company?->representative_title,
            'company_representative_national_id' => (string) $company?->representative_national_id,

            'space' => $local($reservation->space, 'name'),
            'unit' => $local($reservation->unit, 'name'),
            'unit_description' => $local($reservation->unit, 'description'),
            'capacity' => (string) ($reservation->unit?->capacity ?: ''),
            'plan' => (string) $reservation->plan?->name,
            'lease_period' => $leasePeriod ? ($ar ? (self::LEASE_PERIODS_AR[$leasePeriod] ?? $leasePeriod) : (Unit::LEASE_PERIODS[$leasePeriod] ?? $leasePeriod)) : '',

            'price' => $money($price),
            'term_amount' => $money($termAmount),
            'billing_day' => (string) ($reservation->subscription_day ?: ''),
            'renewal_date' => $reservation->isPeriod() ? (string) $reservation->renewalDate()?->format($format) : '',

            'net_before_tax' => $taxed ? $money($reservation->tax_net) : '',
            'vat_rate' => $taxed ? rtrim(rtrim(number_format((float) $reservation->tax_vat_rate, 2), '0'), '.') . '%' : '',
            'vat' => $taxed ? $money($reservation->vat) : '',
            'table_tax_rate' => $taxed ? rtrim(rtrim(number_format((float) $reservation->tax_table_rate, 2), '0'), '.') . '%' : '',
            'table_tax' => $taxed ? $money($reservation->table_tax) : '',
            'total_with_tax' => $taxed ? $money($reservation->tax_total) : '',
        ];
    }

    // 1 → شهر واحد / 1 month, 2 → شهران, 3–10 → 3 أشهر, 11+ → 12 شهرًا
    public static function term(int $months, string $lang = 'ar'): string
    {
        if ($lang !== 'ar') {
            return $months . ' ' . ($months === 1 ? 'month' : 'months');
        }
        return match (true) {
            $months === 1 => 'شهر واحد',
            $months === 2 => 'شهران',
            $months <= 10 => $months . ' أشهر',
            default => $months . ' شهرًا',
        };
    }

    /**
     * The template form's live preview: {ar: {...}, en: {...}} from the account's newest subscription period with a member (else
     * its newest reservation), as a 12-month contract No. 1 issued today; [] when there is none (the preview shows the placeholders).
     */
    public function sampleVariables(int $accountId): array
    {
        $with = ['member.company', 'member.job', 'unit', 'space', 'plan', 'subscriptionType'];
        $reservation = Reservation::where('account_id', $accountId)->where('member_id', '>', 0)
            ->whereIn('subscription_type_id', SubscriptionType::idsOf('period') ?: [0])->with($with)->latest('id')->first()
            ?? Reservation::where('account_id', $accountId)->where('member_id', '>', 0)->with($with)->latest('id')->first();
        if (!$reservation) {
            return [];
        }
        $contract = ['contract_no' => $this->settings($accountId)->number_start, 'term_months' => 12, 'activity' => 'تجارة / Trading', 'deposit' => 0,
            'previous_contract_no' => max(1, $this->settings($accountId)->number_start - 1), 'previous_contract_date' => now()->subYear()->format(Reservation::DATE_FORMAT)];
        return ['ar' => $this->variables($reservation, 'ar', $contract), 'en' => $this->variables($reservation, 'en', $contract)];
    }
}
