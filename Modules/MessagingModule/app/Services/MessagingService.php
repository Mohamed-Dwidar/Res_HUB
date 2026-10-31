<?php

namespace Modules\MessagingModule\app\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\AccountModule\app\Models\Account;
use Modules\MemberModule\app\Models\Member;
use Modules\MessagingModule\app\Models\MessageLog;
use Modules\MessagingModule\app\Models\MessageSetting;
use Modules\MessagingModule\app\Models\MessageTemplate;
use Modules\MessagingModule\app\Repositories\MessageTemplateRepository;
use Modules\ReservationModule\app\Models\Reservation;
use Modules\SpaceModule\app\Models\Space;
use Modules\SpaceModule\app\Models\SubscriptionType;
use Modules\UnitModule\app\Models\Unit;

/**
 * WhatsApp messages (MessagingModule, 2026-10-29). Every method takes the owner account id (or a reservation of it).
 * Other modules only render the button partial (messagingmodule::Message.partials.button → button()) and ask popupUrl()
 * after a save; how a message is sent stays here: send() builds the wa.me link (driver click_to_chat) and logs it. The WhatsApp
 * API later = another branch of send() by settings()->driver; the templates, the log and the popup stay as they are.
 * Messages (2026-10-31): none until the owner saves one (config texts = "Start from" only); a message = a name + texts + its
 * units + an optional "Suggest when" (config suggest_when, NULL = Never); a unit has at most one message of each "Suggest when"
 * value (Never: any number); a reservation sees only its unit's switched on messages (no message = no icon).
 *
 * Suggested (the ★ row, the icon state, the popup after a save) by context = the Schedule group / the list's Renewal filter:
 * new → New booking, next / upcoming → Before renewal, today → Renewal day, owing → Payment due (renewal date still ahead: an
 * unpaid current period) or After renewal (renewal date passed), left → Marked left; elsewhere by the reservation's state
 * (suggest()). It counts only when the unit has a message with that "Suggest when".
 */
class MessagingService
{
    const CHANNEL = 'whatsapp';

    const CONTEXT_SUGGEST = ['new' => 'new_booking', 'next' => 'before_renewal', 'upcoming' => 'before_renewal',
        'today' => 'renewal_day', 'left' => 'marked_left'];

    const CONTEXTS = ['new', 'next', 'upcoming', 'today', 'owing', 'left'];

    // read once per request (the Schedule / the list ask them for every row)
    private static array $settings = [];
    private static array $templates = [];

    private $templateRepository;

    public function __construct(MessageTemplateRepository $templateRepository)
    {
        $this->templateRepository = $templateRepository;
    }

    // the "Suggest when" values: [key => ['label', 'hint']]
    public static function suggestOptions(): array
    {
        return config('messagingmodule.suggest_when');
    }

    public static function suggestLabel(?string $when): ?string
    {
        return $when ? (self::suggestOptions()[$when]['label'] ?? $when) : null;
    }

    // ---- settings

    // the account's settings, the defaults until the first save
    public function settings(int $accountId): MessageSetting
    {
        return self::$settings[$accountId] ??= MessageSetting::where('account_id', $accountId)->where('channel', self::CHANNEL)->first()
            ?? new MessageSetting(['account_id' => $accountId, 'channel' => self::CHANNEL, 'is_enabled' => true, 'driver' => 'click_to_chat',
                'default_language' => 'ar']);
    }

    public function saveSettings(int $accountId, array $data): MessageSetting
    {
        $settings = $this->settings($accountId);
        $settings->fill($data)->save();
        unset(self::$settings[$accountId]);
        return $settings;
    }

    // ---- templates (messages), assigned to units

