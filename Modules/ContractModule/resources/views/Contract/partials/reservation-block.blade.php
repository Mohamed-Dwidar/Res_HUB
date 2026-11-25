{{--
    The reservation view's "Contract" section (ReservationModule Reservation/show; Phase 3, 2026-11-25): the contract chain of this
    subscription as cards (the original contract + its annexes: partials/timeline) and what this period needs
    (ContractService::reservationState()): covered → its contract / annex marked + its buttons; ended → "Renewal annex" (only when the
    chain has an issued contract and the unit has an annex template); none → Create Contract (a renewed period with no contract:
    "No contract — issue a contract first"). Nothing is ever created by itself.
--}}
@php
    $contractService = app(\Modules\ContractModule\app\Services\ContractService::class);
    $contractState = $contractService->reservationState($reservation);
    $area = request()->routeIs('employee.*') ? 'employee' : 'account';
    $stateContract = $contractState && $contractState['state'] !== 'none' ? \Modules\ContractModule\app\Models\Contract::with('renewals')->find($contractState['contract']->id) : null;
    $contractChain = $stateContract ? $contractService->chain($stateContract) : collect();
@endphp
@if ($contractState)
    <div class="form-section mt-8 payments-block__head">
        <span>Contract</span>
        @can('contracts.draft')
            @if ($contractState['state'] === 'ended' && !empty($contractState['template']))
                <a href="{{ route($area . '.contracts.create', ['reservation_id' => $reservation->id]) }}" class="btn btn-primary btn-sm"><i data-lucide="file-plus"></i> Renewal Annex</a>
            @elseif ($contractState['state'] === 'none')
                <a href="{{ route($area . '.contracts.create', ['reservation_id' => $reservation->id]) }}" class="btn btn-primary btn-sm"><i data-lucide="file-plus"></i> Create Contract</a>
            @endif
        @endcan
    </div>
    @if ($contractState['state'] === 'covered')
        <div class="contract-block">
            <div>This period is covered by {{ strtolower($stateContract->kind_label) }} <b>{{ $stateContract->number_label }}</b>.</div>
            <div class="contract-block__buttons">
                @include('contractmodule::Contract.partials.actions', ['contract' => $stateContract, 'compact' => true, 'languages' => $contractService->languages($stateContract)])
            </div>
        </div>
    @elseif ($contractState['state'] === 'ended')
        <div class="notice notice--warning">Renewed without an annex: {{ strtolower($stateContract->kind_label) }} {{ $stateContract->number_label }} ended on
            {{ $stateContract->ends_on->format(\Modules\ReservationModule\app\Models\Reservation::DATE_FORMAT) }}.
            @if (empty($contractState['template'])) No renewal annex template is switched on for this unit (Settings › Contracts). @endif</div>
    @elseif ($reservation->renewal_of)
        <div class="text-slate-500">No contract — issue a contract first (no renewal annex without one).</div>
    @else
        <div class="text-slate-500">No contract covers this period yet ({{ $contractState['template']->name }}).</div>
    @endif
    @if ($contractChain->isNotEmpty())
        <div class="mt-4">@include('contractmodule::Contract.partials.timeline', ['chain' => $contractChain, 'current' => $contractState['state'] === 'covered' ? $stateContract : null, 'rows' => true])</div>
    @endif
@endif
