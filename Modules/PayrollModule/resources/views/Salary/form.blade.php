{{--
    Add / edit a salary row (popup). $salary = the row (edit) or null; $from = a row to copy (New Salary from This / a raise:
    its amounts, an empty date); $types = the allowance types offered (active + the row's own). Empty / 0 allowance = none.
--}}
@extends(request()->ajax() ? 'layoutmodule::modal' : Auth::user()->userable->layout())

@section('title')
    {{ $salary ? 'Edit Salary' : 'New Salary' }} · {{ $staff->name }}
@endsection

@section('content')
    @php($source = $salary ?? $from)
    @php($amounts = $source ? $source->items->pluck('amount', 'allowance_type_id') : collect())
    <form method="POST" action="{{ $salary ? route($area . '.payroll.salaries.update', [$staff->id, $salary->id]) : route($area . '.payroll.salaries.store', $staff->id) }}" data-ajax>
        @csrf
        @if ($salary) @method('PUT') @endif
        @if ($from)
            <div class="notice notice--info mb-4">From {{ $from->effective_from->format('d-m-Y') }}: {{ number_format($from->total, 2) }}. The new salary starts on the date below; the old one is kept in the history.</div>
        @endif
        <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
            @include('layoutmodule::partials.field', ['name' => 'effective_from', 'label' => 'Effective From', 'type' => 'date', 'required' => true,
                'value' => $salary?->effective_from?->format('Y-m-d') ?? ($from ? null : $staff->join_date?->format('Y-m-d'))])
            @include('layoutmodule::partials.field', ['name' => 'basic', 'label' => 'Basic Salary', 'type' => 'number', 'required' => true,
                'value' => $source?->basic, 'attrs' => 'min=0 step=0.01'])
        </div>

        <div class="form-section mt-6">Allowances</div>
        @if ($types->isEmpty())
            <div class="text-slate-500">
                No allowance types yet.
                @if (Auth::user()->userable instanceof \Modules\AccountModule\app\Models\Account)
                    Add them in <a href="{{ route('account.hr.allowance-types.index') }}">HR › Allowance Types</a>.
                @endif
            </div>
        @else
            <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                @foreach ($types as $allowance)
                    @include('layoutmodule::partials.field', ['name' => 'allowances[' . $allowance->id . ']', 'label' => $allowance->name . ($allowance->is_insurable ? ' (insurable)' : '') . (!$allowance->is_active || $allowance->trashed() ? ' (inactive)' : ''),
                        'type' => 'number', 'value' => $amounts[$allowance->id] ?? null, 'attrs' => 'min=0 step=0.01'])
                @endforeach
            </div>
        @endif

        <div class="grid grid-cols-1 gap-5 mt-6">
            @include('layoutmodule::partials.field', ['name' => 'reason', 'label' => 'Reason', 'value' => $salary?->reason,
                'attrs' => 'maxlength="255" placeholder="e.g. Yearly raise"'])
        </div>
        @include('layoutmodule::partials.form-actions', ['cancel' => route($area . '.hr.staff.show', [$staff->id, 'tab' => 'salary'])])
    </form>
@endsection
