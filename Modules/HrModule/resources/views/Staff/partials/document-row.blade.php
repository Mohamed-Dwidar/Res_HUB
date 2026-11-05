{{-- one document row of the Add Staff form ($index, $types = [id => name], $typeIds = StaffService::formTypeIds()) --}}
@php($p = 'documents[' . $index . ']')
<div data-repeat-row class="staff-doc-row form-grid">
    <div>
        <label class="{{ config('layoutmodule.form.label') }}">Document</label>
        <select name="{{ $p }}[document_type_id]" class="{{ config('layoutmodule.form.select') }}">
            <option value="">- None -</option>
            @foreach ($types as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </select>
    </div>
    @if ($typeIds['other'])
        <div data-show-if="{{ $p }}[document_type_id]={{ implode(' ', $typeIds['other']) }}">
            <label class="{{ config('layoutmodule.form.label') }}">Document Name <span class="text-danger">*</span></label>
            <input type="text" name="{{ $p }}[name]" maxlength="255" class="{{ config('layoutmodule.form.input') }}">
        </div>
    @endif
    <div data-show-if="{{ $p }}[document_type_id]={{ implode(' ', $typeIds['number']) }}">
        <label class="{{ config('layoutmodule.form.label') }}">Number</label>
        <input type="text" name="{{ $p }}[number]" maxlength="100" class="{{ config('layoutmodule.form.input') }}">
    </div>
    @if ($typeIds['expiry'])
        <div data-show-if="{{ $p }}[document_type_id]={{ implode(' ', $typeIds['expiry']) }}">
            <label class="{{ config('layoutmodule.form.label') }}">Expiry Date</label>
            <input type="date" name="{{ $p }}[expiry_date]" class="{{ config('layoutmodule.form.input') }} field-short">
        </div>
    @endif
    <div class="staff-doc-row__files">
        <label class="{{ config('layoutmodule.form.label') }}">Files</label>
        <input type="file" name="{{ $p }}[files][]" multiple accept=".pdf,.jpg,.jpeg,.png" class="{{ config('layoutmodule.form.input') }}">
    </div>
    <div class="staff-doc-row__remove">
        <button type="button" data-repeat-remove class="btn btn-secondary btn-sm" title="Remove this row"><i data-lucide="x"></i></button>
    </div>
</div>
