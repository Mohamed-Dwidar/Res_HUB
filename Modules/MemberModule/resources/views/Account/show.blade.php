@php($area = request()->routeIs('employee.*') ? 'employee' : 'account')
@extends(request()->ajax() ? 'layoutmodule::modal' : Auth::user()->userable->layout())

@section('title')
    Member Details
@endsection

@section('modal-size', 'lg')

@section('actions')
    {{-- the member's lists (as in the Members list row menu; Payments = the account only), in a new tab --}}
    <a href="{{ route($area . '.reservations.index', ['member_id' => $member->id]) }}" target="_blank" class="btn btn-secondary"><i data-lucide="calendar-check"></i> Reservations</a>
    @if ($area === 'account')
        <a href="{{ route('account.payments.index', ['type' => 'in', 'member_id' => $member->id]) }}" target="_blank" class="btn btn-secondary"><i data-lucide="wallet"></i> Payments</a>
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

    <div class="form-section mt-6">Member Data</div>
    <dl class="detail-list">
        <div><dt>Email</dt><dd>{{ $member->email ?: '-' }}</dd></div>
        <div><dt>National Number</dt><dd>{{ $member->national_number ?: '-' }}</dd></div>
        <div><dt>Company</dt><dd>{{ $member->company?->name ?? '-' }}</dd></div>
        <div><dt>Job</dt><dd>{{ $member->job?->name ?? '-' }}</dd></div>
        <div><dt>WhatsApp Language</dt><dd>{{ config('messagingmodule.languages.' . $member->message_language, 'Account default') }}</dd></div>
        <div><dt>Added At</dt><dd>{{ $member->created_at->format('Y-m-d H:i') }}</dd></div>
        <div class="wide"><dt>Source (from where)</dt><dd>{{ $member->from_where ?: '-' }}</dd></div>
        <div class="wide"><dt>Notes</dt><dd>{{ $member->notes ?: '-' }}</dd></div>
    </dl>

    @unless (request()->ajax())
        <div class="form-actions">
            <a href="{{ route($area . '.members.index') }}" class="btn btn-secondary"><i data-lucide="arrow-left"></i> Back to Members</a>
        </div>
    @endunless
@endsection
