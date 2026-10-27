@extends(request()->ajax() ? 'layoutmodule::modal' : 'layoutmodule::account.main')

@section('title')
    Plan Details
@endsection

@section('modal-size', 'lg')

@section('actions')
    <a href="{{ route('account.plans.edit', $plan->id) }}" data-modal class="btn btn-secondary"><i data-lucide="pencil"></i> Edit</a>
    @include('planmodule::Account.partials.delete-form')
@endsection

@section('content')
    <div class="flex items-center gap-3">
        <div class="text-lg font-medium">{{ $plan->name }}</div>
        <span class="badge {{ $plan->is_active ? 'badge-active' : 'badge-inactive' }}">{{ $plan->is_active ? 'Active' : 'Inactive' }}</span>
    </div>

    <div class="form-section mt-6">Plan Data</div>
    <dl class="detail-list">
        <div><dt>Amount</dt><dd>{{ number_format((float) $plan->amount, 2) }}</dd></div>
        <div><dt>Lease Period</dt><dd>{{ $plan->lease_period_label ?? '-' }}</dd></div>
        <div><dt>Capacity</dt><dd>{{ $plan->capacity ?: '-' }}</dd></div>
        <div><dt>Time Based</dt><dd>{{ $plan->is_time_based ? 'Yes (start / end time)' : 'No' }}</dd></div>
        @if (($taxAccount = \Modules\AccountModule\app\Models\Account::current())?->vatEnabled())
            <div><dt>Taxes</dt><dd>{{ $plan->taxSummary($taxAccount) }}<div class="text-xs text-slate-500">{{ $plan->invoice_by_default ? 'Invoice by default' : 'No invoice by default' }} (taxes only with an invoice)</div></dd></div>
        @endif
        <div><dt>Added At</dt><dd>{{ $plan->created_at->format('Y-m-d H:i') }}</dd></div>
        <div class="wide"><dt>Facilities</dt><dd class="whitespace-pre-line">{{ $plan->facilities ?: '-' }}</dd></div>
    </dl>

    <div class="form-section mt-8">Spaces ({{ $plan->spaces->count() }})</div>
    @forelse ($plan->spaces as $space)
        <a href="{{ route('account.spaces.show', $space->id) }}" class="badge badge-inactive mr-1">{{ $space->name }}</a>
    @empty
        <div class="text-slate-500">Not assigned to any space yet. Assign it from the space form.</div>
    @endforelse

    <div class="form-section mt-8">Units ({{ $plan->units->count() }})</div>
    @forelse ($plan->units as $unit)
        <a href="{{ route('account.spaces.units.show', [$unit->space_id, $unit->id]) }}" data-modal class="badge badge-inactive mr-1">{{ $unit->space?->name }} &rsaquo; {{ $unit->name }}</a>
    @empty
        <div class="text-slate-500">Not used by any unit yet. Choose it from the unit form.</div>
    @endforelse

    @unless (request()->ajax())
        <div class="form-actions">
            <a href="{{ route('account.plans.index') }}" class="btn btn-secondary"><i data-lucide="arrow-left"></i> Back to Plans</a>
        </div>
    @endunless
@endsection
