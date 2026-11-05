{{-- Cancel a leave (popup): the reason is required and kept --}}
@extends(request()->ajax() ? 'layoutmodule::modal' : Auth::user()->userable->layout())

@section('title')
    Cancel Leave · {{ $staff->name }}
@endsection

@section('content')
    <form method="POST" action="{{ route($area . '.hr.leaves.cancel', [$staff->id, $leave->id]) }}" data-ajax>
        @csrf
        <div class="notice notice--warning mb-4">{{ $leave->type?->name }} · {{ $leave->datesText() }} · {{ $leave->daysText() }} will be cancelled (its days go back to the balance).</div>
        @include('layoutmodule::partials.field', ['name' => 'cancel_reason', 'label' => 'Reason', 'required' => true, 'attrs' => 'maxlength="255" autofocus'])
        <div class="form-actions">
            <button type="submit" class="btn btn-danger"><i data-lucide="x-circle"></i> Cancel Leave</button>
            <button type="button" data-tw-dismiss="modal" class="btn btn-secondary">Back</button>
        </div>
    </form>
@endsection
