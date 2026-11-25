{{-- End / Cancel a contract or annex (Phase 3, the owner only; ContractService::close()): the date + the reason (contract_events) --}}
@extends(request()->ajax() ? 'layoutmodule::modal' : Auth::user()->userable->layout())

@section('title')
    {{ $action === 'end' ? 'End' : 'Cancel' }} · {{ $contract->kind_label }} {{ $contract->number_label }}
@endsection

@section('content')
    <form method="POST" action="{{ route('account.contracts.close.store', [$contract->id, $action]) }}" data-ajax
        data-confirm="{{ $action === 'end' ? 'It stops on this date: it covers the periods up to it, and a renewal after it needs a new contract.' : 'It becomes void: it covers no period any more. This can\'t be undone.' }}"
        data-confirm-title="{{ $action === 'end' ? 'End' : 'Cancel' }} {{ strtolower($contract->kind_label) }} {{ $contract->number_label }}?"
        data-confirm-button="{{ $action === 'end' ? 'End' : 'Cancel It' }}"
        data-confirm-variant="danger">
        @csrf
        <div class="mb-4"><span dir="auto">{{ $contract->party_name }}</span> · {{ $contract->starts_on->format('d-m-Y') }} → {{ $contract->ends_on->format('d-m-Y') }} · @include('contractmodule::Contract.partials.status')</div>
        <div class="grid grid-cols-1 gap-5">
            @include('layoutmodule::partials.field', ['name' => 'date', 'label' => $action === 'end' ? 'End Date' : 'Cancel Date', 'type' => 'date', 'required' => true,
                'value' => now()->format('Y-m-d'), 'hint' => $action === 'end' ? 'Inside its term; the last day it covers.' : null])
            @include('layoutmodule::partials.field', ['name' => 'reason', 'label' => 'Reason', 'type' => 'textarea', 'required' => true, 'attrs' => 'maxlength="255"',
                'hint' => 'Kept in the contract\'s history with your name.'])
        </div>

        @include('layoutmodule::partials.form-actions', ['cancel' => route('account.contracts.show', $contract->id)])
    </form>
@endsection
