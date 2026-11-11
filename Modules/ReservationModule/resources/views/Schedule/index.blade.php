@extends(Auth::user()->userable->layout())

{{--
    The Schedule page (was Today until 2026-10-16; ScheduleActions / ScheduleService, the same for the account and its employees): one section per subscription type
    with Show on Home (home_order), laid out by its booking mode — nothing named in code. ?date=Y-m-d, default = the business
    date (Settings › General: at night, before the cutoff, still the day before). ‹ › = the day before / after, the date field = any day.
    The sections are reloaded by custom.js todayPage after every save in a popup (route {area}.schedule.sections).
--}}
@section('title')
    {{-- page heading + breadcrumb (main: the browser tab title without the badge) --}}
    Schedule · {{ $date->format('D d-m-Y') }} @if ($isToday)<span class="badge badge-active schedule-today-badge">Today</span>@endif
@endsection

@section('actions')
    <div class="today-nav">
        <a href="{{ route($area . '.schedule.index', ['date' => $date->copy()->subDay()->toDateString()]) }}" class="btn btn-secondary" title="The day before"><i data-lucide="chevron-left"></i></a>
        {{-- any day: flatpickr (local copy) → the page of that day (custom.js todayPage) --}}
        <input type="text" class="{{ config('layoutmodule.form.input') }} today-nav__date" value="{{ $date->format('D d-m-Y') }}" data-today-date data-url="{{ route($area . '.schedule.index') }}" data-date="{{ $date->toDateString() }}" aria-label="Pick a day" autocomplete="off" readonly>
        <a href="{{ route($area . '.schedule.index') }}" class="btn {{ $isToday ? 'btn-primary' : 'btn-secondary' }}">Today</a>
        <a href="{{ route($area . '.schedule.index', ['date' => $date->copy()->addDay()->toDateString()]) }}" class="btn btn-secondary" title="The day after"><i data-lucide="chevron-right"></i></a>
    </div>
    {{-- + New: pick the type first (the form opens with it, for the selected day); + Cash In / Cash Out popups (saved → the Payments list) --}}
    @php($newReservation = $newTypes->isNotEmpty() && Gate::allows('reservations.create'))
    @if ($newReservation || Gate::allows('payments.create') || Gate::allows('expenses.create'))
        <div data-tw-merge="" data-tw-placement="bottom-end" class="dropdown relative">
            <button type="button" data-tw-toggle="dropdown" aria-expanded="false" class="btn btn-primary"><i data-lucide="plus"></i> New <i data-lucide="chevron-down"></i></button>
            <div class="dropdown-menu js-menu absolute z-[9999]">
                <div data-tw-merge="" class="dropdown-content rounded-md border-transparent bg-white p-2 shadow-[0px_3px_10px_#00000017] dark:border-transparent dark:bg-darkmode-600 w-52">
                    @if ($newReservation)
                        @foreach ($newTypes as $new)
                            <a href="{{ route($area . '.reservations.create', ['subscription_type_id' => $new['type']->id, 'start' => $new['start']]) }}" data-modal data-tw-dismiss="dropdown" class="{{ config('layoutmodule.menu.item') }}">
                                <i data-lucide="calendar-plus" class="{{ config('layoutmodule.menu.icon') }}"></i> {{ $new['type']->name }}
                            </a>
                        @endforeach
                    @endif
                    @if ($newReservation && (Gate::allows('payments.create') || Gate::allows('expenses.create')))
                        <div class="h-px my-2 -mx-2 bg-slate-200/60"></div>
                    @endif
                    @can('payments.create')
                        <a href="{{ route($area . '.payments.create', ['back_to_list' => 1]) }}" data-modal data-tw-dismiss="dropdown" class="{{ config('layoutmodule.menu.item') }}">
                            <i data-lucide="arrow-down-circle" class="{{ config('layoutmodule.menu.icon') }}"></i> Cash In
                        </a>
                    @endcan
                    @can('expenses.create')
                        <a href="{{ route($area . '.expenses.create', ['back_to_list' => 1]) }}" data-modal data-tw-dismiss="dropdown" class="{{ config('layoutmodule.menu.item') }}">
                            <i data-lucide="arrow-up-circle" class="{{ config('layoutmodule.menu.icon') }}"></i> Cash Out
                        </a>
                    @endcan
                </div>
            </div>
        </div>
    @endif
@endsection

{{-- flatpickr (the date field between ‹ ›) is loaded by the main layout --}}

@section('content')
    <div class="today" data-today data-url="{{ route($area . '.schedule.sections', ['date' => $date->toDateString()]) }}">
        @include('reservationmodule::Schedule.partials.sections')
    </div>
@endsection
