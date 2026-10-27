{{--
    The status of the reservation as a drop menu styled like its badge (the reservation view header).
    Choosing a status saves it by ajax (custom.js [data-status-option]): loading on the badge, then the new badge + the list row.
    Cancelling one with payments is refused ("Refund first"); the account owner (data-can-force) may confirm it with a reason.
    $reservation, $statuses (ReservationStatus models: the active ones + the current one)
--}}
@php
    $area = request()->routeIs('employee.*') ? 'employee' : 'account';
    $menuId = 'status-menu-' . $reservation->id . '-' . uniqid();
@endphp
<div data-tw-merge="" data-tw-placement="bottom-end" class="dropdown relative">
    <button type="button" id="{{ $menuId }}" data-tw-toggle="dropdown" aria-expanded="false" title="Change the status"
        class="{{ $reservation->status?->badge_class ?? 'badge badge-status-slate' }} status-menu__toggle">
        <span data-status-name>{{ $reservation->status?->name ?? 'No status' }}</span>
        <i data-lucide="chevron-down" class="status-menu__chevron"></i>
        <span class="status-menu__spinner" aria-hidden="true"></span>
    </button>
    <div class="dropdown-menu js-menu absolute z-[9999]">
        <div data-tw-merge="" class="dropdown-content rounded-md border-transparent bg-white p-2 shadow-[0px_3px_10px_#00000017] dark:border-transparent dark:bg-darkmode-600 w-52">
            @forelse ($statuses as $status)
                <button type="button" data-tw-dismiss="dropdown" data-status-option class="{{ config('layoutmodule.menu.item') }}"
                    data-url="{{ route($area . '.reservations.status', $reservation->id) }}" data-status-id="{{ $status->id }}"
                    data-toggle="{{ $menuId }}" data-row="{{ $reservation->id }}" @if ($area === 'account') data-can-force @endif>
                    <span class="{{ $status->badge_class }}">{{ $status->name }}</span>
                    @if ($status->id == $reservation->reservation_status_id)
                        <i data-lucide="check" class="ml-auto h-4 w-4 text-slate-500"></i>
                    @endif
                </button>
            @empty
                <div class="p-2 text-xs text-slate-500">No active statuses.</div>
            @endforelse
        </div>
    </div>
</div>
