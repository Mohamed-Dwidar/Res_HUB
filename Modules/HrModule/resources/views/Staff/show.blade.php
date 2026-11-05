{{-- ONE staff page (docs/hr-plan.md §B), tabs = $tabs (Profile, Documents + other modules' tabs via StaffTabs: Salary (PayrollModule); later Leaves, Advances & adjustments, Payslips) --}}
@extends(Auth::user()->userable->layout())

@section('title')
    {{ $staff->name }}
@endsection

@section('actions')
    @can('hr.manage')
        <a href="{{ route($area . '.hr.staff.edit', $staff->id) }}" data-modal class="btn btn-secondary"><i data-lucide="pencil"></i> Edit</a>
        <a href="{{ route($area . '.hr.staff.status', $staff->id) }}" data-modal class="btn btn-secondary"><i data-lucide="user-cog"></i> Change Status</a>
    @endcan
@endsection

@section('content')
    <div class="staff-head">
        <div>
            <div class="staff-head__name">
                {{ $staff->name }} <span class="staff-head__no">#{{ $staff->employee_no }}</span>
                @if ($staff->name_ar && $staff->name_ar !== $staff->name) <span class="staff-head__ar" dir="rtl">{{ $staff->name_ar }}</span> @endif
            </div>
            <div class="mt-1 text-slate-500">
                {{ $staff->position?->name ?? 'No job title' }}@if ($staff->department) · {{ $staff->department->name }}@endif
            </div>
        </div>
        <div>
            @include('hrmodule::Staff.partials.status-badge')
            @if ($staff->is_external) <span class="badge badge-status-slate">External</span> @endif
        </div>
    </div>


    <nav class="page-tabs staff-tabs mt-5">
        @foreach ($tabs as $value => $label)
            <a href="{{ route($area . '.hr.staff.show', $value === 'profile' ? $staff->id : [$staff->id, 'tab' => $value]) }}" @class(['active' => $tab === $value])>{{ $label }}</a>
        @endforeach
    </nav>

    @include($tabView)

    <div class="form-actions">
        <a href="{{ route($area . '.hr.staff.index') }}" class="btn btn-secondary"><i data-lucide="arrow-left"></i> Back to Staff</a>
    </div>
@endsection
