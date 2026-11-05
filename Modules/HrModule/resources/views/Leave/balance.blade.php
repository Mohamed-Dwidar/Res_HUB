{{-- A person's own yearly days of a leave type for one year (popup); empty = back to the type's default --}}
@extends(request()->ajax() ? 'layoutmodule::modal' : Auth::user()->userable->layout())

@section('title')
    {{ $card['type']->name }} {{ $year }} · {{ $staff->name }}
@endsection

@section('content')
    <form method="POST" action="{{ route($area . '.hr.leaves.balance.update', [$staff->id, $card['type']->id]) }}" data-ajax>
        @csrf
        @method('PUT')
        <input type="hidden" name="year" value="{{ $year }}">
        @include('layoutmodule::partials.field', ['name' => 'days', 'label' => 'Yearly Days for ' . $staff->name, 'type' => 'number',
            'value' => $card['own'] ? $card['entitled'] : null, 'attrs' => 'min=0 max=365 step=0.5 autofocus',
            'hint' => 'Empty = the type\'s default (' . ($card['type']->yearly_days === null ? 'no balance' : rtrim(rtrim(number_format($card['type']->yearly_days, 1), '0'), '.') . ' days') . '). Taken in ' . $year . ': ' . rtrim(rtrim(number_format($card['taken'], 1), '0'), '.') . '.'])
        @include('layoutmodule::partials.form-actions', ['cancel' => route($area . '.hr.staff.show', [$staff->id, 'tab' => 'leaves', 'year' => $year])])
    </form>
@endsection
