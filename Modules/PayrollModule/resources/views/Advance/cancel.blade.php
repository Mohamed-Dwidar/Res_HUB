{{-- Cancel an advance / a move / a bonus or deduction (popup): $title, $text, $action; the reason is required and kept --}}
@extends(request()->ajax() ? 'layoutmodule::modal' : Auth::user()->userable->layout())

@section('title')
    {{ $title }}
@endsection

@section('content')
    <form method="POST" action="{{ $action }}" data-ajax data-confirm="{{ $text }}" data-confirm-title="{{ $title }}?" data-confirm-button="Cancel it" data-confirm-variant="danger">
        @csrf
        <div class="notice notice--warning mb-4">{{ $text }}</div>
        @include('layoutmodule::partials.field', ['name' => 'cancel_reason', 'label' => 'Reason', 'required' => true, 'attrs' => 'maxlength="255" autofocus'])
        <div class="form-actions">
            <button type="submit" class="btn btn-danger"><i data-lucide="x-circle"></i> Cancel It</button>
            <button type="button" data-tw-dismiss="modal" class="btn btn-secondary">Back</button>
        </div>
    </form>
@endsection