    // the account's messages (by name), each with unit_ids = its units (2 queries, once per request)
    public function templates(int $accountId): Collection
    {
        if (!isset(self::$templates[$accountId])) {
            $units = DB::table('message_template_unit')->where('account_id', $accountId)->get(['message_template_id', 'unit_id'])
                ->groupBy('message_template_id');
            self::$templates[$accountId] = $this->templateRepository->forAccount($accountId)->get()
                ->each(fn ($template) => $template->unit_ids = $units->get($template->id, collect())->pluck('unit_id')->map(fn ($id) => (int) $id)->all())
                ->sortBy(fn ($template) => mb_strtolower($template->name))->values();
        }
        return self::$templates[$accountId];
    }

    // the switched on messages of a unit, by name (the popup, the icon, sending)
    public function unitTemplates(int $accountId, int $unitId): Collection
    {
        return $unitId ? $this->templates($accountId)->filter(fn ($template) => $template->is_enabled && in_array($unitId, $template->unit_ids, true))->values() : collect();
    }

    // the unit's switched on message with this "Suggest when", null = none
    public function templateFor(int $accountId, ?string $when, int $unitId): ?MessageTemplate
    {
        return $when ? $this->unitTemplates($accountId, $unitId)->firstWhere('suggest_when', $when) : null;
    }

    // 404 when the message belongs to another account
    public function findTemplate(int $accountId, $id): MessageTemplate
    {
        return $this->templateRepository->forAccount($accountId)->with(['units' => fn ($query) => $query->with('space')])->findOrFail($id);
    }

    public function createTemplate(int $accountId, array $data, Model $creator): MessageTemplate
    {
        return $this->saveTemplate($accountId, new MessageTemplate(['account_id' => $accountId, 'channel' => self::CHANNEL]), $data, $creator);
    }

    public function updateTemplate(int $accountId, $id, array $data): MessageTemplate
    {
        return $this->saveTemplate($accountId, $this->findTemplate($accountId, $id), $data);
    }

    // the message + its assignments
    public function deleteTemplate(int $accountId, $id): void
    {
        $template = $this->findTemplate($accountId, $id);
        DB::transaction(function () use ($template) {
            DB::table('message_template_unit')->where('message_template_id', $template->id)->delete();
            $template->delete();
        });
        unset(self::$templates[$accountId]);
    }

