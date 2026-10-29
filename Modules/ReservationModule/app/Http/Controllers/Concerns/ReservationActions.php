<?php

namespace Modules\ReservationModule\app\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Modules\CompanyModule\app\Services\CompanyService;
use Modules\MessagingModule\app\Services\MessagingService;
use Modules\PackageModule\app\Services\PackageService;
use Modules\PaymentModule\app\Services\PaymentMethodService;
use Modules\ReservationModule\app\Http\Requests\ReservationRequest;
use Modules\ReservationModule\app\Models\Reservation;
use Modules\ReservationModule\app\Services\ReservationService;
use Modules\ReservationModule\app\Services\ReservationStatusService;
use Modules\SpaceModule\app\Services\SpaceService;
use Modules\SpaceModule\app\Services\SubscriptionTypeService;
use Modules\UnitModule\app\Services\UnitService;
use Yajra\DataTables\Facades\DataTables;

/**
 * Reservations actions shared by the account and the employee controllers (both manage the account's reservations).
 * The controller gives area(): "account" | "employee" (route names {area}.reservations.*), the views read it from the route.
 * show / create / edit open in the popup (ajax), store / update / destroy answer JSON (custom.js ajax forms),
 * The form's place chain (type → space → unit → plan) comes with the form (ReservationService::placeTree(), custom.js
 * [data-place-tree]); availability() = the form's live check (custom.js reservationAvailability()).
 */
trait ReservationActions
{

    private ReservationService $reservationService;
    private ReservationStatusService $statusService;
    private SubscriptionTypeService $subscriptionTypeService;
    private SpaceService $spaceService;
    private PackageService $packageService;
    private CompanyService $companyService;

    abstract protected function area(): string;

    public function __construct(
        ReservationService $reservationService, ReservationStatusService $statusService, SubscriptionTypeService $subscriptionTypeService,
        SpaceService $spaceService, PackageService $packageService, CompanyService $companyService
    ) {
        $this->reservationService = $reservationService;
        $this->statusService = $statusService;
        $this->subscriptionTypeService = $subscriptionTypeService;
        $this->spaceService = $spaceService;
        $this->packageService = $packageService;
        $this->companyService = $companyService;
    }

    // the account itself, or the account of the logged in employee
    private function accountId()
    {
        return Auth::user()->userable->ownerAccountId();
    }

    private function route($name, $parameters = [])
    {
        return route($this->area() . '.reservations.' . $name, $parameters);
    }

    // the drop menus of the form; the place chain = placeTree (all of it, filtered in the browser); on edit / pre-filled the
    // saved choices' lists are rendered from it already (only the active spaces / units / plans: an inactive saved one must be changed)
    private function formOptions($reservation = null): array
    {
        $accountId = $this->accountId();
        // the Renew form: member / package / type are fixed hidden inputs, their drop menus are not shown
        $renewing = $reservation && !$reservation->exists && $reservation->renewal_of;
        $options = [
            // with can repeat: the form shows the repeat options only for these types
            'subscriptionTypes' => $renewing ? [] : $this->subscriptionTypeService->reservationOptions($accountId),
            'packages' => $renewing ? [] : $this->packageService->options($accountId, $reservation?->package_id),
            // the Member field searches the server (member-select); its quick add offers the account's companies
            'companies' => $renewing ? [] : $this->companyService->options($accountId),
            'placeTree' => $tree = $this->reservationService->placeTree($accountId),
            'spaces' => [], 'units' => [], 'plans' => [],
        ];

        if ($reservation) {
            $options['spaces'] = collect($tree['spaces'][$reservation->subscription_type_id] ?? [])->pluck('name', 'id')->all();
            $options['units'] = $tree['units'][$reservation->subscription_type_id . '-' . $reservation->space_id] ?? [];
            $options['plans'] = $reservation->unit_id ? ($tree['plans'][$reservation->unit_id] ?? []) : [];
        }

        return $options;
    }

