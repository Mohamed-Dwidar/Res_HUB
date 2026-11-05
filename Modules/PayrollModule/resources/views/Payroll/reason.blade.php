{{-- Reopen a month / undo a salary payment (popup): $title, $text, $action, $error (null = allowed); the reason is kept --}}
@extends(request()->ajax() ? 'layoutmodule::modal' : Auth::user()->userable->layout())

@section('title')
    {{ $title }}
@endsection

@section('content')
    <form method="POST" action="{{ $action }}" data-ajax>
        @csrf
        @if ($error)
            <div class="notice notice--warning mb-4">{{ $error }}</div>
        @else
            <div class="notice notice--warning mb-4">{{ $text }}</div>
            @include('layoutmodule::partials.field', ['name' => 'reason', 'label' => 'Reason', 'required' => true, 'attrs' => 'maxlength="255" autofocus'])
        @endif
        <div class="form-actions">
            @unless ($error)
                <button type="submit" class="btn btn-danger">{{ $title }}</button>
            @endunless
            <button type="button" data-tw-dismiss="modal" class="btn btn-secondary">Back</button>
        </div>
    </form>
@endsection
