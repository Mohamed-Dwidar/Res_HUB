{{--
    The contract badge of a subscription (period type, counted) reservation ($reservation; 2026-11-25): "Contract" = opens the contract
    covering it (ContractService::coveringContract(); a draft says so; "Contract + annex" = an annex covers it) | "Annex missing" (renewed, the
    contract ended, no annex: Phase 3) | "No contract". Other reservations: nothing.
    Used by the reservation view, the reservations list (Status) and the Schedule renewal / Not paid rows.
--}}
@if ($reservation instanceof \Modules\ReservationModule\app\Models\Reservation && $reservation->isPeriod() && $reservation->isCounted())
    @php
        $badgeService = app(\Modules\ContractModule\app\Services\ContractService::class);
        $badgeContract = $badgeService->coveringContract($reservation);
        // renewed with no annex: the chain has an issued / signed contract that ended before this period (Phase 3)
        $annexMissing = !$badgeContract && $badgeService->previousContract($reservation);
        $badgeText = $badgeContract ? ($badgeContract->isRenewal() ? 'Contract + annex' : 'Contract') . ($badgeContract->isDraft() ? ' (draft)' : '') : null;
    @endphp
    <div class="mt-1">
        @if ($annexMissing)
            <span class="badge badge-status-warning" title="Renewed: the contract ended before this period and no renewal annex covers it">Annex missing</span>
        @elseif (!$badgeContract)
            <span class="badge badge-status-slate">No contract</span>
        @elseif (Gate::allows('contracts.view'))
            <a href="{{ route((request()->routeIs('employee.*') ? 'employee' : 'account') . '.contracts.show', $badgeContract->id) }}" data-modal
                class="badge {{ $badgeContract->isDraft() ? 'badge-status-pending' : 'badge-status-success badge-contract' }}" title="{{ $badgeContract->number_label }} · {{ $badgeContract->status_label }}"><i data-lucide="file-text" class="inline h-3 w-3"></i> {{ $badgeText }}</a>
        @else
            <span class="badge {{ $badgeContract->isDraft() ? 'badge-status-pending' : 'badge-status-success' }}">{{ $badgeText }}</span>
        @endif
    </div>
@endif
