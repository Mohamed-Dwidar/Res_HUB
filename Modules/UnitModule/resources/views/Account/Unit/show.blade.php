@extends(request()->ajax() ? 'layoutmodule::modal' : 'layoutmodule::account.main')

@section('title')
    {{ $space->name }} &rsaquo; Unit Details
@endsection

@section('modal-size', 'lg')

@section('actions')
    <a href="{{ route('account.spaces.units.edit', [$space->id, $unit->id]) }}" data-modal class="btn btn-secondary"><i data-lucide="pencil"></i> Edit</a>
    @include('unitmodule::Account.Unit.partials.delete-form')
@endsection

@section('content')
    <div class="flex items-center gap-3">
        @if ($unit->color)
            @include('unitmodule::partials.color-swatch', ['value' => $unit->color->value, 'class' => 'color-swatch--lg'])
        @endif
        <div class="text-lg font-medium">{{ $unit->name }}</div>
        <span class="badge {{ $unit->is_active ? 'badge-active' : 'badge-inactive' }}">{{ $unit->is_active ? 'Active' : 'Inactive' }}</span>
    </div>

    <div class="form-section mt-6">Unit Data</div>
    <dl class="detail-list">
        <div><dt>Space</dt><dd><a href="{{ route('account.spaces.show', $space->id) }}" class="text-primary">{{ $space->name }}</a></dd></div>
        <div><dt>Subscription Type</dt><dd>{{ $unit->subscriptionType?->name ?? '-' }}</dd></div>
        <div><dt>Color</dt><dd>{{ $unit->color?->name ?? '-' }}</dd></div>
        <div><dt>Capacity</dt><dd>{{ $unit->capacity ?: '-' }}</dd></div>
        <div><dt>Concurrent Usage</dt><dd>{{ $unit->concurrent_usage }}</dd></div>
        <div><dt>Lease Period</dt><dd>{{ $unit->lease_period_label ?? '-' }}</dd></div>
        <div class="wide"><dt>Description (Arabic)</dt><dd dir="rtl">{{ $unit->description_ar ?: '-' }}</dd></div>
        <div class="wide"><dt>Description (English)</dt><dd>{{ $unit->description_en ?: '-' }}</dd></div>
        <div class="wide"><dt>Notes (Arabic)</dt><dd dir="rtl">{{ $unit->notes_ar ?: '-' }}</dd></div>
        <div class="wide"><dt>Notes (English)</dt><dd>{{ $unit->notes_en ?: '-' }}</dd></div>
    </dl>

    <div class="form-section mt-8">Plans</div>
    @forelse ($unit->plans as $plan)
        <a href="{{ route('account.plans.show', $plan->id) }}" data-modal class="badge badge-inactive mr-1">{{ $plan->name }}</a>
    @empty
        <div class="text-slate-500">No plans chosen.</div>
    @endforelse

    @include('messagingmodule::Unit.show-messages')

    <div class="form-section mt-8">Images ({{ $unit->images->count() }})</div>
    @include('layoutmodule::partials.gallery', ['images' => $unit->images, 'alt' => $unit->name])

    @unless (request()->ajax())
        <div class="form-actions">
            <a href="{{ route('account.spaces.show', $space->id) }}" class="btn btn-secondary"><i data-lucide="arrow-left"></i> Back to {{ $space->name }}</a>
        </div>
    @endunless
@endsection
