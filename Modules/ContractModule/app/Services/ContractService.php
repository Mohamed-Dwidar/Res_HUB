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
use Modules\ContractModule\app\Models\ContractEvent;
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

    // a contract's own value in the shape of its column (Arabic letters pasted from a PDF → normal letters, plainArabic())
    const TENANT_FIELDS = ['tenant_name', 'tenant_representative', 'tenant_title', 'tenant_national_id', 'tenant_commercial_register', 'tenant_tax_number', 'tenant_mobile', 'tenant_email'];

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
        $settings->fill(array_map(fn ($value) => is_string($value) ? self::plainArabic($value) : $value, $data))->save();
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

    // the unit's template of that type (on or off), null = none
    public function unitTemplate(int $accountId, int $unitId, string $kind = 'contract'): ?ContractTemplate
    {
        return $unitId ? $this->templates($accountId)->first(fn ($template) => ($template->kind ?: 'contract') === $kind && in_array($unitId, $template->unit_ids, true)) : null;
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
            'kind' => $original->kind,
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
        return $this->saveTemplate($accountId, new ContractTemplate(['account_id' => $accountId, 'kind' => ($data['kind'] ?? 'contract') === 'renewal' ? 'renewal' : 'contract']), $data, $creator);
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
        // only the texts the form sent (2026-11-25: the template page has no renewal annex editors any more; an old annex text stays)
        foreach (array_filter(self::BODIES, fn ($field) => $field === 'body_ar' || array_key_exists($field, $data)) as $field) {
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
            if ($error = $this->templateUnitsError($accountId, $unitIds, $template->id, $template->kind ?: 'contract')) {
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
            DB::table('contract_template_unit')->insert(array_map(fn ($unitId) => ['contract_template_id' => $template->id, 'unit_id' => $unitId,
                'kind' => $template->kind ?: 'contract', 'account_id' => $accountId], $unitIds));
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
    public function templateUnitsError(int $accountId, array $unitIds, ?int $exceptId = null, string $kind = 'contract'): ?string
    {
        if (!$unitIds) {
            return null;
        }
        $taken = DB::table('contract_template_unit')->join('contract_templates', 'contract_templates.id', '=', 'contract_template_unit.contract_template_id')
            ->where('contract_template_unit.account_id', $accountId)->where('contract_template_unit.kind', $kind)->whereNull('contract_templates.deleted_at')
            ->when($exceptId, fn ($query) => $query->where('contract_templates.id', '!=', $exceptId))
            ->whereIn('contract_template_unit.unit_id', $unitIds)->get(['contract_template_unit.unit_id', 'contract_templates.name'])->keyBy('unit_id');
        if ($taken->isEmpty()) {
            return null;
        }
        $units = Unit::whereIn('id', $taken->keys())->with('space')->get();
        return $units->map(fn ($unit) => $unit->name . ' (' . $unit->space?->name . ') already has "' . $taken[$unit->id]->name . '".')
            ->push('A unit has one ' . strtolower(ContractTemplate::KINDS[$kind] ?? 'contract') . ' template: untick these units, or choose another template for them from that template / the unit form first.')->implode(' ');
    }

    // the unit form's check: the chosen template is one of the account's, null = fine (none chosen = no contracts for the unit)
    public function unitTemplateError(int $accountId, $templateId, string $kind = 'contract'): ?string
    {
        return $templateId && !$this->templateRepository->forAccount($accountId)->where('kind', $kind)->whereKey((int) $templateId)->exists()
            ? 'The chosen contract template was not found, reload the page.' : null;
    }

    /**
     * The unit form's save (UnitService create / update, inside its transaction): the unit's template = $templateId (null = none;
     * the account's templates locked while checking, 422 under contract_template_id).
     */
    public function syncUnitTemplate(int $accountId, int $unitId, $templateId, string $kind = 'contract'): void
    {
        ContractTemplate::where('account_id', $accountId)->lockForUpdate()->pluck('id');
        if ($error = $this->unitTemplateError($accountId, $templateId, $kind)) {
            throw ValidationException::withMessages([$kind === 'renewal' ? 'renewal_template_id' : 'contract_template_id' => $error]);
        }
        DB::table('contract_template_unit')->where('unit_id', $unitId)->where('account_id', $accountId)->where('kind', $kind)->delete();
        if ($templateId) {
            DB::table('contract_template_unit')->insert(['contract_template_id' => (int) $templateId, 'unit_id' => $unitId, 'kind' => $kind, 'account_id' => $accountId]);
        }
        unset(self::$templates[$accountId]);
    }

    // the unit form's select: [id => name (Off)] + the chosen id
    public function unitFormData(int $accountId, ?int $unitId, string $kind = 'contract'): array
    {
        $templates = $this->templates($accountId)->filter(fn ($template) => ($template->kind ?: 'contract') === $kind);
        return [
            'options' => $templates->mapWithKeys(fn ($template) => [$template->id => $template->name . ($template->is_enabled ? '' : ' (Off)')])->all(),
            'selected' => $unitId ? $this->unitTemplate($accountId, $unitId, $kind)?->id : null,
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
    public function unitsTaken(int $accountId, ?int $exceptId = null, string $kind = 'contract'): array
    {
        $taken = [];
        foreach ($this->templates($accountId) as $template) {
            if ($template->id !== $exceptId && ($template->kind ?: 'contract') === $kind) {
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

    // {name} → its value, HTML-escaped (the text is HTML); an unknown {name} stays as typed; a date / number / Latin value is kept
    // left to right (LRE … PDF marks: inside an Arabic line its parts would be reordered)
    public static function render(?string $html, array $variables): string
    {
        return preg_replace_callback('/\{(\w+)\}/u', fn ($match) => array_key_exists($match[1], $variables)
            ? (preg_match('/^(?=.*\d)[\x20-\x7E]+$/', (string) $variables[$match[1]]) ? "\u{202A}" . e($variables[$match[1]]) . "\u{202C}" : e($variables[$match[1]]))
            : $match[0], (string) $html);
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
        // before tax (2026-11-25): the taxes are never part of the contract value
        $price = self::netPrice($reservation);
        $leasePeriod = (string) $reservation->plan?->lease_period;
        $months = $contract['term_months'] ?? null;
        $start = $contract['start_date'] ?? $reservation->start_at;
        $end = $contract['end_date'] ?? ($months && $start ? $start->copy()->addMonthsNoOverflow($months)->subDay() : $reservation->realEnd());
        $termAmount = self::termAmount($reservation, $months);
        $length = $months ?: self::leaseMonths($reservation, $start, $end);
        $taxed = $reservation->isTaxed();
        $money = fn ($amount) => number_format((float) $amount, 2);
        $idType = $member?->national_number ? ($member->id_type ?: 'national_id') : null;
        $own = $contract['fields'] ?? $this->fieldDefaults($reservation, $months);
        // the lessor: the contract's own, else (never set) the settings'; an English PDF keeps the settings' English name
        foreach ($this->lessorDefaults($accountId) as $field => $default) {
            $own[$field] ??= $default;
        }
        $text = fn ($field) => trim((string) ($own[$field] ?? ''));
        $date = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->format($format) : '';

        $variables = [
            'contract_no' => (string) ($contract['contract_no'] ?? ''),
            'contract_date' => ($contract['contract_date'] ?? now())->format($format),
            'start_date' => (string) $start?->format($format),
            'end_date' => (string) $end?->format($format),
            'term' => $length ? self::term($length, $lang) : (string) $reservation->period,
            'activity' => (string) ($contract['activity'] ?? ''),
            'deposit' => isset($contract['deposit']) && $contract['deposit'] !== null ? $money($contract['deposit']) : '',
            'previous_contract_no' => (string) ($contract['previous_contract_no'] ?? ''),
            'previous_contract_date' => (string) ($contract['previous_contract_date'] ?? ''),
            'original_contract_date' => (string) ($contract['original_contract_date'] ?? ''),

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
            'tenant_company' => $text('tenant_company') ?: (($contract['party_type'] ?? '') === 'company' ? $text('tenant_name') : ''),
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
            'company_name' => $text('tenant_company') ?: (($contract['party_type'] ?? '') === 'company' ? $text('tenant_name') : ''),
            'company_representative' => $text('tenant_representative'),
            'company_representative_title' => $text('tenant_title'),
            // 2026-11-25
            'contract_value_label' => $ar ? ['قيمة العقد', 1 => 'قيمة العقد الشهرية', 12 => 'قيمة العقد السنوية'][in_array($length, [1, 12], true) ? $length : 0]
                : ['Contract value', 1 => 'Monthly contract value', 12 => 'Annual contract value'][in_array($length, [1, 12], true) ? $length : 0],
            'contract_value_period' => ($ar ? [1 => 'شهريا', 12 => 'سنويا'] : [1 => 'per month', 12 => 'per year'])[$length] ?? '',
            'tenant_commercial_register' => $text('tenant_commercial_register'),
            'tenant_tax_number' => $text('tenant_tax_number'),
            'company_commercial_register' => $text('tenant_commercial_register'),
            'company_tax_number' => $text('tenant_tax_number'),
            'lessor_name' => !$ar && $settings->lessor_name_en && $text('lessor_name') === self::plainArabic($settings->lessor_name_ar) ? (string) $settings->lessor_name_en : $text('lessor_name'),
            'lessor_commercial_register' => $text('lessor_commercial_register'),
            'lessor_tax_number' => $text('lessor_tax_number'),
            'lessor_representative' => $text('lessor_representative'),
            'lessor_representative_title' => $text('lessor_representative_title'),
            'lessor_representative_national_id' => $text('lessor_representative_national_id'),
        ]);
    }

    // the lessor as Settings › Contracts has it (a new contract's own lessor fields)
    public function lessorDefaults(int $accountId): array
    {
        $settings = $this->settings($accountId);
        return array_map(fn ($value) => (string) self::plainArabic($value), [
            'lessor_name' => $settings->lessor_name_ar ?: $settings->lessor_name_en,
            'lessor_commercial_register' => $settings->commercial_register,
            'lessor_tax_number' => $settings->tax_number,
            'lessor_representative' => $settings->representative_name,
            'lessor_representative_title' => $settings->representative_title,
            'lessor_representative_national_id' => $settings->representative_national_id,
        ]);
    }

    // Arabic copied from a PDF comes as presentation forms (ﺷﻘﻪ): back to normal letters (شقه), so it prints and is searched
    public static function plainArabic(?string $text): ?string
    {
        return $text === null ? null : preg_replace_callback('/[\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFC}]+/u',
            fn ($match) => \Normalizer::normalize($match[0], \Normalizer::FORM_KC) ?: $match[0], $text);
    }

    // the reservation's price before tax (2026-11-25): the net of its tax snapshot when an invoice is requested, else its net
    public static function netPrice(Reservation $reservation): float
    {
        return round((float) ($reservation->isTaxed() ? $reservation->tax_net : $reservation->net_amount), 2);
    }

    // the length in months of a contract without a term of its own, by the plan's lease period and the dates (365 days of a year
    // plan = 12, 1,095 = 36; 30 days of a month plan = 1); null = another lease period
    public static function leaseMonths(Reservation $reservation, $start, $end): ?int
    {
        $lease = (string) $reservation->plan?->lease_period;
        if (!in_array($lease, ['month', 'year'], true) || !$start || !$end) {
            return null;
        }
        $days = (int) round($start->copy()->startOfDay()->diffInDays($end->copy()->startOfDay())) + 1;
        return $lease === 'year' ? max(1, (int) round($days / 365)) * 12 : max(1, (int) round($days / 30.4));
    }

    /**
     * The contract's own data pre-filled (a new draft; the template page's preview): unit number = the reservation's Unit no. (none:
     * the unit's name, empty for a shared unit such as Virtual Office), contract date = today (emptied = the issue date), duration in words
     * (the term / the plan), value = the price BEFORE TAX for the term + the default currency, the lessor = Settings › Contracts,
     * the tenant = the member's contract data (legal name, else the company when it has legal data, else the member), unit address =
     * the property address, activity = the member's, notes "لا توجد".
     * A company without legal data ("Other", "Freelancer" …) is never printed.
     */
    public function fieldDefaults(Reservation $reservation, ?int $months): array
    {
        $member = $reservation->member;
        $company = $member ? self::legalCompany($member) : null;
        $settings = $this->settings((int) $reservation->account_id);
        $unit = $reservation->unit;
        $length = $months ?: self::leaseMonths($reservation, $reservation->start_at, $reservation->realEnd());
        $mobiles = array_filter([(string) $member?->phone, (string) $member?->other_phone], fn ($phone) => $phone !== '');
        return $this->lessorDefaults((int) $reservation->account_id) + [
            // the unit number belongs to the contract (user 2026-11-25): a numbered unit = the suggested free number (suggestNumber()); a
            // shared unit without numbering = typed on the contract; else the unit's name
            'unit_number' => (string) ($this->suggestNumber($unit) ?? ((int) $unit?->concurrent_usage > 1 ? '' : ($unit?->name_ar ?: $unit?->name_en))),
            'contract_date' => (Account::settingsOf((int) $reservation->account_id)?->businessDate() ?? now())->format('Y-m-d'),
            'duration_text' => $length ? self::term($length) : '',
            'term_amount' => self::termAmount($reservation, $months),
            'currency' => (string) config('contractmodule.currency'),
            'tenant_name' => (string) ($member?->legal_name ?: ($company ? ($company->name_ar ?: $company->name_en) : $member?->name)),
            'tenant_representative' => (string) ($member?->representative_name ?: ($company?->representative_name ?: $member?->name)),
            'tenant_title' => (string) ($member?->representative_title ?: $company?->representative_title),
            'tenant_national_id' => (string) ($member?->national_number ?: $company?->representative_national_id),
            'tenant_commercial_register' => (string) ($member?->commercial_register ?: $company?->commercial_register),
            'tenant_tax_number' => (string) ($member?->tax_number ?: $company?->tax_number),
            'tenant_mobile' => implode(' - ', $mobiles),
            // a stored email that is not one ("--" in old data) is not offered: the page would refuse it
            'tenant_email' => filter_var((string) $member?->email, FILTER_VALIDATE_EMAIL) ? (string) $member->email : '',
            'unit_address' => (string) self::plainArabic($settings->property_address_ar ?: $settings->property_address_en),
            'activity' => (string) $member?->activity,
            'deposit_amount' => null,
            'notes' => (string) config('contractmodule.notes'),
        ];
    }

    // the member's company when it has legal data (a commercial register / a representative), else null
    private static function legalCompany(Member $member): ?\Modules\CompanyModule\app\Models\Company
    {
        $company = $member->company_id ? $member->company : null;
        return $company && ($company->commercial_register || $company->representative_name) ? $company : null;
    }

    // 1 → شهر, 2 → شهران, 3–10 → 3 أشهر, 11+ → 18 شهرًا; whole years: سنة ميلادية, سنتان ميلاديتان, 3 سنوات ميلادية (custom.js the same)
    public static function term(int $months, string $lang = 'ar'): string
    {
        $years = $months % 12 === 0 ? intdiv($months, 12) : 0;
        if ($lang !== 'ar') {
            return $years ? $years . ' ' . ($years === 1 ? 'year' : 'years') : $months . ' ' . ($months === 1 ? 'month' : 'months');
        }
        return match (true) {
            $years === 1 => 'سنة ميلادية',
            $years === 2 => 'سنتان ميلاديتان',
            $years > 0 => $years . ($years <= 10 ? ' سنوات ميلادية' : ' سنة ميلادية'),
            $months === 1 => 'شهر',
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
            'previous_contract_no' => max(1, $this->settings($accountId)->number_start - 1), 'previous_contract_date' => now()->subYear()->format(Reservation::DATE_FORMAT),
            'original_contract_date' => now()->subYear()->format(Reservation::DATE_FORMAT)];
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
            ->get(['id', 'account_id', 'contract_no', 'kind', 'renewal_of', 'status', 'reservation_id', 'member_id', 'unit_id', 'unit_number', 'starts_on', 'ends_on', 'ended_on', 'creatable_type', 'creatable_id'])
            ->groupBy(fn ($contract) => $contract->member_id . '-' . $contract->unit_id);
    }

    /**
     * The not cancelled contract covering the reservation (2026-11-25): made from it, or of its member + unit with a term holding its
     * start day; a member with two places of a shared unit (two contracts on those dates): the one whose reservation is in this period's
     * renewal chain (the unit number belongs to the contract, not to the reservation; user 2026-11-25).
     * One query per request (accountContracts()); filterReservations() = the same rule in SQL.
     */
    public function coveringContract(Reservation $reservation): ?Contract
    {
        if (!$reservation->member_id || !$reservation->unit_id || !$reservation->start_at) {
            return null;
        }
        $date = $reservation->start_at->copy()->startOfDay();
        $contracts = $this->accountContracts((int) $reservation->account_id)->get($reservation->member_id . '-' . $reservation->unit_id, collect());
        return $contracts->first(fn ($contract) => (int) $contract->reservation_id === (int) $reservation->id)
            ?? self::ofChain($reservation, $contracts->filter(fn ($contract) => $contract->starts_on->lte($date) && $contract->covers_until->gte($date))->values());
    }

    // of several candidate contracts, the one made from this reservation's renewal chain (its earlier periods); one candidate = it
    private static function ofChain(Reservation $reservation, Collection $candidates): ?Contract
    {
        if ($candidates->count() <= 1) {
            return $candidates->first();
        }
        $chain = [];
        for ($row = $reservation, $steps = 0; $row && $steps < 60; $steps++) {
            $chain[] = (int) $row->id;
            $row = $row->renewal_of ? Reservation::withTrashed()->select(['id', 'renewal_of'])->find($row->renewal_of) : null;
        }
        return $candidates->first(fn ($contract) => in_array((int) $contract->reservation_id, $chain, true)) ?? $candidates->first();
    }

    // a unit number as compared: "V 138" = "v-138" = "V138"
    private static function numberKey(?string $value): string
    {
        return mb_strtoupper(str_replace([' ', '-', '.'], '', (string) $value));
    }

    /**
     * The reservations list "Contract" filter (2026-11-25): counted subscriptions with a covering contract | the current ones (not
     * ended by the business date, not left) without one. The coveringContract() rule in SQL.
     */
    public function filterReservations($query, string $filter): void
    {
        if (!in_array($filter, ['with', 'without'], true)) {
            return;
        }
        $covering = fn ($sub) => $sub->selectRaw('1')->from('contracts')->whereNull('contracts.deleted_at')->where('contracts.status', '!=', 'cancelled')
            ->whereColumn('contracts.account_id', 'reservations.account_id')->whereColumn('contracts.member_id', 'reservations.member_id')
            ->whereColumn('contracts.unit_id', 'reservations.unit_id')->where('contracts.unit_id', '>', 0)->where('contracts.member_id', '>', 0)
            ->whereRaw("(contracts.reservation_id = reservations.id OR (contracts.starts_on <= DATE(reservations.start_at) AND LEAST(contracts.ends_on, COALESCE(contracts.ended_on, contracts.ends_on)) >= DATE(reservations.start_at)))");
        $query->counted()->whereIn('reservations.subscription_type_id', SubscriptionType::idsOf('period') ?: [0]);
        if ($filter === 'with') {
            $query->whereExists($covering);
        } else {
            $query->whereNotExists($covering)->whereNull('reservations.left_at')->where('reservations.end_at', '>=', Reservation::businessToday()[0]);
        }
    }

    /**
     * The contract state of a reservation (its view's Contract block, the reservations list row menu, the badge), by its start day:
     * ['state' => 'covered', 'contract'] = a not cancelled contract / annex covers it (made from it, or its term holds the start; an
     * ended one only up to its end date);
     * ['state' => 'ended', 'contract' => the chain's last issued / signed contract before it, 'template' => the unit's renewal annex
     * template (null = none switched on: no annex button)] → Renewal annex (Phase 3, 2026-11-25: only a chain WITH a contract gets one);
     * ['state' => 'none', 'template'] → Create contract (the unit's contract template switched on; a renewed period without any
     * contract = "No contract — issue a contract first"); null = nothing to offer.
     */
    public function reservationState(Reservation $reservation): ?array
    {
        $accountId = (int) $reservation->account_id;
        if (!$reservation->member_id || !$reservation->unit_id || !$reservation->start_at) {
            return null;
        }
        if ($covering = $this->coveringContract($reservation)) {
            return ['state' => 'covered', 'contract' => $covering];
        }
        if ($previous = $this->previousContract($reservation)) {
            return ['state' => 'ended', 'contract' => $previous, 'template' => $this->annexTemplate($accountId, (int) $reservation->unit_id)];
        }
        $template = $this->unitTemplate($accountId, (int) $reservation->unit_id);
        return $template && $template->is_enabled ? ['state' => 'none', 'template' => $template] : null;
    }

    // the chain's last issued / signed contract or annex of the member's unit ending before the period starts (two places of a shared
    // unit: the one of this period's renewal chain); an ended (terminated) or cancelled one closes the chain
    public function previousContract(Reservation $reservation): ?Contract
    {
        $date = $reservation->start_at->copy()->startOfDay();
        $before = $this->accountContracts((int) $reservation->account_id)->get($reservation->member_id . '-' . $reservation->unit_id, collect())
            ->filter(fn ($contract) => $contract->status !== 'draft' && $contract->ends_on->lt($date))->values();
        // the newest one of each place (a place = its original contract's chain)
        $last = self::ofChain($reservation, $before->unique(fn ($contract) => self::numberKey($contract->unit_number))->values());
        return $last && in_array($last->status, ['issued', 'signed'], true) ? $last : null;
    }

    // the renewal annex template of a unit (switched on): its own "Renewal annex" template, else (old templates) its contract template
    // when that one has an annex text; null = none
    public function annexTemplate(int $accountId, int $unitId): ?ContractTemplate
    {
        $annex = $this->unitTemplate($accountId, $unitId, 'renewal');
        if ($annex) {
            return $annex->is_enabled ? $annex : null;
        }
        $contract = $this->unitTemplate($accountId, $unitId);
        return $contract && $contract->is_enabled && $contract->renewal_body_ar ? $contract : null;
    }

    /**
     * A contract with its annexes, oldest first (the original contract, then every annex renewing it or one of its annexes; cancelled
     * ones too) — the timeline of the reservation view and the contract view.
     */
    public function chain(Contract $contract): Collection
    {
        $root = self::original($contract) ?? $contract;
        $all = collect([$root]);
        $ids = [$root->id];
        for ($steps = 0; $ids && $steps < 50; $steps++) {
            $children = Contract::where('account_id', $root->account_id)->whereIn('renewal_of', $ids)->get();
            $all = $all->merge($children);
            $ids = $children->pluck('id')->all();
        }
        return $all->unique('id')->sortBy(fn ($item) => [$item->starts_on->format('Ymd'), $item->id])->values();
    }

    // the reservations list row menu: Create / Renew contract, or the covering contract (view) — [] = nothing
    public function menuLinks(Reservation $reservation, string $area): array
    {
        $state = $this->reservationState($reservation);
        return match ($state['state'] ?? null) {
            // the draft page = a full page (its editors need room)
            'none' => [['label' => 'Create Contract', 'icon' => 'file-plus', 'url' => route($area . '.contracts.create', ['reservation_id' => $reservation->id])]],
            'ended' => !empty($state['template']) ? [['label' => 'Renewal Annex', 'icon' => 'file-plus', 'url' => route($area . '.contracts.create', ['reservation_id' => $reservation->id])]] : [],
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
     * (term from the template's default_term_months, else a year / month by the subscription's plan; a renewal starts the day after the previous
     * contract and keeps its party, deposit, activity). 422 when the reservation can't get a contract (no template / covered).
     */
    public function draftFor(Reservation $reservation): array
    {
        $state = $this->reservationState($reservation);
        if (($state['state'] ?? null) === 'covered') {
            throw ValidationException::withMessages(['reservation_id' => 'This reservation is already covered by contract ' . $state['contract']->number_label . '.']);
        }
        if (!$state || empty($state['template'])) {
            throw ValidationException::withMessages(['reservation_id' => ($state['state'] ?? null) === 'ended'
                ? 'This unit has no renewal annex template switched on (Settings › Contracts).'
                : 'This reservation\'s unit has no contract template switched on (Settings › Contracts).']);
        }
        $previous = $state['state'] === 'ended' ? Contract::find($state['contract']->id) : null;
        $template = $state['template'];
        $startsOn = $reservation->start_at->copy()->startOfDay();
        // the template's term (a contract template's), else the subscription's own: a year / a month by its plan (leaseMonths())
        $templateMonths = $template->kind === 'renewal' ? null : $template->default_term_months;
        $months = $templateMonths ?: self::leaseMonths($reservation, $reservation->start_at, $reservation->realEnd());

        // the contract's own data: pre-filled from the member / company / settings; an annex (Phase 3, 2026-11-25) from the ORIGINAL
        // contract (lessor, lessee, unit no., address, activity — as edited there): only the period, duration and value from the renewal,
        // its date = the issue date
        $fields = $this->fieldDefaults($reservation, $months);
        if ($previous) {
            $original = self::original($previous) ?? $previous;
            foreach (array_merge(['currency', 'unit_number', 'unit_address', 'activity', 'deposit_amount'], self::TENANT_FIELDS, Contract::LESSOR_FIELDS) as $field) {
                if ($original->{$field} !== null) {
                    $fields[$field] = $original->{$field};
                }
            }
            // an old company contract: its company name is the tenant's name now
            $fields['tenant_name'] = $original->party_type === 'company' ? ($original->tenant_company ?: $original->tenant_name) : $original->tenant_name;
            $fields['contract_date'] = null;
        }

        return [
            'kind' => $previous ? 'renewal' : 'contract',
            'previous' => $previous,
            'template' => $template,
            'starts_on' => $startsOn,
            'term_months' => $months,
            // the subscription's own term = its last day (a billing day may make it a day off start + months)
            'ends_on' => $this->termEnd($startsOn, $templateMonths ? $months : null, $reservation),
            'party_type' => isset($original) ? $original->party_type : (($fields['tenant_commercial_register'] ?? '') !== '' || ($reservation->member && !$reservation->member->legal_name && self::legalCompany($reservation->member)) ? 'company' : 'individual'),
        ] + $fields;
    }

    // the last day of a term: start + months − 1 day; no term = the reservation's last day
    public function termEnd($startsOn, ?int $months, Reservation $reservation)
    {
        return $months ? $startsOn->copy()->addMonthsNoOverflow($months)->subDay() : ($reservation->realEnd() ?? $startsOn)->copy()->startOfDay();
    }

    // the price for the whole term: the reservation's price ÷ its own months (leaseMonths()) × the term, else the reservation's price
    // before tax (netPrice())
    public static function termAmount(Reservation $reservation, ?int $months): float
    {
        $price = self::netPrice($reservation);
        $own = $months ? self::leaseMonths($reservation, $reservation->start_at, $reservation->realEnd()) : null;
        return round($own ? $price * $months / $own : $price, 2);
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
            $this->fillDraft($contract, $reservation, $data, $creator);
            $this->setText($contract, $data);
            $contract->save();
            unset(self::$contracts[$accountId]);
            return $contract;
        });
    }

    // the draft page again (account + employees): term, party, deposit, activity, notes, its wording; never after issue
    public function updateDraft(int $accountId, $id, array $data, ?Model $by = null): Contract
    {
        return DB::transaction(function () use ($accountId, $id, $data, $by) {
            $contract = $this->contractRepository->forAccount($accountId)->lockForUpdate()->findOrFail($id);
            if (!$contract->isDraft()) {
                throw ValidationException::withMessages(['status' => 'Only a draft can be edited: this contract is ' . strtolower($contract->status_label) . '.']);
            }
            $this->fillDraft($contract, $this->findReservation($accountId, $contract->reservation_id), $data, $by);
            $this->setText($contract, $data);
            $contract->save();
            unset(self::$contracts[$accountId]);
            return $contract;
        });
    }

    // the draft page's fields (the contract's own data; never the member / company unless "Also save to member / company"); the
    // term must not overlap another contract of the member's unit
    private function fillDraft(Contract $contract, Reservation $reservation, array $data, ?Model $by = null): void
    {
        // an annex (user 2026-11-25): the original contract's data and the renewal's period / amount, never what the page sent
        if ($contract->isRenewal()) {
            $data = array_merge($data, $this->annexData($contract, $reservation));
        }
        $partyType = ($data['party_type'] ?? 'individual') === 'company' ? 'company' : 'individual';
        $months = ($data['term_months'] ?? null) ? (int) $data['term_months'] : null;
        $startsOn = \Illuminate\Support\Carbon::parse($data['starts_on'])->startOfDay();
        $endsOn = \Illuminate\Support\Carbon::parse($data['ends_on'])->startOfDay();
        if ($endsOn->lt($startsOn)) {
            throw ValidationException::withMessages(['ends_on' => 'The end must be on or after the start.']);
        }
        $overlap = Contract::where('account_id', $contract->account_id)->where('member_id', $contract->member_id)->where('unit_id', $contract->unit_id)
            ->where('status', '!=', 'cancelled')->when($contract->exists, fn ($query) => $query->whereKeyNot($contract->id))
            ->whereDate('starts_on', '<=', $endsOn)->whereDate('ends_on', '>=', $startsOn)->get()
            // a shared unit (Virtual Office): another unit number of the same member is another place = another contract
            ->first(fn ($other) => (int) $reservation->unit?->concurrent_usage <= 1 || self::numberKey($other->unit_number) === '' || self::numberKey($data['unit_number'] ?? '') === ''
                || self::numberKey($other->unit_number) === self::numberKey($data['unit_number'] ?? ''));
        if ($overlap) {
            throw ValidationException::withMessages(['starts_on' => 'Contract ' . $overlap->number_label . ' (' . $overlap->starts_on->format(Reservation::DATE_FORMAT) . ' → '
                . $overlap->ends_on->format(Reservation::DATE_FORMAT) . ') already covers part of these dates for this member and unit'
                . ((int) $reservation->unit?->concurrent_usage > 1 ? ' (another place of the unit needs its own unit number on both contracts).' : '.')]);
        }
        // the unit number (2026-11-25): free in its unit (not held by another active chain); a numbered unit's suggestion = changed by the
        // owner only (an annex keeps its contract's number: annexData())
        if (!$contract->isRenewal()) {
            $this->checkNumber($contract, $reservation, trim((string) ($data['unit_number'] ?? '')), $by);
        }
        // a contract has its date; an annex may leave it empty (= its issue date)
        if (!$contract->isRenewal() && trim((string) ($data['contract_date'] ?? '')) === '') {
            throw ValidationException::withMessages(['contract_date' => 'The contract date is required.']);
        }
        $member = $reservation->member;
        $clean = fn ($field) => ($value = trim((string) self::plainArabic($data[$field] ?? ''))) === '' ? null : $value;

        $contract->fill([
            'party_type' => $partyType,
            'company_id' => $partyType === 'company' && $member && self::legalCompany($member) ? (int) $member->company_id : 0,
            // one tenant name since 2026-11-25 (tenant_name)
            'tenant_company' => null,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'term_months' => $months,
            'amount' => $reservation->dueAmount(),
            'term_amount' => isset($data['contract_value']) && $data['contract_value'] !== '' ? round((float) $data['contract_value'], 2) : self::termAmount($reservation, $months),
            'deposit_amount' => isset($data['deposit_amount']) && $data['deposit_amount'] !== '' ? round((float) $data['deposit_amount'], 2) : null,
            'contract_date' => $clean('contract_date'),
        ] + collect(array_merge(['unit_number', 'duration_text', 'currency', 'unit_address', 'activity', 'notes'], self::TENANT_FIELDS))->mapWithKeys(fn ($field) => [$field => $clean($field)])->all()
            // '' = emptied on this contract; not sent = the settings' now
            + collect($this->lessorDefaults((int) $contract->account_id))->map(fn ($default, $field) => array_key_exists($field, $data) ? (string) $clean($field) : ($contract->{$field} ?? $default))->all());
        // the commercial register / tax card: a company only (the form hides them for an individual)
        if ($partyType !== 'company') {
            $contract->fill(['tenant_commercial_register' => null, 'tenant_tax_number' => null]);
        }
        if (!empty($data['save_to_member'])) {
            $this->saveBack($member, $contract);
        }
    }

    /**
     * The fixed data of a renewal annex (user 2026-11-25: not edited on the annex): lessor, lessee, unit number, address, activity,
     * currency, deposit and the contract type from the ORIGINAL contract (as edited there; a lessor field never set = Settings ›
     * Contracts); the period from the renewal reservation (its start → end; the term = the months between them) and its price before
     * tax; the date = the issue date. Nothing of it is edited on the annex page (user 2026-11-25: it shows the annex text only).
     * In the shape of the draft page's fields (fillDraft()).
     */
    public function annexData(Contract $contract, Reservation $reservation): array
    {
        $original = self::original($contract->previous) ?? $contract->previous;
        // the renewal's own dates; the term = the months from its start to its end (365 days = سنة ميلادية, 30 = شهر)
        $start = $reservation->start_at->copy()->startOfDay();
        $end = ($reservation->realEnd() ?? $start)->copy()->startOfDay();
        $months = max(1, (int) round(((int) round($start->diffInDays($end)) + 1) / 30.4));
        $data = [
            'starts_on' => $start->format('Y-m-d'),
            'ends_on' => $end->format('Y-m-d'),
            'term_months' => $months,
            'duration_text' => $months ? self::term($months) : (string) $reservation->period,
            'contract_value' => self::netPrice($reservation),
            'contract_date' => null,
            'notes' => (string) config('contractmodule.notes'),
        ];
        if (!$original) {
            return $data;
        }
        $lessor = $this->lessorDefaults((int) $contract->account_id);
        foreach (array_merge(['currency', 'unit_number', 'unit_address', 'activity', 'deposit_amount'], self::TENANT_FIELDS, Contract::LESSOR_FIELDS) as $field) {
            $data[$field] = $original->{$field} ?? ($lessor[$field] ?? null);
        }
        $data['party_type'] = $original->party_type;
        $data['tenant_name'] = $original->party_type === 'company' ? ($original->tenant_company ?: $original->tenant_name) : $original->tenant_name;
        $data['save_to_member'] = false;
        return $data;
    }

    // the annex page's text values (Arabic): the draft (or the saved annex) with annexData() applied, as it will print
    public function annexPreview(Reservation $reservation, ?Contract $contract, ?array $draft): array
    {
        $contract ??= $this->draftContract($draft);
        $contract->fill(array_intersect_key($this->annexData($contract, $reservation), array_flip(Contract::OWN_FIELDS))
            + ['starts_on' => $reservation->start_at->copy()->startOfDay(), 'ends_on' => ($reservation->realEnd() ?? $reservation->start_at)->copy()->startOfDay()]);
        $data = $this->annexData($contract, $reservation);
        $contract->term_amount = $data['contract_value'];
        $contract->duration_text = $data['duration_text'];
        return $this->variables($reservation, 'ar', $this->contractVariables($contract));
    }

    /**
     * "Also save to member" (unticked by default): the member's EMPTY fields get what was typed on the contract (ID number when it is
     * 5–20 letters / digits, email, legal name / representative when they differ from the member's name, title, commercial register,
     * tax card, activity); not what already comes from the member's company. Nothing that is already filled is changed; the phone
     * never (it is the member's key).
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
        $company = self::legalCompany($member);
        $known = ['legal_name' => [$member->name, $company?->name_ar, $company?->name_en], 'representative_name' => [$member->name, $company?->representative_name],
            'representative_title' => [$company?->representative_title], 'commercial_register' => [$company?->commercial_register], 'tax_number' => [$company?->tax_number], 'activity' => []];
        foreach (['legal_name' => 'tenant_name', 'representative_name' => 'tenant_representative', 'representative_title' => 'tenant_title',
                'commercial_register' => 'tenant_commercial_register', 'tax_number' => 'tenant_tax_number', 'activity' => 'activity'] as $field => $from) {
            if (!$member->{$field} && $contract->{$from} && !in_array($contract->{$from}, $known[$field], true)) {
                $member->{$field} = $contract->{$from};
            }
        }
        if ($member->isDirty()) {
            $member->save();
        }
    }

    // what is still missing to issue: the same fields as the draft page (Contract::REQUIRED_FIELDS; a draft saved before 2026-11-25
    // may lack some); a lessor field never set = Settings › Contracts'
    public function missingToIssue(Contract $contract): array
    {
        $required = Contract::REQUIRED_FIELDS + ($contract->party_type === 'company' ? Contract::COMPANY_FIELDS : []);
        // an annex: its date = the issue date when empty
        if ($contract->isRenewal()) {
            unset($required['contract_date']);
        }
        $lessor = $this->lessorDefaults((int) $contract->account_id);
        $value = fn ($field) => $field === 'tenant_name' && $contract->party_type === 'company' && $contract->tenant_company
            ? $contract->tenant_company : ($contract->{$field} ?? $lessor[$field] ?? '');
        return array_values(array_filter($required, fn ($label, $field) => trim((string) $value($field)) === '', ARRAY_FILTER_USE_BOTH));
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
        // a renewal annex template's text is the annex; an (old) contract template: its annex text, else the full text
        if ($template->kind === 'renewal') {
            return $template->{'body_' . $lang} ?: null;
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
            'original_contract_date' => (($original = self::original($previous))?->contract_date ?? $original?->issued_at)?->format(Reservation::DATE_FORMAT),
        ];
    }

    /**
     * Unit numbers (2026-11-25, user: on the CONTRACT): the numbers held in a unit = its chains (a contract + its annexes, one number)
     * whose latest item is a draft / issued / signed — whatever the dates (an ended term still holds it until Mark left terminates it). [number key => the chain's latest contract], $except = a chain (its root id) left out.
     */
    public function heldNumbers(int $accountId, int $unitId, ?int $exceptRoot = null): Collection
    {
        $all = Contract::where('account_id', $accountId)->where('unit_id', $unitId)->get(['id', 'renewal_of', 'status', 'unit_number', 'tenant_name', 'tenant_company', 'party_type', 'member_id', 'contract_no', 'starts_on'])->keyBy('id');
        $root = function ($contract) use ($all) {
            for ($steps = 0; $contract->renewal_of && isset($all[$contract->renewal_of]) && $steps < 60; $steps++) {
                $contract = $all[$contract->renewal_of];
            }
            return (int) $contract->id;
        };
        // a chain holds its number while its LATEST item is a draft / issued / signed (Mark left terminates the latest = the number is free)
        return $all->filter(fn ($contract) => self::numberKey($contract->unit_number) !== '' && $contract->status !== 'cancelled')
            ->groupBy(fn ($contract) => $root($contract))
            ->reject(fn ($items, $chainRoot) => $exceptRoot !== null && (int) $chainRoot === $exceptRoot)
            ->map(fn ($items) => $items->sortBy(fn ($contract) => [$contract->starts_on?->format('Ymd'), $contract->id])->last())
            ->filter(fn ($latest) => in_array($latest->status, ['draft', 'issued', 'signed'], true))
            ->keyBy(fn ($latest) => self::numberKey($latest->unit_number));
    }

    // the suggested number of a numbered unit (a prefix or a first number set): the lowest free one from the first number (else the
    // lowest held, else 1) up to the highest held, else the highest + 1; null = the unit has no numbering
    public function suggestNumber(?Unit $unit): ?string
    {
        if (!$unit || ((string) $unit->unit_no_prefix === '' && !$unit->unit_no_start)) {
            return null;
        }
        $prefix = (string) $unit->unit_no_prefix;
        $key = self::numberKey($prefix);
        $used = $this->heldNumbers((int) $unit->account_id, (int) $unit->id)->keys()
            ->filter(fn ($number) => $key === '' ? ctype_digit($number) : str_starts_with($number, $key) && ctype_digit(substr($number, strlen($key))))
            ->map(fn ($number) => (int) substr($number, strlen($key)))->unique()->sort()->values();
        $start = (int) ($unit->unit_no_start ?: ($used->first() ?: 1));
        $max = (int) ($used->max() ?? ($start - 1));
        for ($number = $start; $number <= $max; $number++) {
            if (!$used->contains($number)) {
                return $prefix . $number;
            }
        }
        return $prefix . max($start, $max + 1);
    }

    // a draft contract's unit number: not held by another chain of its unit (422 with who holds it); on a numbered unit an employee
    // keeps the suggested / saved number (the owner types any free one)
    private function checkNumber(Contract $contract, Reservation $reservation, string $number, ?Model $by): void
    {
        $unit = $reservation->unit;
        if ($number === '' || !$unit) {
            return;
        }
        $holder = $this->heldNumbers((int) $contract->account_id, (int) $unit->id, $contract->exists ? (int) (self::original($contract) ?? $contract)->id : null)
            ->get(self::numberKey($number));
        if ($holder && (int) $holder->id !== (int) $contract->id) {
            throw ValidationException::withMessages(['unit_number' => $number . ' is held by ' . ($holder->party_type === 'company' && $holder->tenant_company ? $holder->tenant_company : ($holder->tenant_name ?: 'member #' . $holder->member_id))
                . ' (' . ($holder->contract_no ? 'contract #' . $holder->contract_no : 'a draft') . '). Choose a free number.']);
        }
        $suggested = $this->suggestNumber($unit);
        $kept = $contract->exists ? self::numberKey($contract->getOriginal('unit_number')) : self::numberKey($suggested);
        if ($suggested !== null && $by && !self::isOwner($by) && self::numberKey($number) !== $kept) {
            throw ValidationException::withMessages(['unit_number' => 'Only the account owner can change the unit number (suggested: ' . ($contract->exists ? $contract->getOriginal('unit_number') : $suggested) . ').']);
        }
    }

    // the original contract of a chain (the draft page's note)
    public static function originalOf(?Contract $contract): ?Contract
    {
        return self::original($contract);
    }

    // the contract a chain of renewal annexes starts from ("التعاقد الأصلي")
    private static function original(?Contract $contract): ?Contract
    {
        for ($steps = 0; $contract?->renewal_of && $steps < 50 && $contract->previous; $steps++) {
            $contract = $contract->previous;
        }
        return $contract;
    }

    // printed as a renewal annex (the template's annex text: its own short layout) — not a renewal that prints the full contract text;
    // an issued contract = as it was when issued (its snapshot)
    public function isAnnex(Contract $contract, string $lang): bool
    {
        return $contract->isDraft()
            ? $contract->isRenewal() && ($contract->template?->kind === 'renewal' ? (bool) $contract->template->{'body_' . $lang} : (bool) $contract->template?->{'renewal_body_' . $lang})
            : (bool) ($contract->values['annex'][$lang] ?? false);
    }

    // the template's title, "ملحق تجديد …" for an annex
    private function printedTitle(Contract $contract, string $lang, bool $annex): ?string
    {
        $title = $contract->template?->{'title_' . $lang} ?: $contract->{'title_' . $lang};
        // a renewal annex template carries its own title ("ملحق تجديد …")
        return $title && $annex && $contract->template?->kind !== 'renewal' ? ($lang === 'en' ? 'Renewal Annex: ' : 'ملحق تجديد ') . $title : $title;
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
                throw ValidationException::withMessages(['status' => 'To issue it, fill ' . implode(', ', $missing) . ' on the contract (Edit Draft).']);
            }
            if (!$this->sourceText($contract, 'ar')) {
                throw ValidationException::withMessages(['status' => 'The contract has no Arabic text (its template\'s text is empty).']);
            }
            $reservation = $this->findReservation($accountId, $contract->reservation_id);
            // a contract set back to draft keeps its number (unissue()); a deleted draft's number is not used again
            $number = $contract->contract_no ?: max((int) $settings->number_start, (int) Contract::withTrashed()->where('account_id', $accountId)->max('contract_no') + 1);

            $contract->contract_no = $number;
            $contract->issued_at = now();
            $contract->amount = $reservation->dueAmount();
            // empty = the issue date
            $contract->contract_date ??= (Account::settingsOf($accountId)?->businessDate() ?? now())->copy()->startOfDay();
            $input = $this->contractVariables($contract);
            // source = the draft's own texts (NULL = the template's), given back by unissue(); history = its earlier unissues
            $values = ['party_type' => $contract->party_type, 'source' => ['ar' => $contract->body_ar, 'en' => $contract->body_en]];
            foreach (['ar', 'en'] as $lang) {
                $values[$lang] = $this->variables($reservation, $lang, $input);
                $source = $this->sourceText($contract, $lang);
                $contract->{'body_' . $lang} = $source ? self::render($source, $values[$lang]) : null;
            }
            $values['annex'] = ['ar' => $this->isAnnex($contract, 'ar'), 'en' => $this->isAnnex($contract, 'en')];
            $contract->title_ar = $this->printedTitle($contract, 'ar', $values['annex']['ar']);
            $contract->title_en = $this->printedTitle($contract, 'en', $values['annex']['en']);
            $contract->values = $values;
            $contract->status = 'issued';
            $contract->issued_by = $user->getKey();
            $contract->save();
            $this->event($contract, 'issued', $user, null, null, ['contract_no' => $contract->contract_no]);
            unset(self::$contracts[$accountId]);
            return $contract;
        });
    }

    /**
     * Back to draft (2026-11-25, user: the account owner only, with a reason): an issued contract, not signed and not renewed, becomes
     * a draft again — its number is kept for the next issue, its texts go back to the draft's (values.source; issued before that was
     * kept: the template's when they print the same, else the issued text). Logged in values.history (who, when, reason), shown on
     * its page.
     */
    public function unissue(int $accountId, $id, string $reason, Model $user): Contract
    {
        return DB::transaction(function () use ($accountId, $id, $reason, $user) {
            $contract = $this->contractRepository->forAccount($accountId)->lockForUpdate()->findOrFail($id);
            if (!self::isOwner($user)) {
                throw ValidationException::withMessages(['reason' => 'Only the account owner can set a contract back to draft.']);
            }
            if ($contract->status !== 'issued') {
                throw ValidationException::withMessages(['reason' => 'Only an issued contract can be set back to draft: this one is ' . strtolower($contract->status_label) . '.']);
            }
            if ($contract->renewals()->exists()) {
                throw ValidationException::withMessages(['reason' => 'This contract has a renewal annex: it can\'t be set back to draft.']);
            }
            $values = $contract->values ?? [];
            foreach (['ar', 'en'] as $lang) {
                $body = $contract->{'body_' . $lang};
                $contract->{'body_' . $lang} = array_key_exists('source', $values) ? ($values['source'][$lang] ?? null)
                    : ($body === self::render($this->templateText($contract, $contract->template, $lang), $values[$lang] ?? []) ? null : $body);
            }
            $contract->title_ar = $contract->template?->title_ar ?? $contract->title_ar;
            $contract->title_en = $contract->template?->title_en ?? $contract->title_en;
            // an older back-to-draft line kept (values.history, before the events)
            $contract->values = isset($values['history']) ? ['history' => $values['history']] : null;
            $this->event($contract, 'back_to_draft', $user, null, $reason, ['contract_no' => $contract->contract_no, 'issued_at' => $contract->issued_at?->format('Y-m-d H:i:s')]);
            $contract->status = 'draft';
            $contract->issued_at = null;
            $contract->issued_by = null;
            $contract->save();
            unset(self::$contracts[$accountId]);
            return $contract;
        });
    }

    /**
     * Mark left (ReservationService::markLeft(), user 2026-11-25): the chain of the period (its covering contract / annex, else the
     * one it renews) — its latest issued / signed contract or annex is TERMINATED on the left date (ended_on, status terminated),
     * reason "Left", logged (contract_events: terminated + the reservation and the status before). Nothing when there is none.
     */
    public function terminateOnLeft(Reservation $reservation, Model $user): ?Contract
    {
        unset(self::$contracts[(int) $reservation->account_id]);
        $base = $this->coveringContract($reservation) ?? $this->previousContract($reservation);
        $latest = $base ? $this->chain(Contract::find($base->id))->filter(fn ($item) => in_array($item->status, ['issued', 'signed'], true))->last() : null;
        if (!$latest) {
            return null;
        }
        $day = $reservation->left_at ? $reservation->left_at->copy()->startOfDay() : now()->startOfDay();
        $from = $latest->status;
        $latest->forceFill(['status' => 'terminated', 'ended_on' => $day])->save();
        $this->event($latest, 'terminated', $user, $day, 'Left', ['reservation_id' => $reservation->id, 'from' => $from]);
        unset(self::$contracts[(int) $reservation->account_id]);
        return $latest;
    }

    // Undo left: the contract / annex its Mark left terminated goes back to its status before (and covers its whole term again), logged
    public function restoreOnUndoLeft(Reservation $reservation, Model $user): ?Contract
    {
        $event = ContractEvent::where('account_id', $reservation->account_id)->where('type', 'terminated')
            ->where('data->reservation_id', (int) $reservation->id)->latest('id')->first();
        $contract = $event ? Contract::where('account_id', $reservation->account_id)->find($event->contract_id) : null;
        if (!$contract || $contract->status !== 'terminated') {
            return null;
        }
        $contract->forceFill(['status' => $event->data['from'] ?? 'issued', 'ended_on' => null])->save();
        $this->event($contract, 'restored', $user, null, 'Undo left', ['reservation_id' => $reservation->id]);
        unset(self::$contracts[(int) $reservation->account_id]);
        return $contract;
    }

    /**
     * End / Cancel (Phase 3, 2026-11-25; the account owner only): an issued / signed contract or annex that no annex renews yet.
     * End = it stops on that date (status Ended; it covers the periods up to it; a renewal then needs a NEW contract); Cancel = void
     * (status Cancelled, cancelled_at = that date, cancel_reason; it covers nothing). Both logged in contract_events with the reason.
     */
    public function close(int $accountId, $id, string $action, string $date, string $reason, Model $user): Contract
    {
        return DB::transaction(function () use ($accountId, $id, $action, $date, $reason, $user) {
            $contract = $this->contractRepository->forAccount($accountId)->lockForUpdate()->findOrFail($id);
            if (!$this->can($contract, $action, $user)) {
                throw ValidationException::withMessages(['reason' => !self::isOwner($user) ? 'Only the account owner can end or cancel a contract.'
                    : (!in_array($contract->status, ['issued', 'signed'], true) ? 'Only an issued or signed contract can be ended or cancelled: this one is ' . strtolower($contract->status_label) . '.'
                        : 'A renewal annex renews this contract: end or cancel the latest annex instead.')]);
            }
            $day = \Illuminate\Support\Carbon::parse($date)->startOfDay();
            if ($action === 'end') {
                if ($day->lt($contract->starts_on) || $day->gt($contract->ends_on)) {
                    throw ValidationException::withMessages(['date' => 'The end date must be inside its term (' . $contract->starts_on->format(Reservation::DATE_FORMAT)
                        . ' → ' . $contract->ends_on->format(Reservation::DATE_FORMAT) . ').']);
                }
                $contract->ended_on = $day;
                $contract->status = 'ended';
            } else {
                $contract->cancelled_at = $day;
                $contract->cancel_reason = trim($reason);
                $contract->status = 'cancelled';
            }
            $contract->save();
            $this->event($contract, $action === 'end' ? 'ended' : 'cancelled', $user, $day, $reason, ['contract_no' => $contract->contract_no]);
            unset(self::$contracts[$accountId]);
            return $contract;
        });
    }

    // one line of the contract's history (contract_events)
    private function event(Contract $contract, string $type, ?Model $user, $date = null, ?string $reason = null, array $data = []): void
    {
        $event = new ContractEvent(['account_id' => $contract->account_id, 'contract_id' => $contract->id, 'type' => $type, 'event_date' => $date,
            'reason' => $reason !== null ? trim($reason) : null, 'data' => $data ?: null]);
        if ($user) {
            $event->creatable()->associate($user);
        }
        $event->save();
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
        $wasSigned = $contract->status === 'signed';
        $contract->status = 'signed';
        $contract->save();
        $this->event($contract, 'signed', $user, $contract->signed_on, null, ['replaced' => $wasSigned]);
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
            // once numbered (set back to draft): the owner only
            'delete' => $contract->isDraft() && ($owner || ($own && !$contract->contract_no)),
            'unissue' => $contract->status === 'issued' && $owner,
            // Phase 3: the owner cancels (a wrong contract) an issued / signed one, the latest of its chain only; ending = Mark left
            // (terminateOnLeft(), user 2026-11-25: no End button)
            'cancel' => $owner && in_array($contract->status, ['issued', 'signed'], true) && !$contract->renewals()->exists(),
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
            throw ValidationException::withMessages(['status' => $contract->isDraft() ? 'Only the owner or the user who made this draft can delete it.'
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
            $title = $this->printedTitle($contract, $lang, $this->isAnnex($contract, $lang));
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
            'annex' => $this->isAnnex($contract, $lang),
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
