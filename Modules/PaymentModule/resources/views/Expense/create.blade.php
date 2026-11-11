@php($area = request()->routeIs('employee.*') ? 'employee' : 'account')
@extends(request()->ajax() ? 'layoutmodule::modal' : Auth::user()->userable->layout())

@section('title')
    Add Cash Out
@endsection

@section('modal-size', 'lg')

{{-- any login: the expense joins YOUR open shift (the money leaves your drawer) --}}
@section('content')
    <form method="POST" action="{{ route($area . '.expenses.store') }}" enctype="multipart/form-data" data-ajax
        data-confirm="The cash out will be added to your shift: the money leaves your drawer."
        data-confirm-title="Add this cash out?"
        data-confirm-button="Add"
        data-confirm-variant="success">
        @csrf
        {{-- opened from the Schedule page "New" menu: the Payments list (Cash Out tab) after saving --}}
        @if (request()->boolean('back_to_list'))
            <input type="hidden" name="back_to_list" value="1">
        @endif
        @include('paymentmodule::Expense.partials.form')
        @include('layoutmodule::partials.form-actions', ['cancel' => route($area . '.payments.index', ['type' => 'out'])])
    </form>
@endsection
