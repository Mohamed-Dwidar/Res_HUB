@php($area = request()->routeIs('employee.*') ? 'employee' : 'account')
@extends(request()->ajax() ? 'layoutmodule::modal' : Auth::user()->userable->layout())

@section('title')
    {{ $contract->status === 'signed' ? 'Replace the Signed Scan' : 'Upload Signed' }} · Contract {{ $contract->number_label }}
@endsection

@section('content')
    <form method="POST" action="{{ route($area . '.contracts.signed.store', $contract->id) }}" data-ajax enctype="multipart/form-data"
        data-confirm="{{ $contract->status === 'signed' ? 'The scan and signing date are replaced.' : 'The contract is marked signed on this date.' }}"
        data-confirm-title="{{ $contract->status === 'signed' ? 'Replace the scan?' : 'Mark it signed?' }}"
        data-confirm-button="Save"
        data-confirm-variant="success">
        @csrf
        <div class="grid grid-cols-1 gap-5">
            @include('layoutmodule::partials.field', ['name' => 'signed_on', 'label' => 'Signed On', 'type' => 'date', 'required' => true,
                'value' => ($contract->signed_on ?? now())->format('Y-m-d'), 'attrs' => 'max="' . now()->format('Y-m-d') . '"'])
            <div data-field="signed_file">
                <label for="field-signed_file" class="{{ config('layoutmodule.form.label') }}">Signed Scan</label>
                <input type="file" name="signed_file" id="field-signed_file" accept="application/pdf,image/jpeg,image/png" class="{{ config('layoutmodule.form.input') }} field-long">
                <div class="{{ config('layoutmodule.form.hint') }}">PDF or image (JPG, PNG), up to 10 MB. Optional: it can be added later{{ $contract->status === 'signed' ? '' : ' (by the owner)' }}.</div>
            </div>
        </div>

        @include('layoutmodule::partials.form-actions', ['cancel' => route($area . '.contracts.show', $contract->id)])
    </form>
@endsection
