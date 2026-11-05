{{-- The Documents tab: the warnings (missing required types with an Add button, expired / expiring), the documents, Add.
     $documents (StaffService::documents()), $alert (StaffService::alerts() of this file, null when none / ended) --}}
@php($canManage = Gate::allows('documents.manage'))
@if ($alert && $alert['missing'])
    <div class="notice mb-4">
        <b>Missing:</b>
        @foreach ($alert['missing'] as $type)
            @if ($canManage)
                <a href="{{ route($area . '.hr.staff.documents.create', [$staff->id, 'document_type_id' => $type->id]) }}" data-modal class="staff-missing">+ {{ $type->name }}</a>
            @else
                <span class="staff-missing">{{ $type->name }}</span>
            @endif
        @endforeach
    </div>
@endif

<div class="form-section payments-block__head">
    <span>Documents ({{ $documents->count() }})</span>
    @if ($canManage)
        <a href="{{ route($area . '.hr.staff.documents.create', $staff->id) }}" data-modal class="btn btn-primary btn-sm"><i data-lucide="plus"></i> Add Document</a>
    @endif
</div>

@if ($documents->isEmpty())
    <div class="text-slate-500">No documents yet.</div>
@else
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Document</th>
                    <th>Number</th>
                    <th class="whitespace-nowrap">Issued</th>
                    <th class="whitespace-nowrap">Expires</th>
                    <th>Files</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($documents as $document)
                    <tr>
                        <td>
                            <div class="font-medium">{{ $document->label }}</div>
                            @if ($document->notes)
                                <div class="text-xs text-slate-500">{{ $document->notes }}</div>
                            @endif
                        </td>
                        <td>{{ $document->number ?: '-' }}</td>
                        <td class="whitespace-nowrap">{{ $document->issue_date?->format('d-m-Y') ?? '-' }}</td>
                        <td class="whitespace-nowrap">@include('hrmodule::Document.partials.expiry')</td>
                        <td>
                            @forelse ($document->files as $file)
                                <div class="staff-files__item">
                                    <a href="{{ route($area . '.hr.staff.documents.file', [$staff->id, $document->id, $file->id]) }}" target="_blank" rel="noopener" class="staff-file-link" title="View"><i data-lucide="paperclip"></i> {{ $file->original_name }}</a>
                                    <a href="{{ route($area . '.hr.staff.documents.file', [$staff->id, $document->id, $file->id, 'download' => 1]) }}" class="staff-file-link" title="Download"><i data-lucide="download"></i></a>
                                </div>
                            @empty
                                <span class="text-slate-400">No file</span>
                            @endforelse
                        </td>
                        <td>
                            @if ($canManage)
                                @include('layoutmodule::partials.row-menu', [
                                    'links' => [['label' => 'Edit', 'icon' => 'pencil', 'url' => route($area . '.hr.staff.documents.edit', [$staff->id, $document->id]), 'modal' => true]],
                                    'actions' => [['view' => 'hrmodule::Document.partials.delete-form', 'data' => ['staff' => $staff, 'document' => $document, 'area' => $area]]],
                                ])
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