    // the list page, the rows come from data() (DataTables)
    public function index()
    {
        $accountId = $this->accountId();
        $activeOnly = $this->area() === 'employee'; // employees: only the active statuses / spaces
        // ?member_id= (the Members list count): that member's reservations, the All tab
        $member = request()->filled('member_id')
            ? \Modules\MemberModule\app\Models\Member::where('account_id', $accountId)->find((int) request('member_id')) : null;
        return view('reservationmodule::Reservation.index', [
            'member' => $member,
            'statuses' => $this->statusService->filterOptions($accountId, $activeOnly),
            'spaces' => $this->spaceService->filterOptions($accountId, $activeOnly),
            // the Unit filter: the chosen space's units (and type's), custom.js select[data-filter-parents]
            'units' => app(UnitService::class)->filterOptions($accountId, $activeOnly),
            // the type filter (Day Use / Meeting / Subscriptions ...): a contract type shows its rows on every tab
            'types' => $this->subscriptionTypeService->options($accountId),
            'counts' => $this->tabCounts(['member_id' => $member?->id]),
        ]);
    }

    // the list tab: today (default, also '') | upcoming | all (docs/reservations-list-plan.md)
    // days picked in the date field (date_from / date_to) = 'days': the Today rule (running, no contracts) for those days
    private function tab(array $filters): string
    {
        if (!empty($filters['date_from']) && strtotime($filters['date_from'])) {
            // the business date alone (the Today tab's date) = the Today tab (its night after midnight included)
            $today = Reservation::businessToday()[0]->toDateString();
            return $filters['date_from'] === $today && ($filters['date_to'] ?? null ?: $today) === $today ? 'today' : 'days';
        }
        $tab = (string) ($filters['tab'] ?? '');
        return isset(Reservation::TABS[$tab]) ? $tab : 'today';
    }

    // the counted reservations of the Today tab with the other filters (not the tab / dates)
    private function tabCounts(array $filters): array
    {
        $filters = ['search' => trim((string) ($filters['search'] ?? ''))]
            + Arr::only($filters, ['member_id', 'reservation_status_id', 'space_id', 'unit_id', 'subscription_type_id', 'payment_status']);

        return ['reservations-today' => $this->reservationService->listQuery($this->accountId(), $filters)->counted()->tab('today', $filters)->count()];
    }

    // DataTables server side: tab + search (member) + member (?member_id=) / status / space / type / payment / dates filters
    public function data(Request $request)
    {
        return $this->table(['search' => $request->input('search.value')] + $request->all())->toJson();
    }

    // the list columns, for data() and for one row after a change (row())
    /**
     * The "Renewal" filter (2026-10-23; the Schedule lists, 2026-10-29): [status, from, to, asOf] when chosen
     * (Reservation::RENEWAL_FILTERS), null = All. The picked days of the date field (the Today tab = the business date) = the
     * "due since" days (not_paid) / the renewal dates (the others); else not_paid = everything up to the business date, upcoming =
     * the next N days (Settings › General renewals window), renewed / left = this month. asOf = the range's last day, the business
     * date at most (not_paid is seen on it; the texts count the days from it). custom.js moves to the All tab when it is chosen.
     */
    private function renewalFilter(array $filters): ?array
    {
        $status = (string) ($filters['renewal'] ?? '');
        if (!isset(Reservation::RENEWAL_FILTERS[$status])) {
            return null;
        }
        $today = Reservation::businessToday()[0]->copy();
        if (!empty($filters['date_from']) && strtotime($filters['date_from'])) {
            [$from, $to] = Reservation::pickedDays($filters);
        } elseif ($this->tab($filters) === 'today') {
            [$from, $to] = [$today->copy(), $today->copy()->addDay()];
        } elseif ($status === 'not_paid') {
            [$from, $to] = [Carbon::create(2000, 1, 1), $today->copy()->addDay()];
        } elseif ($status === 'upcoming') {
            $days = \Modules\AccountModule\app\Models\Account::settingsOf($this->accountId())?->renewalDays() ?? 7;
            [$from, $to] = [$today->copy()->addDay(), $today->copy()->addDays($days + 1)];
        } else {
            $from = $today->copy()->startOfMonth();
            $to = $from->copy()->addMonth();
        }
        $last = $to->copy()->subDay();

        return [$status, $from, $to, $last->lt($today) ? $last : $today];
    }

