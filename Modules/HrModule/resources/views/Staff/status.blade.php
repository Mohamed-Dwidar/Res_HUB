{{-- Change Status (popup): status, since, reason (required to end the work); any status but Active switches the login off --}}
@extends(request()->ajax() ? 'layoutmodule::modal' : Auth::user()->userable->layout())

@section('title')
    Change Status: {{ $staff->name }}
@endsection

@section('content')
    @php($notActive = implode(' ', array_diff(array_keys(config('hrmodule.statuses')), ['active'])))
    <form method="POST" action="{{ route($area . '.hr.staff.status.update', $staff->id) }}" data-ajax>
        @csrf
        @method('PATCH')
        <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
            @include('layoutmodule::partials.field', ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true,
                'options' => config('hrmodule.statuses'), 'value' => $staff->status])
            @include('layoutmodule::partials.field', ['name' => 'status_date', 'label' => 'Since', 'type' => 'date', 'required' => true,
                'value' => today()->format('Y-m-d')])
            @include('layoutmodule::partials.field', ['name' => 'status_reason', 'label' => 'Reason', 'value' => null, 'wrapperClass' => 'md:col-span-2',
                'hint' => 'Required for Resigned / Terminated.'])
        </div>
        @if ($staff->user && !$staff->hasLeft())
            <div class="notice mt-5" data-show-if="status={{ $notActive }}">
                The system access ({{ $staff->user->email }}) will be switched off: logged out at the next click. Setting the status back to
                Active doesn't switch it on again — tick "Can log in" in Edit.
            </div>
        @endif
        @include('layoutmodule::partials.form-actions', ['cancel' => route($area . '.hr.staff.show', $staff->id)])
    </form>
@endsection