    /**
     * The template form: name, "Suggest when", texts, its units (the account's own, not deleted; a unit already having another
     * message with this "Suggest when" = 422 under units; Never = no limit). The account's messages are locked while checking, so two saves can't both give a unit the same "Suggest when".
     */
    private function saveTemplate(int $accountId, MessageTemplate $template, array $data, ?Model $creator = null): MessageTemplate
    {
        DB::transaction(function () use ($accountId, $template, $data, $creator) {
            MessageTemplate::where('account_id', $accountId)->lockForUpdate()->pluck('id');
            $unitIds = $this->accountUnitIds($accountId, $data['units'] ?? []);
            if ($error = $this->templateUnitsError($accountId, $data['suggest_when'] ?? null, $unitIds, $template->id)) {
                throw ValidationException::withMessages(['units' => $error]);
            }
            $template->fill([
                'name' => $data['name'],
                'suggest_when' => ($data['suggest_when'] ?? null) ?: null,
                'is_enabled' => (bool) ($data['is_enabled'] ?? false),
                'body_ar' => $data['body_ar'],
                'body_en' => $data['body_en'] ?? null,
            ]);
            if ($creator) {
                $template->creatable()->associate($creator);
            }
            $template->save();
            // the deleted units' rows stay (a restored unit keeps its messages)
            DB::table('message_template_unit')->where('message_template_id', $template->id)
                ->whereIn('unit_id', Unit::where('account_id', $accountId)->select('id'))->delete();
            DB::table('message_template_unit')->insert(array_map(fn ($unitId) => ['message_template_id' => $template->id, 'unit_id' => $unitId, 'account_id' => $accountId], $unitIds));
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
     * The template form's check: the units that already have another message with "Suggest when" = $when, null = none (or
     * Never). One line per unit: 'Office 3 (Offices) already has "VO renewal" for Before renewal.'
     */
    public function templateUnitsError(int $accountId, ?string $when, array $unitIds, ?int $exceptId = null): ?string
    {
        if (!$unitIds || !$when) {
            return null;
        }
        $taken = DB::table('message_template_unit')->join('message_templates', 'message_templates.id', '=', 'message_template_unit.message_template_id')
            ->where('message_templates.account_id', $accountId)->where('message_templates.channel', self::CHANNEL)->where('message_templates.suggest_when', $when)
            ->when($exceptId, fn ($query) => $query->where('message_templates.id', '!=', $exceptId))
            ->whereIn('message_template_unit.unit_id', $unitIds)->get(['message_template_unit.unit_id', 'message_templates.name'])->keyBy('unit_id');
        if ($taken->isEmpty()) {
            return null;
        }
        $units = Unit::whereIn('id', $taken->keys())->with('space')->get();
        return $units->map(fn ($unit) => $unit->name . ' (' . $unit->space?->name . ') already has "' . $taken[$unit->id]->name . '" for ' . self::suggestLabel($when) . '.')
            ->push('A unit can have one message for each "Suggest when": untick these units, choose another "Suggest when" (or Never), or change that message first.')->implode(' ');
    }

    /**
     * The unit form's check: the ticked messages of the account (unknown ids refused) with at most one per "Suggest when" (Never:
     * any number), null = fine. 'One message for Before renewal per unit: "A" and "B" are both ticked.'
     */
    public function unitTemplatesError(int $accountId, array $templateIds): ?string
    {
        $templateIds = array_values(array_unique(array_map('intval', $templateIds)));
        $chosen = $this->templateRepository->forAccount($accountId)->whereIn('id', $templateIds)->get();
        if ($chosen->count() !== count($templateIds)) {
            return 'A chosen message was not found, reload the page.';
        }
        $errors = $chosen->whereNotNull('suggest_when')->groupBy('suggest_when')->filter(fn ($group) => $group->count() > 1)
            ->map(fn ($group, $when) => 'One message for ' . self::suggestLabel($when) . ' per unit: "' . $group->pluck('name')->implode('" and "') . '" are ' . ($group->count() > 2 ? 'all' : 'both') . ' ticked.');
        return $errors->isEmpty() ? null : $errors->implode(' ');
    }

    /**
     * The unit form's save (UnitService create / update, inside its transaction): the unit's messages = $templateIds (the
     * account's messages locked while checking, 422 under message_templates).
     */
    public function syncUnitTemplates(int $accountId, int $unitId, array $templateIds): void
    {
        MessageTemplate::where('account_id', $accountId)->lockForUpdate()->pluck('id');
        if ($error = $this->unitTemplatesError($accountId, $templateIds)) {
            throw ValidationException::withMessages(['message_templates' => $error]);
        }
        DB::table('message_template_unit')->where('unit_id', $unitId)->where('account_id', $accountId)->delete();
        DB::table('message_template_unit')->insert(array_map(fn ($id) => ['message_template_id' => (int) $id, 'unit_id' => $unitId, 'account_id' => $accountId],
            array_values(array_unique($templateIds))));
        unset(self::$templates[$accountId]);
    }

    // the unit form's list: the account's messages (by name) + the ticked ids
    public function unitFormData(int $accountId, ?int $unitId): array
    {
        $templates = $this->templates($accountId);
        $selected = $unitId ? $templates->filter(fn ($template) => in_array($unitId, $template->unit_ids, true))->pluck('id')->all() : [];
        return ['templates' => $templates, 'selected' => $selected];
    }

    // the template form's units: the account's spaces (order) with their units (order), deleted ones left out
    public function spacesWithUnits(int $accountId): Collection
    {
        return Space::where('account_id', $accountId)->ordered()
            ->with(['units' => fn ($query) => $query->where('account_id', $accountId)->ordered()])->get()
            ->filter(fn ($space) => $space->units->isNotEmpty())->values();
    }

    // the template form's hints: {unit id: {suggest_when: "message name"}} of the other messages (custom.js marks the taken units)
    public function unitTypesTaken(int $accountId, ?int $exceptId = null): array
    {
        $taken = [];
        foreach ($this->templates($accountId) as $template) {
            if ($template->id === $exceptId) {
                continue;
            }
            if (!$template->suggest_when) {
                continue;
            }
            foreach ($template->unit_ids as $unitId) {
                $taken[$unitId][$template->suggest_when] = $template->name;
            }
        }
        return $taken;
    }

    // the {names} of a text that are not placeholders (the template form refuses them)
    public static function unknownPlaceholders(?string $text): array
    {
        preg_match_all('/\{(\w+)\}/u', (string) $text, $matches);
        return array_values(array_diff(array_unique($matches[1]), array_keys(config('messagingmodule.placeholders'))));
    }

    // ---- texts

    /**
     * The placeholder values for a reservation (config messagingmodule.placeholders). $date = the business date (renewal days);
     * $withOwing: {owing_total} reads the member's other owing periods (one query; the popup only).
     */
    public function variables(Reservation $reservation, ?Carbon $date = null, bool $withOwing = false): array
    {
        $accountId = (int) $reservation->account_id;
        $account = Account::settingsOf($accountId);
        $settings = $this->settings($accountId);
        $date = ($date ?? $account?->businessDate() ?? now())->copy()->startOfDay();
        $format = Reservation::DATE_FORMAT;
        $period = $reservation->isPeriod();
        $renewsOn = $period ? $reservation->renewalDate() : null;
        $renewal = $period ? $reservation->renewedBy : null;
        $timed = (bool) $reservation->plan?->is_time_based;
        $total = $reservation->dueAmount();
        $due = $reservation->canTakePayments() ? max(0.0, (float) $reservation->remaining) : 0.0;
        $owing = $due;
        if ($withOwing && $reservation->member_id) {
            $periods = Reservation::owingPeriodsOf($accountId, (int) $reservation->member_id);
            $owing = (float) $periods->sum('remaining') + ($periods->contains('id', $reservation->id) ? 0 : $due);
        }
        $name = trim((string) $reservation->member?->name);

        return [
            'member_name' => $name,
            'member_first_name' => Str::before($name, ' ') ?: $name,
            'unit' => (string) $reservation->unit?->name,
            'space' => (string) $reservation->space?->name,
            'plan' => (string) $reservation->plan?->name,
            'start_date' => (string) $reservation->start_at?->format($format),
            'end_date' => (string) $reservation->realEnd()?->format($format),
            'start_time' => $timed ? (string) $reservation->start_at?->format(Reservation::TIME_FORMAT) : '',
            'end_time' => $timed ? (string) $reservation->realEnd()?->format(Reservation::TIME_FORMAT) : '',
            'period' => $reservation->period,
            'renewal_date' => (string) $renewsOn?->format($format),
            'renewal_amount' => self::money($renewal ? $renewal->dueAmount() : $total),
            'amount' => self::money($total),
            'due' => self::money($due),
            'owing_total' => self::money($owing),
            'days_left' => $renewsOn && $renewsOn->gte($date) ? (string) (int) round($date->diffInDays($renewsOn, true)) : '0',
            'days_overdue' => $renewsOn && $renewsOn->lt($date) ? (string) (int) round($renewsOn->diffInDays($date, true)) : '0',
            'left_date' => (string) $reservation->left_at?->format($format),
            'reservation_no' => (string) $reservation->id,
            'account_name' => (string) $account?->name,
            'account_phone' => (string) $account?->phone,
            'employee_name' => (string) Auth::user()?->userable?->displayName(),
        ];
    }

    // {name} → its value; an unknown {name} stays as typed
    public static function render(?string $text, array $variables): string
    {
        return preg_replace_callback('/\{(\w+)\}/u', fn ($match) => array_key_exists($match[1], $variables) ? $variables[$match[1]] : $match[0], (string) $text);
    }

    // 1,650 / 1,650.5 (no ".00" in a message)
    public static function money(float $amount): string
    {
        return rtrim(rtrim(number_format($amount, 2), '0'), '.');
    }

    /**
     * The member's WhatsApp number: [international digits, null] or [null, the reason]. The first number of the phone (as
     * Member::phoneKey()); an Egyptian mobile → 20 + its 10 digits; a number typed with + or 00 → its digits (10–15); anything
     * else (a landline, 00000, too short) can't be used.
     */
    public function phone(?Member $member): array
    {
        $raw = trim((string) preg_split('/[\/,;|]/', (string) $member?->phone)[0]);
        if ($raw === '') {
            return [null, 'The member has no phone number.'];
        }
        $key = Member::phoneKey($raw);
        if (preg_match('/^1[0125]\d{8}$/', $key)) {
            return ['20' . $key, null];
        }
        $digits = preg_replace('/\D/', '', $raw);
        if (str_starts_with($raw, '+') || str_starts_with($digits, '00')) {
            $digits = preg_replace('/^00/', '', $digits);
            if (preg_match('/^[1-9]\d{9,14}$/', $digits)) {
                return [$digits, null];
            }
        }
        return [null, 'Not a WhatsApp number: ' . $member->phone . ' (an Egyptian mobile, or an international number starting with + or 00).'];
    }

    // ---- the suggested message, icon state

    /**
     * The "Suggest when" of the ★: by $context (the Schedule group / the list's Renewal filter) or by the state on $date (the
     * business date): left → Marked left; a cancelled like one → none; not a period type, or a new period starting on / after
     * the date → New booking; a period still owing → Payment due (renewal date ahead) / After renewal (passed); else by its
     * renewal date (passed and not renewed-and-paid → After renewal, the date → Renewal day, within the renewals window →
     * Before renewal); a renewed-and-paid or a running period far from its renewal → none.
     */
    public function suggest(Reservation $reservation, ?string $context, Carbon $date, int $renewalDays): ?string
    {
        if ($context === 'owing') {
            return $reservation->renewalDate()?->gt($date) ? 'payment_due' : 'after_renewal';
        }
        if (isset(self::CONTEXT_SUGGEST[$context])) {
            return self::CONTEXT_SUGGEST[$context];
        }
        if ($reservation->left_at) {
            return 'marked_left';
        }
        if (!$reservation->isCounted()) {
            return null;
        }
        if (!$reservation->isPeriod()) {
            return 'new_booking';
        }
        if ($reservation->renewal_of === null && $reservation->start_at?->copy()->startOfDay()->gte($date)) {
            return 'new_booking';
        }
        $renewsOn = $reservation->renewalDate();
        if (!$renewsOn) {
            return null;
        }
        if ($reservation->renewalDebt() > 0) {
            return $renewsOn->gt($date) ? 'payment_due' : 'after_renewal';
        }
        $renewal = $reservation->renewedBy;
        if ($renewal && (!$renewal->canTakePayments() || $renewal->remaining <= 0)) {
            return null;
        }
        return match (true) {
            $renewsOn->lt($date) => 'after_renewal',
            $renewsOn->eq($date) => 'renewal_day',
            $renewsOn->lte($date->copy()->addDays($renewalDays)) => 'before_renewal',
            default => null,
        };
    }

    /**
     * What the WhatsApp icon of a row / the view shows (messagingmodule::Message.partials.button): null = no icon (messages off,
     * or no switched on message assigned to the reservation's unit); ['error' => why it is disabled (the phone) | null,
     * 'label' = the suggested message's name (the unit's message with the suggested "Suggest when"), 'sent' = the last send of
     * ANY message for THIS reservation (not the other periods of its chain; user 2026-10-31) → the ✓, 'sentTitle' = "Last sent:
     * <name> · <date time> · <who>", 'url' = the popup]. Reads the eager loaded messageLogs (the Schedule / the list load them
     * for all rows) and the account's messages once per request: no query per row.
     */
    public function button(Reservation $reservation, ?string $context = null, ?Carbon $date = null): ?array
    {
        $accountId = (int) $reservation->account_id;
        if (!$this->settings($accountId)->is_enabled || $this->unitTemplates($accountId, (int) $reservation->unit_id)->isEmpty()) {
            return null;
        }
        [, $error] = $this->phone($reservation->member);
        $account = Account::settingsOf($accountId);
        $when = $this->suggest($reservation, $context, ($date ?? $account->businessDate())->copy()->startOfDay(), $account->renewalDays());
        $template = $this->templateFor($accountId, $when, (int) $reservation->unit_id);
        $last = $reservation->messageLogs->first();

        return [
            'error' => $error,
            'label' => $template?->name,
            'sent' => $last?->created_at,
            'sentTitle' => $last ? self::sentTitle($last) : null,
            'url' => route(self::area() . '.messages.show', ['reservation', $reservation->id] + ($context ? ['context' => $context] : [])),
        ];
    }

    // the icon's tooltip: "Last sent: <message name> · <date time> · <who>"
    public static function sentTitle(MessageLog $log): string
    {
        return 'Last sent: ' . $log->message_name . ' · ' . $log->created_at?->format(Reservation::DATE_FORMAT . ' ' . Reservation::TIME_FORMAT)
            . ' · ' . ($log->creatable?->userable?->displayName() ?? '-');
    }

    // the popup page after a save (new_booking: a new reservation, marked_left: Mark left), null when it can't be used (messages
    // off, no number, the unit has no switched on message with this "Suggest when")
    public function popupUrl(Reservation $reservation, string $when): ?string
    {
        $accountId = (int) $reservation->account_id;
        if (!$this->settings($accountId)->is_enabled || $this->phone($reservation->member)[1] || !$this->templateFor($accountId, $when, (int) $reservation->unit_id)) {
            return null;
        }
        return route(self::area() . '.messages.show', ['reservation', $reservation->id, 'suggest' => $when]);
    }

    // ---- the popup, sending

    // a reservation of the account with what the texts need (404 for another account)
    public function findReservation(int $accountId, $id): Reservation
    {
        return Reservation::where('account_id', $accountId)->with('member', 'unit', 'space', 'plan', 'subscriptionType', 'status', 'renewedBy')->findOrFail($id);
    }

    /**
     * The popup: one row per switched on message of the reservation's unit with its texts / wa.me links in both languages and
     * its last send (this reservation or its renewal); ★ = the message whose "Suggest when" matches ($suggest asked for after a
     * save, else suggest() by $context), first; the others by name. The history of the reservation + the period it renews + its
     * renewal; the language to open in.
     */
    public function popup(Reservation $reservation, ?string $context, ?string $suggest, ?string $language = null): array
    {
        $accountId = (int) $reservation->account_id;
        $account = Account::settingsOf($accountId);
        $settings = $this->settings($accountId);
        $date = $account->businessDate()->copy()->startOfDay();
        [$number, $error] = $this->phone($reservation->member);
        $when = isset(self::suggestOptions()[$suggest]) ? $suggest : $this->suggest($reservation, $context, $date, $account->renewalDays());
        $variables = $this->variables($reservation, $date, true);
        $history = $this->history($reservation);
        $ownIds = array_filter([$reservation->id, $reservation->renewedBy?->id]);

        $rows = [];
        foreach ($this->unitTemplates($accountId, (int) $reservation->unit_id) as $template) {
            $texts = ['ar' => self::render($template->body_ar, $variables), 'en' => $template->body_en ? self::render($template->body_en, $variables) : null];
            $rows[] = [
                'id' => $template->id,
                'name' => $template->name,
                'suggestLabel' => self::suggestLabel($template->suggest_when),
                'suggested' => $when !== null && $template->suggest_when === $when,
                'hasEnglish' => $texts['en'] !== null,
                'urls' => $number ? ['ar' => self::link($number, $texts['ar']), 'en' => self::link($number, $texts['en'] ?? $texts['ar'])] : null,
                'last' => $history->first(fn ($log) => (int) $log->message_template_id === $template->id && in_array((int) $log->messageable_id, $ownIds, true)),
            ];
        }
        usort($rows, fn ($a, $b) => $b['suggested'] <=> $a['suggested']);

        return [
            'reservation' => $reservation,
            'number' => $number,
            'error' => $error,
            'rows' => $rows,
            'suggest' => $when,
            'history' => $history,
            'language' => in_array($language, ['ar', 'en'], true) ? $language : ($reservation->member?->message_language ?: $settings->default_language),
            'context' => $context,
        ];
    }

    // every send for the reservation, the period it renews and its renewal: newest first, who sent it
    public function history(Reservation $reservation): Collection
    {
        $ids = array_values(array_filter([$reservation->id, $reservation->renewal_of, $reservation->renewedBy?->id]));
        return MessageLog::where('messageable_type', $reservation->getMorphClass())->whereIn('messageable_id', $ids)
            ->with('creatable.userable')->orderByDesc('id')->limit(100)->get();
    }

    /**
     * Send a message of the reservation's unit in $language (Arabic when it has no English text): the click-to-chat driver = the
     * wa.me link (opened by the employee's browser) + the log (the message id + its name now); the WhatsApp API later = a call
     * here, status / external_id in the log. 422 when the number can't be used or the message is not a switched on message of
     * the reservation's unit.
     */
    public function send(int $accountId, Reservation $reservation, int $templateId, string $language, Model $creator): array
    {
        [$number, $error] = $this->phone($reservation->member);
        $template = $this->unitTemplates($accountId, (int) $reservation->unit_id)->firstWhere('id', $templateId);
        if ($error || !$template) {
            throw ValidationException::withMessages(['message' => $error ?? 'This message is not switched on for this unit.']);
        }
        $language = $language === 'en' && $template->body_en ? 'en' : 'ar';
        $text = self::render($language === 'en' ? $template->body_en : $template->body_ar, $this->variables($reservation, null, true));
        $settings = $this->settings($accountId);

        $log = new MessageLog([
            'account_id' => $accountId,
            'channel' => self::CHANNEL,
            'driver' => $settings->driver ?: 'click_to_chat',
            'messageable_type' => $reservation->getMorphClass(),
            'messageable_id' => $reservation->id,
            'member_id' => (int) $reservation->member_id,
            'message_template_id' => $template->id,
            'message_name' => $template->name,
            'language' => $language,
            'phone' => $number,
        ]);
        $log->creatable()->associate($creator);
        $log->save();

        return ['url' => self::link($number, $text), 'log' => $log, 'template' => $template];
    }

    // the click-to-chat link
    public static function link(string $number, string $text): string
    {
        return 'https://wa.me/' . $number . '?text=' . rawurlencode($text);
    }

    // ---- the settings page

    // the live preview's values: the account's newest subscription period with a member (else its newest reservation)
    public function sampleVariables(int $accountId): array
    {
        $with = ['member', 'unit', 'space', 'plan', 'subscriptionType', 'renewedBy'];
        $reservation = Reservation::where('account_id', $accountId)->where('member_id', '>', 0)
            ->whereIn('subscription_type_id', SubscriptionType::idsOf('period') ?: [0])->with($with)->latest('id')->first()
            ?? Reservation::where('account_id', $accountId)->where('member_id', '>', 0)->with($with)->latest('id')->first();
        return $reservation ? $this->variables($reservation) : [];
    }

    // the logged in user's area (route names {area}.messages.*)
    private static function area(): string
    {
        return Auth::user()?->userable instanceof Account ? 'account' : 'employee';
    }
}