    // the line under the filters with the "Renewal" filter: the dates, the count and what is owed (json html → [data-html])
    private function renewalSummary(array $filters, array $renewal): string
    {
        [$status, $from, $to] = $renewal;
        $notCounted = implode(',', array_map('intval', \Modules\ReservationModule\app\Models\ReservationStatus::notCountedIds()) ?: [0]);
        $renewalOf = 'FROM reservations next_period WHERE next_period.renewal_of = reservations.id AND next_period.deleted_at IS NULL'
            . ' AND next_period.reservation_status_id NOT IN (' . $notCounted . ')';
        $renewalOwes = 'EXISTS (SELECT 1 ' . $renewalOf . ' AND next_period.package_id = 0 AND next_period.remaining > 0)';
        // one query: the count; not_paid: the debt + the paid ones not renewed; upcoming: not renewed yet (+ their price),
        // renewed and owing (+ the debt), renewed & paid
        $totals = $this->reservationService->listQuery($this->accountId(), $this->listFilters($filters))
            ->renewalStatus(...$renewal)->toBase()
            ->selectRaw('COUNT(*) AS periods')
            ->selectRaw('COALESCE(SUM(CASE WHEN ' . Reservation::OWES_SQL . ' THEN reservations.remaining ELSE 0 END), 0) AS own_due')
            ->selectRaw('COALESCE(SUM(CASE WHEN NOT ' . Reservation::OWES_SQL . ' THEN 1 ELSE 0 END), 0) AS paid_not_renewed')
            ->selectRaw('COALESCE(SUM(CASE WHEN NOT EXISTS (SELECT 1 ' . $renewalOf . ') THEN 1 ELSE 0 END), 0) AS not_created')
            ->selectRaw('COALESCE(SUM(CASE WHEN NOT EXISTS (SELECT 1 ' . $renewalOf . ') THEN ' . Reservation::DUE_SQL . ' ELSE 0 END), 0) AS not_created_amount')
            ->selectRaw('COALESCE(SUM(CASE WHEN ' . $renewalOwes . ' THEN 1 ELSE 0 END), 0) AS renewed_owing')
            ->selectRaw('COALESCE(SUM((SELECT COALESCE(SUM(next_period.remaining), 0) ' . $renewalOf . ' AND next_period.package_id = 0 AND next_period.remaining > 0)), 0) AS renewal_due')
            ->first();

        return view('reservationmodule::Reservation.partials.renewal-summary', [
            'status' => $status, 'from' => $from, 'to' => $to->copy()->subDay(), 'totals' => $totals,
        ])->render();
    }

    // the list filters given to Reservation::scopeFilter() (not the tab / dates / renewal)
    private function listFilters(array $filters): array
    {
        return ['search' => trim((string) ($filters['search'] ?? ''))]
            + Arr::only($filters, ['member_id', 'reservation_status_id', 'space_id', 'unit_id', 'subscription_type_id', 'payment_status']);
    }

