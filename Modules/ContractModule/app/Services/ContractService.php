<?php

namespace Modules\ContractModule\app\Services;

use HTMLPurifier;
use HTMLPurifier_Config;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\AccountModule\app\Models\Account;
use Modules\ContractModule\app\Models\Contract;
use Modules\ContractModule\app\Models\ContractSetting;
use Modules\ContractModule\app\Models\ContractTemplate;
use Modules\ContractModule\app\Repositories\ContractRepository;
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
    private $contractRepository;

    public function __construct(ContractTemplateRepository $templateRepository, ContractRepository $contractRepository)
    {
        $this->templateRepository = $templateRepository;
        $this->contractRepository = $contractRepository;
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
     * $contract: contract_no, start_date, end_date, term_months, party_type, previous_contract_no, previous_contract_date + fields =
     * the contract's OWN data (Contract::OWN_FIELDS; 2026-11-01: what is printed — tenant, unit number / address, contract date,
     * duration, value + currency, activity, deposit); no fields = fieldDefaults() (the template page's preview).
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
        $own = $contract['fields'] ?? $this->fieldDefaults($reservation, $months);
        $text = fn ($field) => trim((string) ($own[$field] ?? ''));
        $date = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->format($format) : '';

        $variables = [
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

            'tenant_name' => (string) $member?->name,
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

        // the contract's own data wins: what the contract says is what is printed
        return array_merge($variables, [
            'contract_date' => $date($own['contract_date'] ?? null) ?: $variables['contract_date'],
            'term' => $text('duration_text') ?: $variables['term'],
            'unit_number' => $text('unit_number'),
            'contract_value' => isset($own['term_amount']) && $own['term_amount'] !== '' && $own['term_amount'] !== null ? $money($own['term_amount']) : $variables['term_amount'],
            'term_amount' => isset($own['term_amount']) && $own['term_amount'] !== '' && $own['term_amount'] !== null ? $money($own['term_amount']) : $variables['term_amount'],
            'currency' => $text('currency'),
            'tenant_name' => $text('tenant_name'),
            'tenant_representative' => $text('tenant_representative'),
            'tenant_company' => $text('tenant_company'),
            'tenant_title' => $text('tenant_title'),
            'tenant_national_id' => $text('tenant_national_id'),
            'tenant_mobile' => $text('tenant_mobile'),
            'tenant_email' => $text('tenant_email'),
            'unit_address' => $text('unit_address'),
            'property_address' => $text('unit_address'),
            'activity' => $text('activity'),
            'deposit' => isset($own['deposit_amount']) && $own['deposit_amount'] !== '' && $own['deposit_amount'] !== null ? $money($own['deposit_amount']) : '',
            'notes' => $text('notes'),
            // the old member / company names read the contract's fields too
            'member_name' => $text('tenant_name'),
            'member_phone' => $text('tenant_mobile'),
            'member_email' => $text('tenant_email'),
            'member_id_number' => $text('tenant_national_id'),
            'company_name' => $text('tenant_company'),
            'company_representative' => $text('tenant_representative'),
            'company_representative_title' => $text('tenant_title'),
        ]);
    }

    /**
     * The contract's own data pre-filled (a new draft; the template page's preview): unit number = the unit's name, contract date =
     * the business date, duration = the term in words, value = the price for the term + the default currency, the tenant = the
     * member (+ the member's company and its representative), unit address = Settings › Contracts' property address.
     */
    public function fieldDefaults(Reservation $reservation, ?int $months): array
    {
        $member = $reservation->member;
        $company = $member && $member->company_id ? $member->company : null;
        $settings = $this->settings((int) $reservation->account_id);
        $unit = $reservation->unit;
        return [
            'unit_number' => (string) ($unit?->name_ar ?: $unit?->name_en),
            'contract_date' => (Account::settingsOf((int) $reservation->account_id)?->businessDate() ?? now())->format('Y-m-d'),
            'duration_text' => $months ? self::term($months) : '',
            'term_amount' => self::termAmount($reservation, $months),
            'currency' => (string) config('contractmodule.currency'),
            'tenant_name' => (string) $member?->name,
            'tenant_representative' => (string) ($company?->representative_name ?: $member?->name),
            'tenant_company' => (string) ($company ? ($company->name_ar ?: $company->name_en) : ''),
            'tenant_title' => (string) $company?->representative_title,
            'tenant_national_id' => (string) $member?->national_number,
            'tenant_mobile' => (string) $member?->phone,
            // a stored email that is not one ("--" in old data) is not offered: the page would refuse it
            'tenant_email' => filter_var((string) $member?->email, FILTER_VALIDATE_EMAIL) ? (string) $member->email : '',
            'unit_address' => (string) ($settings->property_address_ar ?: $settings->property_address_en),
            'activity' => '',
            'deposit_amount' => null,
            'notes' => '',
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
        $contract = ['contract_no' => $this->settings($accountId)->number_start, 'term_months' => 12,
            'previous_contract_no' => max(1, $this->settings($accountId)->number_start - 1), 'previous_contract_date' => now()->subYear()->format(Reservation::DATE_FORMAT)];
        return ['ar' => $this->variables($reservation, 'ar', $contract), 'en' => $this->variables($reservation, 'en', $contract)];
    }

    // ---- contracts (Phase 2): made by hand from a reservation, one per term; draft → issued (number + snapshot) → signed

    // the account's not cancelled contracts by "member-unit", newest term first (the reservation states: one query per request)
    private static array $contracts = [];

    // the owner (the account login) vs an employee
    public static function isOwner(?Model $user): bool
    {
        return $user?->userable instanceof Account;
    }

    // 404 when the contract belongs to another account
    public function findContract(int $accountId, $id): Contract
    {
        return $this->contractRepository->forAccount($accountId)->with(['member.company', 'member.job', 'company', 'unit', 'space', 'template', 'previous',
            'renewals', 'creatable.userable', 'reservation' => fn ($query) => $query->with(['member.company', 'member.job', 'unit', 'space', 'plan', 'subscriptionType'])])->findOrFail($id);
    }

    // the list query (paged / ordered by DataTables)
    public function listQuery(int $accountId)
    {
        return $this->contractRepository->forAccount($accountId)->with(['member', 'company', 'unit', 'space', 'creatable.userable', 'renewals']);
    }

    private function accountContracts(int $accountId): Collection
    {
        return self::$contracts[$accountId] ??= Contract::where('account_id', $accountId)->where('status', '!=', 'cancelled')
            ->where('member_id', '>', 0)->where('unit_id', '>', 0)->orderByDesc('starts_on')->orderByDesc('id')
            ->get(['id', 'account_id', 'contract_no', 'kind', 'renewal_of', 'status', 'member_id', 'unit_id', 'starts_on', 'ends_on', 'creatable_type', 'creatable_id'])
            ->groupBy(fn ($contract) => $contract->member_id . '-' . $contract->unit_id);
    }

    /**
     * The contract state of a reservation (its view's Contract block, the reservations list row menu), by its start day:
     * ['state' => 'covered', 'contract'] = a not cancelled contract of the member + unit whose term holds it (any template state);
     * else only when its unit has a switched on template: ['state' => 'ended', 'contract' => the last issued one before it,
     * 'template'] (→ Renew contract) | ['state' => 'none', 'template'] (→ Create contract); null = no contract button.
     */
    public function reservationState(Reservation $reservation): ?array
    {
        $accountId = (int) $reservation->account_id;
        if (!$reservation->member_id || !$reservation->unit_id || !$reservation->start_at) {
            return null;
        }
        $date = $reservation->start_at->copy()->startOfDay();
        $contracts = $this->accountContracts($accountId)->get($reservation->member_id . '-' . $reservation->unit_id, collect());
        if ($covering = $contracts->first(fn ($contract) => $contract->starts_on->lte($date) && $contract->ends_on->gte($date))) {
            return ['state' => 'covered', 'contract' => $covering];
        }
        $template = $this->unitTemplate($accountId, (int) $reservation->unit_id);
        if (!$template || !$template->is_enabled) {
            return null;
        }
        $previous = $contracts->first(fn ($contract) => $contract->ends_on->lt($date) && $contract->status !== 'draft');
        return $previous ? ['state' => 'ended', 'contract' => $previous, 'template' => $template] : ['state' => 'none', 'template' => $template];
    }

    // the reservations list row menu: Create / Renew contract, or the covering contract (view) — [] = nothing
    public function menuLinks(Reservation $reservation, string $area): array
    {
        $state = $this->reservationState($reservation);
        return match ($state['state'] ?? null) {
            // the draft page = a full page (its editors need room)
            'none' => [['label' => 'Create Contract', 'icon' => 'file-plus', 'url' => route($area . '.contracts.create', ['reservation_id' => $reservation->id])]],
            'ended' => [['label' => 'Renew Contract', 'icon' => 'file-plus', 'url' => route($area . '.contracts.create', ['reservation_id' => $reservation->id])]],
            'covered' => [['label' => 'Contract ' . $state['contract']->number_label, 'icon' => 'file-text', 'url' => route($area . '.contracts.show', $state['contract']->id), 'modal' => true],
                ['label' => 'Contract PDF (AR)', 'icon' => 'file-down', 'url' => route($area . '.contracts.pdf', ['id' => $state['contract']->id, 'lang' => 'ar']), 'blank' => true]],
            default => [],
        };
    }

    // a reservation of the account with what a contract needs, 404 otherwise
    public function findReservation(int $accountId, $id): Reservation
    {
        return Reservation::where('account_id', $accountId)->with(['member.company', 'member.job', 'unit', 'space', 'plan', 'subscriptionType'])->findOrFail($id);
    }

    /**
     * The draft form of a reservation: what it will be (kind, the previous contract of a renewal, the template) + the defaults
     * (term from the template's default_term_months, else the reservation's own period; a renewal starts the day after the previous
     * contract and keeps its party, deposit, activity). 422 when the reservation can't get a contract (no template / covered).
     */
    public function draftFor(Reservation $reservation): array
    {
        $state = $this->reservationState($reservation);
        if (($state['state'] ?? null) === 'covered') {
            throw ValidationException::withMessages(['reservation_id' => 'This reservation is already covered by contract ' . $state['contract']->number_label . '.']);
        }
        if (!$state) {
            throw ValidationException::withMessages(['reservation_id' => 'This reservation\'s unit has no contract template switched on (Settings › Contracts).']);
        }
        $previous = $state['state'] === 'ended' ? Contract::find($state['contract']->id) : null;
        $template = $state['template'];
        $startsOn = $previous ? $previous->ends_on->copy()->addDay() : $reservation->start_at->copy()->startOfDay();
        $months = $template->default_term_months;

        // the contract's own data: pre-filled from the member / company / settings; a renewal from the previous contract's (its
        // dates, duration and value follow the new term, the contract date = today)
        $fields = $this->fieldDefaults($reservation, $months);
        if ($previous) {
            foreach (['unit_number', 'currency', 'tenant_name', 'tenant_representative', 'tenant_company', 'tenant_title', 'tenant_national_id',
                    'tenant_mobile', 'tenant_email', 'unit_address', 'activity', 'deposit_amount'] as $field) {
                $fields[$field] = $previous->{$field};
            }
        }

        return [
            'kind' => $previous ? 'renewal' : 'contract',
            'previous' => $previous,
            'template' => $template,
            'starts_on' => $startsOn,
            'term_months' => $months,
            'ends_on' => $this->termEnd($startsOn, $months, $reservation),
            'party_type' => $previous?->party_type ?? (($fields['tenant_company'] ?? '') !== '' && $reservation->member?->company?->representative_name ? 'company' : 'individual'),
        ] + $fields;
    }

    // the last day of a term: start + months − 1 day; no term = the reservation's last day
    public function termEnd($startsOn, ?int $months, Reservation $reservation)
    {
        return $months ? $startsOn->copy()->addMonthsNoOverflow($months)->subDay() : ($reservation->realEnd() ?? $startsOn)->copy()->startOfDay();
    }

    // the price for the whole term: a month plan × months, a year plan × months / 12, else the reservation's price
    public static function termAmount(Reservation $reservation, ?int $months): float
    {
        $price = $reservation->dueAmount();
        return round(match (true) {
            $months && $reservation->plan?->lease_period === 'month' => $price * $months,
            $months && $reservation->plan?->lease_period === 'year' => $price * $months / 12,
            default => $price,
        }, 2);
    }

    /**
     * Create contract / Renew contract (the draft page): a draft for the reservation (kind and template by draftFor(); a company
     * contract needs the member's company); the party fields typed in the page are saved back to the member / company; its wording =
     * the editors' text (NULL while it is the template's own text).
     */
    public function createDraft(int $accountId, $reservationId, array $data, Model $creator): Contract
    {
        $reservation = $this->findReservation($accountId, $reservationId);
        return DB::transaction(function () use ($accountId, $reservation, $data, $creator) {
            Contract::where('account_id', $accountId)->where('member_id', $reservation->member_id)->lockForUpdate()->pluck('id');
            unset(self::$contracts[$accountId]);
            $draft = $this->draftFor($reservation);
            $contract = new Contract([
                'account_id' => $accountId,
                'kind' => $draft['kind'],
                'renewal_of' => $draft['previous']?->id ?? 0,
                'contract_template_id' => $draft['template']->id,
                'template_name' => $draft['template']->name,
                'reservation_id' => $reservation->id,
                'member_id' => $reservation->member_id,
                'space_id' => $reservation->space_id,
                'unit_id' => $reservation->unit_id,
                'title_ar' => $draft['template']->title_ar,
                'title_en' => $draft['template']->title_en,
            ]);
            $contract->status = 'draft';
            $contract->source = 'app';
            $contract->creatable()->associate($creator);
            $contract->setRelation('template', $draft['template']);
            $contract->setRelation('previous', $draft['previous']);
            $this->fillDraft($contract, $reservation, $data);
            $this->setText($contract, $data);
            $contract->save();
            unset(self::$contracts[$accountId]);
            return $contract;
        });
    }

    // the draft page again (account + employees): term, party, deposit, activity, notes, its wording; never after issue
    public function updateDraft(int $accountId, $id, array $data): Contract
    {
        return DB::transaction(function () use ($accountId, $id, $data) {
            $contract = $this->contractRepository->forAccount($accountId)->lockForUpdate()->findOrFail($id);
            if (!$contract->isDraft()) {
                throw ValidationException::withMessages(['status' => 'Only a draft can be edited: this contract is ' . strtolower($contract->status_label) . '.']);
            }
            $this->fillDraft($contract, $this->findReservation($accountId, $contract->reservation_id), $data);
            $this->setText($contract, $data);
            $contract->save();
            unset(self::$contracts[$accountId]);
            return $contract;
        });
    }

    // the draft page's fields (the contract's own data; never the member / company unless "Also save to member / company"); the
    // term must not overlap another contract of the member's unit
    private function fillDraft(Contract $contract, Reservation $reservation, array $data): void
    {
        $partyType = ($data['party_type'] ?? 'individual') === 'company' ? 'company' : 'individual';
        $months = ($data['term_months'] ?? null) ? (int) $data['term_months'] : null;
        $startsOn = \Illuminate\Support\Carbon::parse($data['starts_on'])->startOfDay();
        $endsOn = \Illuminate\Support\Carbon::parse($data['ends_on'])->startOfDay();
        if ($endsOn->lt($startsOn)) {
            throw ValidationException::withMessages(['ends_on' => 'The end must be on or after the start.']);
        }
        $overlap = Contract::where('account_id', $contract->account_id)->where('member_id', $contract->member_id)->where('unit_id', $contract->unit_id)
            ->where('status', '!=', 'cancelled')->when($contract->exists, fn ($query) => $query->whereKeyNot($contract->id))
            ->whereDate('starts_on', '<=', $endsOn)->whereDate('ends_on', '>=', $startsOn)->first();
        if ($overlap) {
            throw ValidationException::withMessages(['starts_on' => 'Contract ' . $overlap->number_label . ' (' . $overlap->starts_on->format(Reservation::DATE_FORMAT) . ' → '
                . $overlap->ends_on->format(Reservation::DATE_FORMAT) . ') already covers part of these dates for this member and unit.']);
        }
        $member = $reservation->member;
        $clean = fn ($field) => ($value = trim((string) ($data[$field] ?? ''))) === '' ? null : $value;

        $contract->fill([
            'party_type' => $partyType,
            'company_id' => $partyType === 'company' ? (int) ($member?->company_id ?? 0) : 0,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'term_months' => $months,
            'amount' => $reservation->dueAmount(),
            'term_amount' => isset($data['contract_value']) && $data['contract_value'] !== '' ? round((float) $data['contract_value'], 2) : self::termAmount($reservation, $months),
            'deposit_amount' => isset($data['deposit_amount']) && $data['deposit_amount'] !== '' ? round((float) $data['deposit_amount'], 2) : null,
            'contract_date' => $clean('contract_date'),
        ] + collect(['unit_number', 'duration_text', 'currency', 'tenant_name', 'tenant_representative', 'tenant_company', 'tenant_title',
            'tenant_national_id', 'tenant_mobile', 'tenant_email', 'unit_address', 'activity', 'notes'])->mapWithKeys(fn ($field) => [$field => $clean($field)])->all());
        if (!empty($data['save_to_member'])) {
            $this->saveBack($member, $contract);
        }
    }

    /**
     * "Also save to member / company" (unticked by default): the member's / company's EMPTY fields get what was typed on the contract
     * (member: ID number when it is 5–20 letters / digits, email; the member's company: representative name + title). Nothing that
     * is already filled is changed; the phone never (it is the member's key).
     */
    private function saveBack(?Member $member, Contract $contract): void
    {
        if (!$member) {
            return;
        }
        $id = (string) $contract->tenant_national_id;
        if (!$member->national_number && preg_match('/^[A-Za-z0-9]{5,20}$/', $id)) {
            $member->national_number = $id;
            $member->id_type = ctype_digit($id) ? 'national_id' : 'passport';
        }
        if (!$member->email && filter_var($contract->tenant_email, FILTER_VALIDATE_EMAIL)) {
            $member->email = $contract->tenant_email;
        }
        if ($member->isDirty()) {
            $member->save();
        }
        $company = $member->company_id ? $member->company : null;
        if ($company) {
            if (!$company->representative_name && $contract->tenant_representative) {
                $company->representative_name = $contract->tenant_representative;
            }
            if (!$company->representative_title && $contract->tenant_title) {
                $company->representative_title = $contract->tenant_title;
            }
            if ($company->isDirty()) {
                $company->save();
            }
        }
    }

    // what is still missing to issue (the contract's own fields, 2026-11-01): an individual = tenant name + national ID; a company =
    // company name + legal representative
    public function missingToIssue(Contract $contract): array
    {
        $required = $contract->party_type === 'company'
            ? ['tenant_company' => 'the company name', 'tenant_representative' => 'the legal representative']
            : ['tenant_name' => 'the tenant name', 'tenant_national_id' => 'the national ID'];
        return array_values(array_filter($required, fn ($label, $field) => trim((string) $contract->{$field}) === '', ARRAY_FILTER_USE_BOTH));
    }

    /**
     * The draft's wording (the draft page's editors, owner + employees; user 2026-10-31: edited while creating the contract):
     * sanitized, unknown placeholders refused (422 under the text); the template's own text = NULL (stays linked to the template);
     * an empty Arabic text = 422. Only when the form sent the texts. Never changes the template.
     */
    private function setText(Contract $contract, array $data): void
    {
        if (!array_key_exists('body_ar', $data) && !array_key_exists('body_en', $data)) {
            return;
        }
        foreach (['ar', 'en'] as $lang) {
            $body = self::sanitize($data['body_' . $lang] ?? null) ?: null;
            if ($unknown = self::unknownPlaceholders($body)) {
                throw ValidationException::withMessages(['body_' . $lang => self::unknownMessage($unknown)]);
            }
            $contract->{'body_' . $lang} = $body === self::sanitize($this->templateText($contract, $contract->template, $lang)) ? null : $body;
        }
        if (!$this->sourceText($contract, 'ar')) {
            throw ValidationException::withMessages(['body_ar' => 'The Arabic contract text is required.']);
        }
    }

    // the template's text for this contract: a renewal = its renewal annex (empty = the full contract text), else the contract text
    public function templateText(Contract $contract, ?ContractTemplate $template, string $lang): ?string
    {
        if (!$template) {
            return null;
        }
        return ($contract->isRenewal() ? ($template->{'renewal_body_' . $lang} ?: $template->{'body_' . $lang}) : $template->{'body_' . $lang}) ?: null;
    }

    // the text a draft prints (with {placeholders}): the owner's edit, else the template's
    public function sourceText(Contract $contract, string $lang): ?string
    {
        return $contract->{'body_' . $lang} ?: $this->templateText($contract, $contract->template, $lang);
    }

    /**
     * The draft page's editors: the text to edit {ar, en} (the owner's / employee's edit, else the template's — a renewal = its annex;
     * with its {placeholders}) + the preview values {ar: {...}, en: {...}} of this reservation. $draft (Create / Renew): an unsaved
     * contract from draftFor()'s defaults; Edit Draft: the contract.
     */
    public function editorData(Reservation $reservation, ?Contract $contract, ?array $draft = null): array
    {
        $contract ??= $this->draftContract($draft);
        $input = $this->contractVariables($contract);
        return [
            'texts' => ['ar' => $this->sourceText($contract, 'ar'), 'en' => $this->sourceText($contract, 'en')],
            'sample' => ['ar' => $this->variables($reservation, 'ar', $input), 'en' => $this->variables($reservation, 'en', $input)],
        ];
    }

    // an unsaved contract from draftFor()'s defaults (its template + previous contract set)
    private function draftContract(array $draft): Contract
    {
        $contract = new Contract(['kind' => $draft['kind'], 'renewal_of' => $draft['previous']?->id ?? 0, 'starts_on' => $draft['starts_on'],
            'ends_on' => $draft['ends_on'], 'term_months' => $draft['term_months'], 'party_type' => $draft['party_type'],
            'term_amount' => $draft['term_amount'], 'deposit_amount' => $draft['deposit_amount']]
            + array_intersect_key($draft, array_flip(Contract::OWN_FIELDS)));
        $contract->setRelation('template', $draft['template']);
        $contract->setRelation('previous', $draft['previous']);
        return $contract;
    }

    // the placeholder input of a contract (ContractService::variables())
    private function contractVariables(Contract $contract): array
    {
        $previous = $contract->previous;
        return [
            'contract_no' => $contract->contract_no,
            'start_date' => $contract->starts_on,
            'end_date' => $contract->ends_on,
            'term_months' => $contract->term_months,
            'party_type' => $contract->party_type,
            'fields' => collect(Contract::OWN_FIELDS)->mapWithKeys(fn ($field) => [$field => $contract->{$field}])->all(),
            'previous_contract_no' => $previous?->contract_no,
            'previous_contract_date' => ($previous?->contract_date ?? $previous?->issued_at)?->format(Reservation::DATE_FORMAT),
        ];
    }

    /**
     * Issue (account + employees): a draft gets its number — MAX + 1 of the account, never below Settings' First Contract Number
     * (the settings row locked: two issues never get the same number) — and its snapshot: title, the texts with every placeholder
     * replaced (ar + en when there is an English text), values {party_type, ar: {...}, en: {...}}. Never edited after this.
     * 422 when the party data is missing (missingToIssue()) or the term now overlaps another contract.
     */
    public function issue(int $accountId, $id, Model $user): Contract
    {
        return DB::transaction(function () use ($accountId, $id, $user) {
            // the settings row = the lock of the account's numbers (read fresh under the lock: number_start may have just changed)
            if (!ContractSetting::where('account_id', $accountId)->exists()) {
                $this->settings($accountId)->save();
            }
            $settings = ContractSetting::where('account_id', $accountId)->lockForUpdate()->first();
            unset(self::$settings[$accountId]);
            $contract = $this->contractRepository->forAccount($accountId)->lockForUpdate()->findOrFail($id);
            if (!$contract->isDraft()) {
                throw ValidationException::withMessages(['status' => 'This contract is already ' . strtolower($contract->status_label) . '.']);
            }
            $contract->load(['member.company', 'member.job', 'company', 'template', 'previous']);
            if ($missing = $this->missingToIssue($contract)) {
                throw ValidationException::withMessages(['status' => 'To issue it, fill ' . implode(' and ', $missing) . ' on the contract (Edit Draft).']);
            }
            if (!$this->sourceText($contract, 'ar')) {
                throw ValidationException::withMessages(['status' => 'The contract has no Arabic text (its template\'s text is empty).']);
            }
            $reservation = $this->findReservation($accountId, $contract->reservation_id);
            $number = max((int) $settings->number_start, (int) Contract::where('account_id', $accountId)->max('contract_no') + 1);

            $contract->contract_no = $number;
            $contract->issued_at = now();
            $contract->amount = $reservation->dueAmount();
            $contract->contract_date ??= now()->startOfDay();
            $input = $this->contractVariables($contract);
            $values = ['party_type' => $contract->party_type];
            foreach (['ar', 'en'] as $lang) {
                $values[$lang] = $this->variables($reservation, $lang, $input);
                $source = $this->sourceText($contract, $lang);
                $contract->{'body_' . $lang} = $source ? self::render($source, $values[$lang]) : null;
            }
            $contract->title_ar = $contract->template?->title_ar ?? $contract->title_ar;
            $contract->title_en = $contract->template?->title_en ?? $contract->title_en;
            $contract->values = $values;
            $contract->status = 'issued';
            $contract->issued_by = $user->getKey();
            $contract->save();
            unset(self::$contracts[$accountId]);
            return $contract;
        });
    }

    /**
     * Upload signed (account + employees): an issued contract becomes signed on that date, with the scan (optional); a signed one's
     * scan / date replaced = the owner only. The scan = a private file (contracts/{account_id}/).
     */
    public function markSigned(int $accountId, $id, array $data, Model $user): Contract
    {
        $contract = $this->contractRepository->forAccount($accountId)->findOrFail($id);
        if (!in_array($contract->status, ['issued', 'signed'], true)) {
            throw ValidationException::withMessages(['signed_on' => 'Only an issued contract can be marked signed.']);
        }
        if ($contract->status === 'signed' && !self::isOwner($user)) {
            throw ValidationException::withMessages(['signed_file' => 'This contract is already signed: only the owner can replace its scan.']);
        }
        if (($file = $data['signed_file'] ?? null) instanceof \Illuminate\Http\UploadedFile) {
            $old = $this->signedPath($contract);
            $name = 'contract-' . $contract->id . '-' . now()->format('YmdHis') . '.' . strtolower($file->getClientOriginalExtension() ?: $file->extension());
            Storage::disk('local')->putFileAs('contracts/' . $accountId, $file, $name);
            $contract->signed_file = $name;
            if ($old) {
                Storage::disk('local')->delete($old);
            }
        }
        $contract->signed_on = $data['signed_on'];
        $contract->signed_by = $user->getKey();
        $contract->status = 'signed';
        $contract->save();
        unset(self::$contracts[$accountId]);
        return $contract;
    }

    // the private scan path (download), null when there is none
    public function signedPath(Contract $contract): ?string
    {
        $path = $contract->signed_file ? 'contracts/' . $contract->account_id . '/' . $contract->signed_file : null;
        return $path && Storage::disk('local')->exists($path) ? $path : null;
    }

    // what the logged in user may do with the contract (the buttons + the controller checks)
    public function can(Contract $contract, string $action, Model $user): bool
    {
        $owner = self::isOwner($user);
        $own = $contract->creatable_type === $user->getMorphClass() && (int) $contract->creatable_id === (int) $user->getKey();
        return match ($action) {
            'edit', 'issue' => $contract->isDraft(),
            'delete' => $contract->isDraft() && ($owner || $own),
            'sign' => $contract->status === 'issued',
            'replace_scan' => $contract->status === 'signed' && $owner,
            default => false,
        };
    }

    // a draft only: the owner any draft, an employee their own (soft delete)
    public function deleteDraft(int $accountId, $id, Model $user): void
    {
        $contract = $this->contractRepository->forAccount($accountId)->findOrFail($id);
        if (!$this->can($contract, 'delete', $user)) {
            throw ValidationException::withMessages(['status' => $contract->isDraft() ? 'Only the owner or the employee who made this draft can delete it.'
                : 'An issued contract can\'t be deleted.']);
        }
        $contract->delete();
        unset(self::$contracts[$accountId]);
    }

    /**
     * The PDF's data in $lang (ar | en): an issued contract = only its snapshot (title, rendered text, values); a draft = live
     * (the template / owner's text with today's values). null text = no such language.
     */
    public function pdfData(int $accountId, $id, string $lang): array
    {
        $contract = $this->findContract($accountId, $id);
        if ($contract->isDraft()) {
            $reservation = $contract->reservation ?? abort(404);
            $values = $this->variables($reservation, $lang, $this->contractVariables($contract));
            $source = $this->sourceText($contract, $lang);
            $body = $source ? self::render($source, $values) : null;
            $title = $lang === 'en' ? ($contract->template?->title_en ?: $contract->title_en) : ($contract->template?->title_ar ?: $contract->title_ar);
        } else {
            $values = $contract->values[$lang] ?? [];
            $body = $contract->{'body_' . $lang};
            $title = $lang === 'en' ? $contract->title_en : $contract->title_ar;
        }
        return [
            'contract' => $contract,
            'lang' => $lang,
            'values' => $values,
            'body' => $body,
            'title' => $title ?: ($lang === 'en' ? 'Contract' : 'عقد'),
            'account' => Account::settingsOf($accountId),
        ];
    }

    // the languages a contract can print in: ar always, en when it has an English text
    public function languages(Contract $contract): array
    {
        $en = $contract->isDraft() ? $this->sourceText($contract, 'en') : $contract->body_en;
        return $en ? ['ar', 'en'] : ['ar'];
    }
}
