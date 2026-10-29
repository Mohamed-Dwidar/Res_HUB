<?php

namespace Modules\MessagingModule\app\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\AccountModule\app\Models\Account;
use Modules\MemberModule\app\Models\Member;
use Modules\MessagingModule\app\Models\MessageLog;
use Modules\MessagingModule\app\Models\MessageSetting;
use Modules\MessagingModule\app\Models\MessageTemplate;
use Modules\MessagingModule\app\Repositories\MessageTemplateRepository;
use Modules\ReservationModule\app\Models\Reservation;
use Modules\SpaceModule\app\Models\SubscriptionType;

/**
 * WhatsApp messages (MessagingModule, 2026-10-29). Every method takes the owner account id (or a reservation of it).
 * Other modules only render the button partial (messagingmodule::Message.partials.button → button()) and ask popupUrl()
 * after a save; how a message is sent stays here: send() builds the wa.me link (driver click_to_chat) and logs it. The WhatsApp
 * API later = another branch of send() by settings()->driver; the templates, the log and the popup stay as they are.
 *
 * Suggested type (the ★ row, the icon state) by context = the Schedule group / the list's Renewal filter: new → Booking
 * confirmation, next / upcoming → Renewal reminder, today → Renewal day, owing → Payment reminder (renewal date still ahead:
 * an unpaid current period) or After-renewal (renewal date passed), left → Contract end; elsewhere by the reservation's state
 * (suggest()).
 */
class MessagingService
{
    const CHANNEL = 'whatsapp';

    const CONTEXT_TYPES = ['new' => 'booking_confirmation', 'next' => 'renewal_reminder', 'upcoming' => 'renewal_reminder',
        'today' => 'renewal_day', 'left' => 'contract_end'];

    const CONTEXTS = ['new', 'next', 'upcoming', 'today', 'owing', 'left'];

    // read once per request (the Schedule / the list ask them for every row)
    private static array $settings = [];
    private static array $templates = [];

    private $templateRepository;

    public function __construct(MessageTemplateRepository $templateRepository)
    {
        $this->templateRepository = $templateRepository;
    }

    public static function types(): array
    {
        return config('messagingmodule.types');
    }

    public static function typeLabel(?string $type): ?string
    {
        return $type ? (self::types()[$type]['label'] ?? $type) : null;
    }

    // ---- settings

    // the account's settings, the defaults until the first save
    public function settings(int $accountId): MessageSetting
    {
        return self::$settings[$accountId] ??= MessageSetting::where('account_id', $accountId)->where('channel', self::CHANNEL)->first()
            ?? new MessageSetting(['account_id' => $accountId, 'channel' => self::CHANNEL, 'is_enabled' => true, 'driver' => 'click_to_chat',
                'default_language' => 'ar', 'deposit_percent' => config('messagingmodule.deposit_percent'), 'payment_details' => null]);
    }

    public function saveSettings(int $accountId, array $data): MessageSetting
    {
        $settings = $this->settings($accountId);
        $settings->fill($data)->save();
        unset(self::$settings[$accountId]);
        return $settings;
    }

    // ---- templates

    // the account's saved texts (defaults + space versions), with their spaces
    public function templates(int $accountId): Collection
    {
        return self::$templates[$accountId] ??= $this->templateRepository->forAccount($accountId)->with('space')->get();
    }

    // the settings page: the 6 defaults saved once from config (missing types only), then edited by the owner
    public function ensureDefaults(int $accountId, Model $creator): void
    {
        $saved = $this->templates($accountId)->where('space_id', 0)->pluck('type')->all();
        foreach (self::types() as $type => $default) {
            if (!in_array($type, $saved, true)) {
                $this->newTemplate($accountId, ['type' => $type, 'space_id' => 0, 'is_enabled' => true, 'body_ar' => $default['ar'], 'body_en' => $default['en']], $creator);
            }
        }
        unset(self::$templates[$accountId]);
    }

    // [type => [default, space versions …]] in the type order, spaces by their order
    public function templateGroups(int $accountId): array
    {
        $templates = $this->templates($accountId);
        $groups = [];
        foreach (self::types() as $type => $default) {
            $groups[$type] = $templates->where('type', $type)
                ->sortBy(fn ($template) => [$template->space_id ? 1 : 0, (int) $template->space?->sort_order, (string) $template->space?->name])->values();
        }
        return $groups;
    }

    // the text of a type for a space: ['ar' => …, 'en' => … | null]; null = the type is off (the space version or the default)
    public function templateFor(int $accountId, string $type, int $spaceId): ?array
    {
        if (!isset(self::types()[$type])) {
            return null;
        }
        $templates = $this->templates($accountId);
        $template = ($spaceId ? $templates->first(fn ($row) => $row->type === $type && $row->space_id === $spaceId) : null)
            ?? $templates->first(fn ($row) => $row->type === $type && $row->space_id === 0);
        if ($template) {
            return $template->is_enabled ? ['ar' => $template->body_ar, 'en' => $template->body_en ?: null] : null;
        }
        $default = self::types()[$type];
        return ['ar' => $default['ar'], 'en' => $default['en'] ?: null];
    }