    private function table(array $filters, $id = null)
    {
        $tab = $this->tab($filters);
        $renewal = $this->renewalFilter($filters);
        // by period only: the not counted ones (cancelled, pending ...) stay in their date place, muted (user 2026-10-27; they were
        // always after the counted ones) + who added it (the "By" column)
        // + the WhatsApp icon state (MessagingModule's messageLogs: one query for the page, the renewal's too)
        $query = $this->reservationService->listQuery($this->accountId())->with('creatable.userable', 'renewedBy', 'messageLogs', 'renewedBy.messageLogs');
        // the Renewal filter (2026-10-29): Upcoming / Renewed rows show the renewal when it exists (member, place, period, amounts,
        // status, menu); Not renewed & Not paid / Left = the period itself; the Renewal column = RenewalView (the Schedule rows)
        $shown = fn ($reservation) => in_array($renewal[0] ?? null, ['upcoming', 'renewed'], true) ? ($reservation->renewedBy ?? $reservation) : $reservation;
        $renewalKind = ['not_paid' => 'owing', 'upcoming' => 'upcoming', 'renewed' => 'renewed', 'left' => 'left'][$renewal[0] ?? ''] ?? null;
        if ($renewal) {
            $query->with('renewedBy.member', 'renewedBy.plan', 'renewedBy.unit.color', 'renewedBy.space', 'renewedBy.status',
                'renewedBy.subscriptionType', 'renewedBy.package', 'renewedBy.creatable.userable');
        }
        if ($id) {
            $query->whereKey($id);
        } elseif (!request()->filled('order')) {
            // the tab's order until a column is clicked (the list has data-order='[]'); the Renewal filter: by renewal date —
            // Not renewed & Not paid: a member's rows together (by name), oldest first
            match (true) {
                ($renewal[0] ?? null) === 'not_paid' => $query->orderByRaw('(SELECT members.name FROM members WHERE members.id = reservations.member_id)')
                    ->orderBy('reservations.member_id')->orderBy('reservations.start_at')->orderBy('reservations.id'),
                (bool) $renewal => $query->orderByRaw(Reservation::RENEWAL_DATE_SQL)->orderBy('reservations.id'),
                default => $query->tabOrder($tab, Arr::only($filters, ['date_from', 'date_to'])),
            };
        }

        $table = DataTables::eloquent($query)
            // inside filter() so "of N total" counts all the reservations
            // the Renewal filter replaces the tab (its dates = renewal dates)
            ->filter(fn ($query) => $renewal ? $query->filter($this->listFilters($filters))->renewalStatus(...$renewal)
                : $query->filter($this->listFilters($filters))->tab($tab, Arr::only($filters, ['subscription_type_id', 'date_from', 'date_to'])))
            ->setRowId(fn ($reservation) => 'row-' . $reservation->id)
            // not counted (cancelled, pending ...): muted (custom.css)
            ->setRowClass(fn ($reservation) => $reservation->isCounted() ? '' : 'row-muted')
            // the columns (docs/reservations-list-plan.md, 2026-10-13): Member | Space › Unit | Plan | Period | Amount | Paid | Remaining | Created By | Status
            ->addColumn('member_html', fn ($reservation) => view('reservationmodule::Reservation.partials.row-member', ['reservation' => $shown($reservation)])->render())
            // (the unit color) space / › unit (two lines)
            ->addColumn('place', fn ($reservation) => view('reservationmodule::Reservation.partials.row-place', ['reservation' => $shown($reservation), 'stacked' => true])->render())
            ->addColumn('plan', fn ($reservation) => e($shown($reservation)->plan?->name ?? '-'))
            // by the subscription type: full day = one date, auto renew / contract = From + Ends, else date | time → (date |) time
            // + on Today / Upcoming / picked days a period row's badge: New / Renewal (its start) or Renewal due (its renewal date)
            ->addColumn('period', fn ($reservation) => view('reservationmodule::Reservation.partials.row-period', ['reservation' => $shown($reservation),
                'badge' => $shown($reservation)->listBadge($tab, Arr::only($filters, ['date_from', 'date_to']))])->render())
            // the Renewal filter: renewal date + what happened + Renew / Pay / Mark left (the Schedule page texts); empty otherwise
            ->addColumn('renewal_html', fn ($reservation) => $renewal ? view('reservationmodule::Reservation.partials.row-renewal', ['reservation' => $reservation,
                'kind' => $renewalKind, 'asOf' => $renewal[3], 'area' => $this->area()])->render() : '')
            // the reservation status + the payment status (two badges)
            ->addColumn('status', fn ($reservation) => view('reservationmodule::Reservation.partials.row-status', ['reservation' => $shown($reservation)])->render())
            // what it costs (with its taxes when an invoice is requested) + the Invoice badge
            ->editColumn('net_amount', fn ($reservation) => view('reservationmodule::Reservation.partials.row-amount', ['reservation' => $shown($reservation)])->render())
            ->editColumn('paid_amount', fn ($reservation) => number_format($shown($reservation)->paid_amount, 2))
            // red when owed, a credit (negative) in blue
            ->addColumn('remaining_html', fn ($reservation) => view('reservationmodule::Reservation.partials.row-remaining', ['reservation' => $shown($reservation)])->render())
            ->addColumn('by', fn ($reservation) => e($shown($reservation)->creatable?->userable?->displayName() ?? '-'))
            // + WhatsApp in the menu (MessagingModule): about the period itself, the Renewal filter = the message context
            ->addColumn('actions', fn ($reservation) => view('reservationmodule::Reservation.partials.row-actions', ['reservation' => $shown($reservation),
                'messageFor' => $reservation, 'messageContext' => ['owing' => 'owing', 'upcoming' => 'upcoming', 'left' => 'left'][$renewalKind] ?? null])->render())
            ->rawColumns(['member_html', 'place', 'period', 'renewal_html', 'status', 'net_amount', 'remaining_html', 'actions'])
            ->only(['DT_RowId', 'DT_RowClass', 'member_html', 'place', 'plan', 'period', 'renewal_html', 'status', 'net_amount', 'paid_amount', 'remaining_html', 'by', 'actions']);

        // the Today tab count (datatables.js → [data-counter]); not for one row
        // + the Renewal filter's line (empty without it)
        return $id ? $table : $table->with('counts', $this->tabCounts($filters))
            ->with('html', ['reservations-renewal' => $renewal ? $this->renewalSummary($filters, $renewal) : '']);
    }

