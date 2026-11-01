{{--
    The reservation view's "Contract" block (ReservationModule Reservation/show; ContractModule Phase 2). Shown only when the
    reservation is covered by a contract or its unit has a switched on template (ContractService::reservationState()):
    covered → "Covered by contract #N (start → end) · status" + its buttons; ended → "Contract #N ended" + Renew Contract;
    none → Create Contract. Nothing is ever created by itself.
--}}
@php
    $contractService = app(\Modules\ContractModule\app\Services\ContractService::class);
    $contractState = $contractService->reservationState($reservation);
    $area = request()->routeIs('employee.*') ? 'employee' : 'account';
    $dateFormat = \Modules\ReservationModule\app\Models\Reservation::DATE_FORMAT;
@endphp
@if ($contractState)
    @php($stateContract = $contractState['state'] === 'none' ? null : \Modules\ContractModule\app\Models\Contract::with('renewals')->find($contractState['contract']->id))
    <div class="form-section mt-8 payments-block__head">
        <span>Contract</span>
        @if ($contractState['state'] !== 'covered')
            <a href="{{ route($area . '.contracts.create', ['reservation_id' => $reservation->id]) }}" class="btn btn-primary btn-sm">
                <i data-lucide="file-plus"></i> {{ $contractState['state'] === 'ended' ? 'Renew Contract' : 'Create Contract' }}
            </a>
        @endif
    </div>
    @if ($contractState['state'] === 'covered')
        <div class="contract-block">
            <div>
                Covered by {{ $stateContract->isRenewal() ? 'renewal annex' : 'contract' }}
                <a href="{{ route($area . '.contracts.show', $stateContract->id) }}" data-modal class="font-medium">{{ $stateContract->number_label }}</a>
                ({{ $stateContract->starts_on->format($dateFormat) }} → {{ $stateContract->ends_on->format($dateFormat) }}) ·
                @include('contractmodule::Contract.partials.status', ['contract' => $stateContract])
            </div>
            <div class="contract-block__buttons">
                <a href="{{ route($area . '.contracts.show', $stateContract->id) }}" data-modal class="btn btn-secondary btn-sm"><i data-lucide="eye"></i> View</a>
                @include('contractmodule::Contract.partials.actions', ['contract' => $stateContract, 'compact' => true, 'languages' => $contractService->languages($stateContract)])
            </div>
        </div>
    @elseif ($contractState['state'] === 'ended')
        <div class="text-slate-500">
            Contract <a href="{{ route($area . '.contracts.show', $stateContract->id) }}" data-modal class="font-medium">{{ $stateContract->number_label }}</a>
            ended on {{ $stateContract->ends_on->format($dateFormat) }}: this period is not covered. <b>Renew Contract</b> makes a renewal annex.
        </div>
    @else
        <div class="text-slate-500">No contract covers this period yet ({{ $contractState['template']->name }}).</div>
    @endif
@endif
