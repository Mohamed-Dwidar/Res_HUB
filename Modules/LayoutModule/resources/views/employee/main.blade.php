{{-- Employee layout (an employee of an account: he sees his profile and changes his password) --}}
@extends('layoutmodule::main')

@php($authUser = Auth::user())

@section('header')
    @include('layoutmodule::header', [
        // the Schedule, else the first page his role opens (Employee::homeRoute(), docs/roles-plan.md)
        'homeUrl' => route($authUser->userable->homeRoute()),
        'userName' => $authUser->userable->displayName(),
        'userSubtitle' => $authUser->email,
        'menuLinks' => [
            ['label' => 'My Profile', 'icon' => 'user', 'url' => route('employee.dashboard')],
            ['label' => 'Change Password', 'icon' => 'key-round', 'url' => route('user.account.edit')],
        ],
        'logoutUrl' => route('logout'),
        // payments not closed (PaymentModule ShiftReminder middleware): the logout asks to close the shift first
        'logoutPrompt' => !empty($shiftOpen) ? route('employee.shift.logout-prompt') : null,
    ])
@endsection

{{-- the shift is open too long (ShiftReminder middleware): custom.js opens the "close your shift" popup --}}
@if (!empty($shiftReminderUrl))
    @push('scripts')
        <div hidden data-shift-reminder="{{ $shiftReminderUrl }}"></div>
    @endpush
@endif

@section('sidebar')
    @include('layoutmodule::sidebar', ['menu' => 'layoutmodule::employee.sidebar'])
@endsection