    // the updated row with the list filters (list[...]), null when it does not match them any more
    private function row(Request $request, $id)
    {
        return $this->table((array) $request->input('list', []), $id)->toArray()['data'][0] ?? null;
    }

    // the form's live availability line: ?unit_id, plan_id, start_at, end_at, start_time, end_time, ignore (edit: itself) —
    // {state: free | busy | shared, ...ReservationService::availability()} or {state: null} when not enough is chosen yet
    // the double-tax guard of the form (custom.js reservationTax(), "Invoice requested" ticked): the member's VAT (manual) cash
    // ins in the dates ± 7 days
    public function manualVat(Request $request)
    {
        $valid = fn ($date) => is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
        if (!$request->integer('member_id') || !$valid($request->input('start_at')) || !$valid($request->input('end_at'))) {
            return response()->json(['payments' => []]);
        }

        return response()->json(['payments' => $this->reservationService->manualVatPayments($this->accountId(), $request->integer('member_id'),
            \Illuminate\Support\Carbon::parse($request->input('start_at')), \Illuminate\Support\Carbon::parse($request->input('end_at')))]);
    }

    public function availability(Request $request)
    {
        $data = $request->only(['unit_id', 'plan_id', 'start_at', 'end_at', 'start_time', 'end_time']);
        $valid = fn ($date) => is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
        $time = fn ($value) => !isset($value) || $value === '' || (is_string($value) && preg_match('/^\d{2}:\d{2}$/', $value));
        if (!$valid($data['start_at'] ?? null) || !$valid($data['end_at'] ?? null) || !$time($data['start_time'] ?? null) || !$time($data['end_time'] ?? null)) {
            return response()->json(['state' => null]);
        }
        $result = $this->reservationService->availability($this->accountId(), $data, $request->integer('ignore') ?: null);
        if (!$result) {
            return response()->json(['state' => null]);
        }
        $state = $result['used'] === 0 ? 'free' : ($result['limit'] > 1 && !$result['full'] ? 'shared' : 'busy');

        return response()->json(['state' => $state] + $result);
    }

    // ?renew=ID (Renew: the Schedule page, the view, the list): the form pre-filled from that period, starting the day after it ends
    // (renewal_of = it); else ?subscription_type_id / space_id / unit_id / start (the Schedule page: + New, a timeline slot)
    public function create(Request $request)
    {
        $reservation = $request->filled('renew')
            ? $this->reservationService->renewalOf($this->reservationService->findOne($this->accountId(), (int) $request->input('renew')))
            : $this->reservationService->prefill($this->accountId(), $request->only(['subscription_type_id', 'space_id', 'unit_id', 'start']));

        return view('reservationmodule::Reservation.create', ['reservation' => $reservation] + $this->formOptions($reservation));
    }

    public function store(ReservationRequest $request)
    {
        $reservation = $this->reservationService->create($this->accountId(), $request->validated(), Auth::user());
        $repeats = $reservation->repeats()->count();

        return response()->json([
            'message' => $repeats ? 'The reservation has been added with ' . $repeats . ' repeated reservation(s).' : 'The reservation has been added successfully.',
            'reload' => true,
            'redirect' => $this->route('show', $reservation->id),
            // the popup goes on to the WhatsApp message (Booking confirmation; MessagingModule, null = no usable number / type off)
            'modal' => $reservation->renewal_of ? null : app(MessagingService::class)->popupUrl($reservation, 'booking_confirmation'),
        ]);
    }

    public function show($id)
    {
        $reservation = $this->reservationService->findOne($this->accountId(), $id);
        // the status menu (the header of the view)
        $statuses = $this->statusService->menuOptions($this->accountId(), $reservation->reservation_status_id);
        // the Add Cash In fields of the view (Pay / "+ Cash In"): the active payment methods
        $methods = $reservation->canTakePayments() ? app(PaymentMethodService::class)->options($this->accountId()) : [];
        return view('reservationmodule::Reservation.show', compact('reservation', 'statuses', 'methods'));
    }

