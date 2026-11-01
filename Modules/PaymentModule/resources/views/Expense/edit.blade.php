@php($area = request()->routeIs('employee.*') ? 'employee' : 'account')
@extends(request()->ajax() ? 'layoutmodule::modal' : Auth::user()->userable->layout())

@section('title')
    Edit Cash Out #{{ $expense->expense_no }}
@endsection

@section('modal-size', 'lg')

{{-- the owner, or the recorder while their shift is open (2026-11-01, ExpenseService::canEdit(): no date); in a closed shift
     (the owner) a reason is required (logged on the shift, its totals recalculated) --}}
@section('content')
    <form method="POST" action="{{ route($area . '.expenses.update', $expense->id) }}" enctype="multipart/form-data" data-ajax data-row="{{ $expense->id }}"
        data-confirm="The cash out will be changed{{ $closedShift ? ', and its closed shift calculated again' : '' }}."
        data-confirm-title="Save the changes?"
        data-confirm-button="Save"
        data-confirm-variant="success">
        @csrf
        @method('PUT')
        @include('paymentmodule::Expense.partials.closed-shift-note')
        @include('paymentmodule::Expense.partials.form')
        @include('layoutmodule::partials.form-actions', ['cancel' => route($area . '.expenses.show', $expense->id)])
    </form>
@endsection
