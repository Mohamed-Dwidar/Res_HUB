@php
    $area = request()->routeIs('employee.*') ? 'employee' : 'account';
@endphp
@extends(request()->ajax() ? 'layoutmodule::modal' : Auth::user()->userable->layout())

@section('title')
    Member Details
@endsection

@section('modal-size', 'lg')

@section('actions')
    {{-- the member's lists (as in the Members list row menu), in a new tab; Payments = every cash in of the member (employees too) --}}
    <a href="{{ route($area . '.reservations.index', ['member_id' => $member->id]) }}" target="_blank" class="btn btn-secondary"><i data-lucide="calendar-check"></i> Reservations</a>
    <a href="{{ route($area . '.payments.index', ['type' => 'in', 'member_id' => $member->id]) }}" target="_blank" class="btn btn-secondary"><i data-lucide="wallet"></i> Payments</a>
    {{-- only when the member has packages (2026-11-18): the packages list of this member --}}
    @if ($packages > 0)
        @can('packages.view')
            <a href="{{ route($area . '.packages.index', ['member_id' => $member->id]) }}" target="_blank" class="btn btn-secondary"><i data-lucide="package"></i> Packages ({{ $packages }})</a>
        @endcan
    @endif
    <a href="{{ route($area . '.members.edit', $member->id) }}" data-modal class="btn btn-secondary"><i data-lucide="pencil"></i> Edit</a>
    {{-- delete: the account only (a member is deleted for good) --}}
    @if ($area === 'account')
        @include('membermodule::Account.partials.delete-form')
    @endif
@endsection

@section('content')
    {{-- left: the name + phone; right: Confirmed | Cancelled reservations (the package view's block, 2026-10-27) --}}
    <div class="flex flex-wrap items-center gap-4">
        <div>
            <div class="text-lg font-medium">{{ $member->name }}</div>
            <div class="mt-1 text-sm text-slate-500">{{ $member->phone }}</div>
            {{-- the commitment under the phone (2026-11-12) --}}
            @if ($confirmed + $cancelled > 0)
                <div class="mt-1 text-sm text-slate-500">
                    @include('membermodule::partials.commitment', ['total' => $confirmed + $cancelled, 'cancelled' => $cancelled, 'withText' => true])</div>
            @endif
        </div>
        <div class="money-box money-box--center ml-auto">
            <div class="money-box__item money-box__item--paid" title="Reservations not cancelled">
                <span>Confirmed</span><b>{{ $confirmed }}</b>
            </div>
            <div @class(['money-box__item', 'money-box__item--owed' => $cancelled > 0]) title="Cancelled reservations">
                <span>Cancelled</span><b>{{ $cancelled }}</b>
            </div>
        </div>
    </div>

    {{-- only the fields that have a value (2026-11-17, user), 2 columns; Address / Source / Notes on full rows --}}
    @php
        $dataFields = array_filter([
            'Email' => $member->email,
            ($member->id_type_label ?? 'ID Number') => $member->national_number,
            'Nationality' => $member->nationality,
            'Company' => $member->company?->name,
            'Job' => $member->job?->name,
            'WhatsApp Language' => $member->message_language ? config('messagingmodule.languages.' . $member->message_language) : null,
            'Added At' => $member->created_at?->format('Y-m-d H:i'),
        ], fn ($value) => filled($value));
        $wideFields = array_filter(['Address' => $member->address, 'Source (from where)' => $member->from_where, 'Notes' => $member->notes], fn ($value) => filled($value));
    @endphp
    <div class="form-section mt-6">Member Data</div>
    <dl class="detail-list detail-list--two">
        @foreach ($dataFields as $label => $value)
            <div><dt>{{ $label }}</dt><dd dir="auto">{{ $value }}</dd></div>
        @endforeach
        @foreach ($wideFields as $label => $value)
            <div class="wide"><dt>{{ $label }}</dt><dd dir="auto" class="whitespace-pre-line">{{ $value }}</dd></div>
        @endforeach
    </dl>

    {{-- every payment of the member (owner AND employees, whoever recorded it) + due / paid / remaining (2026-11-01) --}}
    @include('paymentmodule::Payment.partials.member-payments', ['member' => $member])

    @unless (request()->ajax())
        <div class="form-actions">
            <a href="{{ route($area . '.members.index') }}" class="btn btn-secondary"><i data-lucide="arrow-left"></i> Back to Members</a>
        </div>
    @endunless
@endsection