    // the status menu of the view (ajax): the new status badge + the list row
    public function changeStatus(Request $request, $id)
    {
        $request->validate([
            'reservation_status_id' => ['required', 'integer', \Illuminate\Validation\Rule::exists('reservation_statuses', 'id')
                ->where('account_id', $this->accountId())->where('is_active', true)->whereNull('deleted_at')],
            // cancelling a reservation with payments: the owner's reason (the service refuses employees)
            'force_reason' => ['nullable', 'string', 'max:255'],
        ], ['reservation_status_id.exists' => 'This status is not available, choose another one.']);

        $reservation = $this->reservationService->changeStatus($this->accountId(), $id, (int) $request->input('reservation_status_id'),
            $request->input('force_reason'), Auth::user());

        return response()->json([
            'message' => 'The status has been changed to "' . $reservation->status?->name . '".' . $this->linkedMessage($reservation),
            'status' => ['id' => $reservation->reservation_status_id, 'name' => $reservation->status?->name, 'class' => $reservation->status?->badge_class],
            'row' => $this->row($request, $id),
        ]);
    }

    // " Linked as renewal of #…" when changeStatus() linked it (a period confirmed / restored without renewal_of)
    private function linkedMessage($reservation): string
    {
        $link = $reservation->getRelationValue('linkedRenewal');
        return $link ? ' Linked as renewal of #' . $link->id . '.' : '';
    }

    // the Restore popup (a not counted reservation back to the default counted status): the slot is checked first
    public function restoreForm($id)
    {
        $reservation = $this->reservationService->findOne($this->accountId(), $id);

        return view('reservationmodule::Reservation.restore', [
            'reservation' => $reservation,
            'status' => $this->reservationService->restoreStatus($this->accountId()),
            'error' => $reservation->isCounted() ? null : $this->reservationService->restoreError($reservation),
        ]);
    }

    public function restore(Request $request, $id)
    {
        $reservation = $this->reservationService->restore($this->accountId(), $id);

        return response()->json([
            'message' => 'The reservation has been restored to "' . $reservation->status?->name . '", its amount is due again.'
                . $this->linkedMessage($reservation),
            'row' => $this->row($request, $id),
            'modal' => $request->boolean('back_to_view') ? route($this->area() . '.reservations.show', $id) : null,
        ]);
    }

    // Mark left / undo (the Schedule page's Not renewed list, the view): the list row, back to the view when asked
    public function markLeft(Request $request, $id)
    {
        $this->reservationService->markLeft($this->accountId(), $id, Auth::user());
        // then the WhatsApp message (Contract end; MessagingModule, null = no usable number / type off)
        $whatsapp = app(MessagingService::class)->popupUrl($this->reservationService->findOne($this->accountId(), $id), 'contract_end');

        return response()->json([
            'message' => 'The period has been marked as left.',
            'row' => $this->row($request, $id),
            'modal' => $whatsapp ?? ($request->boolean('back_to_view') ? route($this->area() . '.reservations.show', $id) : null),
            'open_modal' => $whatsapp,
        ]);
    }

    public function undoLeft(Request $request, $id)
    {
        $this->reservationService->undoLeft($this->accountId(), $id);

        return response()->json([
            'message' => 'The period is no longer marked as left.',
            'row' => $this->row($request, $id),
            'modal' => $request->boolean('back_to_view') ? route($this->area() . '.reservations.show', $id) : null,
        ]);
    }

    public function edit($id)
    {
        $reservation = $this->reservationService->findOne($this->accountId(), $id);
        return view('reservationmodule::Reservation.edit', compact('reservation') + $this->formOptions($reservation));
    }

    public function update(ReservationRequest $request, $id)
    {
        $this->reservationService->update($this->accountId(), $id, $request->validated(), Auth::user());

        return response()->json([
            'message' => 'The reservation has been updated successfully.',
            'row' => $this->row($request, $id),
            'redirect' => $this->route('show', $id),
        ]);
    }

    public function destroy($id)
    {
        if ($error = $this->reservationService->deleteOne($this->accountId(), $id)) {
            return response()->json(['message' => $error], 422);
        }

        return response()->json([
            'message' => 'The reservation has been deleted successfully.',
            'row' => null,
            'redirect' => $this->route('index'),
        ]);
    }
}
