@php
    $area = request()->routeIs('employee.*') ? 'employee' : 'account';
    $dateFormat = \Modules\ReservationModule\app\Models\Reservation::DATE_FORMAT;
    $money = fn ($value) => number_format((float) $value, 2);
@endphp
@extends(request()->ajax() ? 'layoutmodule::modal' : Auth::user()->userable->layout())

@section('title')
    {{ $contract->isRenewal() ? 'Renewal Annex' : 'Contract' }} {{ $contract->contract_no ? '#' . $contract->contract_no : '(draft)' }}
@endsection

@section('modal-size', 'lg')

@section('actions')
    @include('contractmodule::Contract.partials.actions')
@endsection

@section('content')
    <div class="flex flex-wrap items-center gap-2">
        @include('contractmodule::Contract.partials.status')
        <span class="text-lg font-medium" dir="auto">{{ $contract->party_name }}</span>
        <span class="text-sm text-slate-500">{{ $contract->member?->phone }}</span>
    </div>

    @if ($contract->isDraft())
        @if ($missing)
            <div class="notice mt-4">To issue it, fill {{ implode(', ', $missing) }}: <a href="{{ route($area . '.contracts.edit', $contract->id) }}" class="font-medium text-primary">Edit Draft</a>.</div>
        @else
            <div class="mt-4 text-sm text-slate-500">A draft: check the PDF (marked DRAFT), then Issue — the contract gets its number and is kept as it is.</div>
        @endif
    @endif

    <div class="form-section mt-6">&nbsp;</div>
    <dl class="detail-list">
        <div><dt>Contract With</dt><dd>{{ $contract->party_type === 'company' ? 'Company' : 'Individual' }}</dd></div>
        <div><dt>Space › Unit</dt><dd>{{ $contract->space?->name ?? '-' }} › {{ $contract->unit?->name ?? '-' }} <span class="text-slate-500">· on the contract: {{ $contract->unit_number ?: '-' }}</span></dd></div>
        <div><dt>Contract Date</dt><dd>{{ $contract->contract_date?->format($dateFormat) ?? '-' }}</dd></div>
        <div><dt>Term</dt><dd>{{ $contract->starts_on->format($dateFormat) }} → {{ $contract->ends_on->format($dateFormat) }}
            <span class="text-slate-500">· {{ \Modules\ContractModule\app\Models\ContractTemplate::termLabel($contract->term_months) }}</span></dd></div>
        <div><dt>Reservation</dt><dd>
            @if ($contract->reservation_id)
                <a href="{{ route($area . '.reservations.show', $contract->reservation_id) }}" data-modal class="font-medium">#{{ $contract->reservation_id }}</a>
            @else - @endif
        </dd></div>
        <div><dt>Contract Value</dt><dd>{{ $money($contract->term_amount) }} {{ $contract->currency }} <span class="text-slate-500">· duration {{ $contract->duration_text ?: '-' }}</span></dd></div>
        <div><dt>Security Deposit</dt><dd>{{ $contract->deposit_amount !== null ? $money($contract->deposit_amount) : '-' }}</dd></div>
        <div><dt>Activity</dt><dd dir="auto">{{ $contract->activity ?: '-' }}</dd></div>
        <div><dt>Template</dt><dd>{{ $contract->template?->name ?? $contract->template_name ?? '-' }}
            @if ($contract->isDraft() && ($contract->body_ar || $contract->body_en)) <span class="badge badge-status-info">Text edited</span>@endif</dd></div>
        @if ($contract->renewal_of)
            <div><dt>Renews</dt><dd><a href="{{ route($area . '.contracts.show', $contract->renewal_of) }}" data-modal class="font-medium">{{ $contract->previous?->number_label ?? '#?' }}</a></dd></div>
        @endif
        @if ($contract->renewals->isNotEmpty())
            <div><dt>Renewed By</dt><dd>
                @foreach ($contract->renewals as $renewal)
                    <a href="{{ route($area . '.contracts.show', $renewal->id) }}" data-modal class="font-medium">{{ $renewal->number_label }}</a>@unless ($loop->last), @endunless
                @endforeach
            </dd></div>
        @endif
        @if ($contract->issued_at)
            <div><dt>Issued</dt><dd>{{ $contract->issued_at->format($dateFormat . ' h:i A') }}</dd></div>
        @endif
        @if ($contract->signed_on)
            <div><dt>Signed</dt><dd>{{ $contract->signed_on->format($dateFormat) }} @unless ($hasScan) <span class="badge badge-status-warning">No scan</span>@endunless</dd></div>
        @endif
        <div><dt>Tenant</dt><dd dir="auto">{{ $contract->tenant_name ?: '-' }} @if ($contract->tenant_national_id)<span class="text-slate-500">· {{ $contract->tenant_national_id }}</span>@endif</dd></div>
        <div><dt>Representative</dt><dd dir="auto">{{ $contract->tenant_representative ?: '-' }} @if ($contract->tenant_title)<span class="text-slate-500">· {{ $contract->tenant_title }}</span>@endif</dd></div>
        <div><dt>Company</dt><dd dir="auto">{{ $contract->tenant_company ?: '-' }}</dd></div>
        <div><dt>Mobile / Email</dt><dd>{{ $contract->tenant_mobile ?: '-' }} · {{ $contract->tenant_email ?: '-' }}</dd></div>
        <div class="wide"><dt>Unit Address</dt><dd dir="auto">{{ $contract->unit_address ?: '-' }}</dd></div>
        <div class="wide"><dt>Notes</dt><dd class="whitespace-pre-line" dir="auto">{{ $contract->notes ?: '-' }}</dd></div>
        <div class="wide"><dd>Added by <b>{{ $contract->creatable?->userable?->displayName() ?? '-' }}</b> | At {{ $contract->created_at->format($dateFormat . ' h:i A') }}</dd></div>
    </dl>
@endsection
