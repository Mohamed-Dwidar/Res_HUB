@extends(request()->ajax() ? 'layoutmodule::modal' : 'layoutmodule::account.main')

{{-- $kind = package | reservation (2026-11-08), $extrable = it --}}
@section('title')
    Edit {{ $extra->type_label }} · {{ $kind === 'reservation' ? 'Reservation #' . $extrable?->id : $extrable?->name }}
@endsection

@section('modal-size', 'lg')

@section('content')
    <form method="POST" action="{{ route('account.extras.update', $extra->id) }}" data-ajax data-extra-form
        data-confirm="What the {{ $kind }} owes will be calculated again."
        data-confirm-title="Save the changes?"
        data-confirm-button="Save"
        data-confirm-variant="success">
        @csrf
        @method('PUT')

        @include('paymentmodule::Extra.partials.form')

        @include('layoutmodule::partials.form-actions', ['cancel' => route('account.' . $kind . 's.show', $extra->extrable_id)])
    </form>
@endsection
