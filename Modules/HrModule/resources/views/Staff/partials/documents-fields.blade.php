{{--
    Add Staff: optional documents uploaded with the new file (user 2026-11-04) — rows of type, name (Other), number (not the
    National ID), expiry (types with one), files; "+ Add another document" (custom.js [data-repeat]). A row without a type is
    skipped. Saved by StaffService::create() → createDocument() (the Documents tab's rules). $docTypes, $docTypeIds
--}}
@php
    $row = function ($index) use ($docTypes, $docTypeIds) {
        return view('hrmodule::Staff.partials.document-row', ['index' => $index, 'types' => $docTypes, 'typeIds' => $docTypeIds])->render();
    };
@endphp
<div class="form-section mt-8">Documents <span class="text-slate-500 font-normal">(optional)</span></div>
<div data-field="documents" data-repeat>
    <div data-repeat-list>{!! $row(0) !!}</div>
    <template data-repeat-template>{!! $row('__INDEX__') !!}</template>
    <button type="button" data-repeat-add class="btn btn-secondary btn-sm mt-3"><i data-lucide="plus"></i> Add another document</button>
</div>
