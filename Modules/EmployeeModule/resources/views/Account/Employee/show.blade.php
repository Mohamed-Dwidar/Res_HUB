@extends(request()->ajax() ? 'layoutmodule::modal' : 'layoutmodule::account.main')

@section('title')
    Employee Details
@endsection

@section('modal-size', 'lg')

@section('actions')
    <a href="{{ route('account.employees.edit', $employee->id) }}" data-modal class="btn btn-secondary"><i data-lucide="pencil"></i> Edit</a>
    @include('employeemodule::Account.Employee.partials.left-form', ['backToView' => true])
    @include('employeemodule::Account.Employee.partials.delete-form')
@endsection

@section('content')
    <div class="flex items-center gap-4">
        @include('employeemodule::Account.Employee.partials.photo')
        <div>
            <div class="text-lg font-medium">{{ $employee->name }}</div>
            <div class="mt-1 text-slate-500">{{ $employee->user?->email }}</div>
            <div class="mt-2">@include('employeemodule::Account.Employee.partials.status-badge')</div>
        </div>
    </div>

    @include('employeemodule::Account.Employee.partials.details', ['withSalary' => true])

    <div class="form-section mt-8">Attachments ({{ $employee->attachments->count() }})</div>
    @if ($employee->attachments->isNotEmpty())
        <div class="attachment-list">
            @foreach ($employee->attachments as $attachment)
                <a href="{{ route('account.employees.attachments.download', [$employee->id, $attachment->id]) }}" class="attachment-list__item attachment-list__item--link">
                    <i data-lucide="paperclip"></i>
                    <span class="attachment-list__name">{{ $attachment->attach_label }}</span>
                    <i data-lucide="download" class="ml-auto"></i>
                </a>
            @endforeach
        </div>
    @else
        <div class="text-slate-500">No attachments.</div>
    @endif

    @unless (request()->ajax())
        <div class="form-actions">
            <a href="{{ route('account.employees.index') }}" class="btn btn-secondary"><i data-lucide="arrow-left"></i> Back to Employees</a>
        </div>
    @endunless
@endsection
