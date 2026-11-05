{{--
    Staff document fields. $document null on create; $types = [id => name]; $typeIds = StaffService::formTypeIds() (expiry /
    other / number type ids: the fields shown for the chosen type); $typeId = pre-chosen. Files: one or more (front + back …);
    edit: its files listed with "Remove". The National ID number is not here: it lives in the staff profile (Identity).
--}}
<div class="grid grid-cols-1 gap-5 md:grid-cols-2">
    @include('layoutmodule::partials.field', ['name' => 'document_type_id', 'label' => 'Document', 'type' => 'select', 'required' => true,
        'options' => ['' => '- Choose -'] + $types, 'value' => $document?->document_type_id ?? ($typeId ?? null) ?: null])
    @if ($typeIds['other'])
        <div data-show-if="document_type_id={{ implode(' ', $typeIds['other']) }}">
            @include('layoutmodule::partials.field', ['name' => 'name', 'label' => 'Document Name', 'required' => true, 'value' => $document?->name,
                'attrs' => 'maxlength="255" placeholder="e.g. Driving licence"'])
        </div>
    @endif
    <div data-show-if="document_type_id={{ implode(' ', $typeIds['number']) }}">
        @include('layoutmodule::partials.field', ['name' => 'number', 'label' => 'Number', 'value' => $document?->number,
            'hint' => 'Certificate / record number (if any).'])
    </div>
    @include('layoutmodule::partials.field', ['name' => 'issue_date', 'label' => 'Issue Date', 'type' => 'date', 'value' => $document?->issue_date?->format('Y-m-d')])
    @if ($typeIds['expiry'])
        <div data-show-if="document_type_id={{ implode(' ', $typeIds['expiry']) }}">
            @include('layoutmodule::partials.field', ['name' => 'expiry_date', 'label' => 'Expiry Date', 'type' => 'date', 'value' => $document?->expiry_date?->format('Y-m-d')])
        </div>
    @endif

    <div data-field="files" class="md:col-span-2">
        <label for="field-files" class="{{ config('layoutmodule.form.label') }}">Files</label>
        @if ($document && $document->files->isNotEmpty())
            <div class="staff-files staff-files--edit">
                @foreach ($document->files as $file)
                    <label class="staff-files__item">
                        <input type="checkbox" name="remove_files[]" value="{{ $file->id }}" class="{{ config('layoutmodule.form.checkbox') }}">
                        <span>Remove</span>
                        <a href="{{ route($area . '.hr.staff.documents.file', [$staff->id, $document->id, $file->id]) }}" target="_blank" rel="noopener">{{ $file->original_name }}</a>
                    </label>
                @endforeach
            </div>
        @endif
        <input type="file" name="files[]" id="field-files" multiple accept=".pdf,.jpg,.jpeg,.png" class="{{ config('layoutmodule.form.input') }} field-long">
        <div class="{{ config('layoutmodule.form.hint') }}">One or more files (e.g. front + back): PDF or image, max 10 MB each.</div>
    </div>
    @include('layoutmodule::partials.field', ['name' => 'notes', 'label' => 'Notes', 'value' => $document?->notes, 'wrapperClass' => 'md:col-span-2'])
</div>