    // 404 when the template belongs to another account
    public function findTemplate(int $accountId, $id): MessageTemplate
    {
        return $this->templateRepository->forAccount($accountId)->with('space')->findOrFail($id);
    }

    // a space version (the defaults come from ensureDefaults())
    public function createTemplate(int $accountId, array $data, Model $creator): MessageTemplate
    {
        $template = $this->newTemplate($accountId, $data, $creator);
        unset(self::$templates[$accountId]);
        return $template;
    }

    // the type / space never change
    public function updateTemplate(int $accountId, $id, array $data): MessageTemplate
    {
        $template = $this->findTemplate($accountId, $id);
        $template->update(['is_enabled' => (bool) ($data['is_enabled'] ?? false), 'body_ar' => $data['body_ar'], 'body_en' => $data['body_en'] ?? null]);
        unset(self::$templates[$accountId]);
        return $template;
    }

    // space versions only (a default can be switched off, not deleted)
    public function deleteTemplate(int $accountId, $id): void
    {
        $template = $this->findTemplate($accountId, $id);
        if ($template->isDefault()) {
            throw ValidationException::withMessages(['template' => 'The default text of a type can\'t be deleted, switch it off instead.']);
        }
        $template->delete();
        unset(self::$templates[$accountId]);
    }

    private function newTemplate(int $accountId, array $data, Model $creator): MessageTemplate
    {
        $template = new MessageTemplate([
            'account_id' => $accountId,
            'channel' => self::CHANNEL,
            'type' => $data['type'],
            'space_id' => (int) ($data['space_id'] ?? 0),
            'is_enabled' => (bool) ($data['is_enabled'] ?? true),
            'body_ar' => $data['body_ar'],
            'body_en' => $data['body_en'] ?? null,
        ]);
        $template->creatable()->associate($creator);
        $template->save();
        return $template;
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
        $percent = (float) $settings->deposit_percent;

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
            'deposit_percent' => self::money($percent),
            'deposit_amount' => self::money(round($total * $percent / 100, 2)),
            'payment_details' => trim((string) $settings->payment_details),
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

    // ---- suggested type, icon state

    /**
     * The ★ type: by $context (the Schedule group / the list's Renewal filter) or by the state on $date (the business date):
     * left → Contract end; a cancelled like one → none; not a period type, or a new period starting on / after the date →
     * Booking confirmation; a period still owing → Payment reminder (renewal date ahead) / After-renewal (passed); else by
     * its renewal date (passed and not renewed-and-paid → After-renewal, the date → Renewal day, within the renewals window →
     * Renewal reminder); a renewed-and-paid or a running period far from its renewal → none.
     */
    public function suggest(Reservation $reservation, ?string $context, Carbon $date, int $renewalDays): ?string
    {
        if ($context === 'owing') {
            return $reservation->renewalDate()?->gt($date) ? 'payment_reminder' : 'after_renewal';
        }
        if (isset(self::CONTEXT_TYPES[$context])) {
            return self::CONTEXT_TYPES[$context];
        }
        if ($reservation->left_at) {
            return 'contract_end';
        }
        if (!$reservation->isCounted()) {
            return null;
        }
        if (!$reservation->isPeriod()) {
            return 'booking_confirmation';
        }
        if ($reservation->renewal_of === null && $reservation->start_at?->copy()->startOfDay()->gte($date)) {
            return 'booking_confirmation';
        }
        $renewsOn = $reservation->renewalDate();
        if (!$renewsOn) {
            return null;
        }
        if ($reservation->renewalDebt() > 0) {
            return $renewsOn->gt($date) ? 'payment_reminder' : 'after_renewal';
        }
        $renewal = $reservation->renewedBy;
        if ($renewal && (!$renewal->canTakePayments() || $renewal->remaining <= 0)) {
            return null;
        }
        return match (true) {
            $renewsOn->lt($date) => 'after_renewal',
            $renewsOn->eq($date) => 'renewal_day',
            $renewsOn->lte($date->copy()->addDays($renewalDays)) => 'renewal_reminder',
            default => null,
        };
    }

    /**
     * What the WhatsApp icon of a row / the view shows (messagingmodule::Message.partials.button): null = messages off;
     * ['error' => why it is disabled | null, 'type' / 'label' = the suggested type, 'sent' = when it was last sent for this
     * reservation or its renewal, 'url' = the popup]. Reads the eager loaded messageLogs (the Schedule / the list load them for
     * all rows: no query per row); the view loads them itself.
     */
    public function button(Reservation $reservation, ?string $context = null, ?Carbon $date = null): ?array
    {
        $accountId = (int) $reservation->account_id;
        if (!$this->settings($accountId)->is_enabled) {
            return null;
        }
        [, $error] = $this->phone($reservation->member);
        $account = Account::settingsOf($accountId);
        $type = $this->suggest($reservation, $context, ($date ?? $account->businessDate())->copy()->startOfDay(), $account->renewalDays());
        if ($type && !$this->templateFor($accountId, $type, (int) $reservation->space_id)) {
            $type = null;
        }

        return [
            'error' => $error,
            'type' => $type,
            'label' => self::typeLabel($type),
            'sent' => $type ? $this->lastSent($reservation, $type) : null,
            'url' => route(self::area() . '.messages.show', ['reservation', $reservation->id] + ($context ? ['context' => $context] : [])),
        ];
    }

    // when $type was last sent for this reservation or its renewal (the loaded logs; a renewal's only when they are loaded)
    private function lastSent(Reservation $reservation, string $type): ?Carbon
    {
        $logs = $reservation->messageLogs;
        if ($reservation->relationLoaded('renewedBy') && $reservation->renewedBy?->relationLoaded('messageLogs')) {
            $logs = $logs->concat($reservation->renewedBy->messageLogs);
        }
        return $logs->where('type', $type)->max('created_at');
    }

    // the popup page after a save (a new reservation, Mark left), null when it can't be used (messages off, no number, type off)
    public function popupUrl(Reservation $reservation, string $type): ?string
    {
        $accountId = (int) $reservation->account_id;
        if (!$this->settings($accountId)->is_enabled || $this->phone($reservation->member)[1] || !$this->templateFor($accountId, $type, (int) $reservation->space_id)) {
            return null;
        }
        return route(self::area() . '.messages.show', ['reservation', $reservation->id, 'type' => $type]);
    }

    // ---- the popup, sending

    // a reservation of the account with what the texts need (404 for another account)
    public function findReservation(int $accountId, $id): Reservation
    {
        return Reservation::where('account_id', $accountId)->with('member', 'unit', 'space', 'plan', 'subscriptionType', 'status', 'renewedBy')->findOrFail($id);
    }

    /**
     * The popup: one row per enabled type (★ the suggested first, then the type order) with its texts / wa.me links in both
     * languages and its last send; the history of the reservation + the period it renews + its renewal; the language to open in.
     * $type = a type asked for (after a save), else suggest() by $context.
     */
    public function popup(Reservation $reservation, ?string $context, ?string $type, ?string $language = null): array
    {
        $accountId = (int) $reservation->account_id;
        $account = Account::settingsOf($accountId);
        $settings = $this->settings($accountId);
        $date = $account->businessDate()->copy()->startOfDay();
        [$number, $error] = $this->phone($reservation->member);
        $suggested = isset(self::types()[$type]) ? $type : $this->suggest($reservation, $context, $date, $account->renewalDays());
        $variables = $this->variables($reservation, $date, true);
        $history = $this->history($reservation);
        $ownIds = array_filter([$reservation->id, $reservation->renewedBy?->id]);

        $rows = [];
        foreach (self::types() as $key => $definition) {
            $template = $this->templateFor($accountId, $key, (int) $reservation->space_id);
            if (!$template) {
                continue;
            }
            $texts = ['ar' => self::render($template['ar'], $variables), 'en' => $template['en'] ? self::render($template['en'], $variables) : null];
            $rows[] = [
                'type' => $key,
                'label' => $definition['label'],
                'hint' => $definition['hint'],
                'suggested' => $key === $suggested,
                'hasEnglish' => $texts['en'] !== null,
                'urls' => $number ? ['ar' => self::link($number, $texts['ar']), 'en' => self::link($number, $texts['en'] ?? $texts['ar'])] : null,
                'last' => $history->first(fn ($log) => $log->type === $key && in_array((int) $log->messageable_id, $ownIds, true)),
            ];
        }
        usort($rows, fn ($a, $b) => $b['suggested'] <=> $a['suggested']);

        return [
            'reservation' => $reservation,
            'number' => $number,
            'error' => $error,
            'rows' => $rows,
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
     * Send $type for the reservation in $language (Arabic when the type has no English text): the click-to-chat driver = the
     * wa.me link (opened by the employee's browser) + the log; the WhatsApp API later = a call here, status / external_id in the log.
     * 422 when the number can't be used or the type is off for this reservation's space.
     */
    public function send(int $accountId, Reservation $reservation, string $type, string $language, Model $creator): array
    {
        [$number, $error] = $this->phone($reservation->member);
        $template = $this->templateFor($accountId, $type, (int) $reservation->space_id);
        if ($error || !$template) {
            throw ValidationException::withMessages(['type' => $error ?? 'This message type is switched off.']);
        }
        $language = $language === 'en' && $template['en'] ? 'en' : 'ar';
        $text = self::render($template[$language], $this->variables($reservation, null, true));
        $settings = $this->settings($accountId);

        $log = new MessageLog([
            'account_id' => $accountId,
            'channel' => self::CHANNEL,
            'driver' => $settings->driver ?: 'click_to_chat',
            'messageable_type' => $reservation->getMorphClass(),
            'messageable_id' => $reservation->id,
            'member_id' => (int) $reservation->member_id,
            'type' => $type,
            'language' => $language,
            'phone' => $number,
        ]);
        $log->creatable()->associate($creator);
        $log->save();

        return ['url' => self::link($number, $text), 'log' => $log];
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
