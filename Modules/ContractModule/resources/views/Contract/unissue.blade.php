@extends(request()->ajax() ? 'layoutmodule::modal' : Auth::user()->userable->layout())

@section('title')
    Back to Draft · Contract {{ $contract->number_label }}
@endsection

@section('content')
    <form method="POST" action="{{ route('account.contracts.unissue.store', $contract->id) }}" data-ajax
        data-confirm="It can be edited again and keeps its number ({{ $contract->number_label }}) for the next issue. A copy already printed or sent is no longer valid."
        data-confirm-title="Set it back to draft?"
        data-confirm-button="Back to Draft"
        data-confirm-variant="danger">
        @csrf
        <div class="mb-4">{{ $contract->number_label }} · <span dir="auto">{{ $contract->party_name }}</span> · issued {{ $contract->issued_at?->format('d-m-Y h:i A') }}</div>
        @include('layoutmodule::partials.field', ['name' => 'reason', 'label' => 'Reason', 'type' => 'textarea', 'required' => true, 'attrs' => 'maxlength="255"',
            'hint' => 'Kept on the contract with your name.'])

        @include('layoutmodule::partials.form-actions', ['cancel' => route('account.contracts.show', $contract->id)])
    </form>
@endsection
