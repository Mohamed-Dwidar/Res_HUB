@php
    $area = request()->routeIs('employee.*') ? 'employee' : 'account';
    $dateFormat = \Modules\ReservationModule\app\Models\Reservation::DATE_FORMAT;
    $money = fn ($value) => number_format((float) $value, 2);
@endphp
@extends(request()->ajax() ? 'layoutmodule::modal' : Auth::user()->userable->layout())

@section('title')
    {{ $contract->kind_label }} {{ $contract->contract_no ? '#' . $contract->contract_no : '(draft)' }}
@endsection

@section('modal-size', 'xl')

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
        <div><dt>Space › Unit</dt><dd>{{ $contract->space?->name ?? '-' }} › {{ $contract->unit?->name ?? '-' }} <span class="text-slate-500">· on the contract: {{ $contract->unit_number ?: '-' }}</span></dd></div>
        <div><dt>Term</dt><dd>{{ $contract->starts_on->format($dateFormat) }} → {{ $contract->ends_on->format($dateFormat) }}
            <span class="text-slate-500">· {{ \Modules\ContractModule\app\Models\ContractTemplate::termLabel($contract->term_months ?: ($contract->reservation ? \Modules\ContractModule\app\Services\ContractService::leaseMonths($contract->reservation, $contract->starts_on, $contract->ends_on) : null)) }}</span></dd></div>
        <div><dt>Reservation</dt><dd>
            @if ($contract->reservation_id)
                <a href="{{ route($area . '.reservations.show', $contract->reservation_id) }}" data-modal class="font-medium">#{{ $contract->reservation_id }}</a>
            @else - @endif
        </dd></div>
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
        @if (in_array($contract->status, ['ended', 'terminated'], true) && $contract->ended_on)
            <div><dt>{{ $contract->status === 'terminated' ? 'Terminated (left)' : 'Ended' }}</dt><dd class="text-danger">{{ $contract->ended_on->format($dateFormat) }}</dd></div>
        @endif
        @if ($contract->status === 'cancelled')
            <div><dt>Cancelled</dt><dd class="text-danger">{{ $contract->cancelled_at?->format($dateFormat) }} · <span dir="auto">{{ $contract->cancel_reason }}</span></dd></div>
        @endif
        {{-- an older back-to-draft line (before the events) --}}
        @foreach ($contract->values['history'] ?? [] as $entry)
            <div class="wide"><dt>Back to Draft</dt><dd>{{ \Illuminate\Support\Carbon::parse($entry['at'])->format($dateFormat . ' h:i A') }} · {{ $entry['by'] ?? '-' }} · <span dir="auto">{{ $entry['reason'] }}</span></dd></div>
        @endforeach
        <div class="wide"><dd>Added by <b>{{ $contract->creatable?->userable?->displayName() ?? '-' }}</b> | At {{ $contract->created_at->format($dateFormat . ' h:i A') }}</dd></div>
    </dl>

    {{-- the contract with its renewal annexes (Phase 3) --}}
    @if ($chain->count() > 1)
        <div class="form-section mt-6">Contract &amp; Annexes</div>
        @include('contractmodule::Contract.partials.timeline', ['chain' => $chain, 'current' => $contract])
    @endif

    {{-- its history (contract_events): issued, signed, back to draft, ended, cancelled --}}
    @if ($contract->events->isNotEmpty())
        <div class="form-section mt-6">History</div>
        <ul class="contract-history">
            @foreach ($contract->events as $event)
                <li><b>{{ $event->type_label }}</b>@if ($event->event_date) · {{ $event->event_date->format($dateFormat) }}@endif
                    · <span class="text-slate-500">{{ $event->created_at?->format($dateFormat . ' h:i A') }} · {{ $event->creatable?->userable?->displayName() ?? '-' }}</span>
                    @if ($event->reason)<div class="text-slate-600" dir="auto">{{ $event->reason }}</div>@endif</li>
            @endforeach
        </ul>
    @endif

    {{-- the whole contract as printed (2026-11-25, user): Arabic, + English when it has one; an issued one = its snapshot --}}
    @foreach ($documents as $document)
        <div class="form-section mt-6">{{ count($documents) > 1 ? ($document['lang'] === 'en' ? 'Contract (English)' : 'Contract (Arabic)') : 'Contract' }}</div>
        <div class="contract-document" dir="{{ $document['lang'] === 'en' ? 'ltr' : 'rtl' }}">
            @php($logo = $document['account']?->logo ? 'uploads/accounts/' . $document['account']->id . '/' . $document['account']->logo : '')
            <img src="{{ asset($logo && is_file(public_path($logo)) ? $logo : 'assets/images/logo.png') }}" alt="" class="contract-document__logo">
            @include('contractmodule::Contract.partials.document', $document)
        </div>
    @endforeach
@endsection
