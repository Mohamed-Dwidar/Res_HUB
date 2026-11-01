@php
    /*
     * The employee sidebar (2026-10-13): their daily pages only, direct links (Members since 2026-10-14; order + Payments = Cash In & Cash Out, no Expenses item: 2026-10-14;
     * Schedule first instead of Dashboard: 2026-10-14);
     * Change Password stays in the header menu (employee/main $menuLinks).
     */
    $items = [
        ['divider' => 'Daily'],
        // Schedule (was Today, 2026-10-16) = the employee's home page (2026-10-14); no Dashboard item (employee.dashboard = their profile: header menu "My Profile")
        [
            'title' => 'Schedule',
            'icon' => 'calendar-days',
            'url' => route('employee.schedule.index'),
            'active' => request()->routeIs('employee.schedule.*'),
        ],
        [
            'title' => 'Reservations',
            'icon' => 'calendar-check',
            'url' => route('employee.reservations.index'),
            'active' => request()->routeIs('employee.reservations.*'),
        ],
        // Payments before Packages (user 2026-10-22)
        [
            'title' => 'Payments',
            'icon' => 'wallet',
            'url' => route('employee.payments.index'),
            // Cash In + Cash Out (2026-10-14): the expense pages too (/expenses → the Cash Out tab)
            'active' => request()->routeIs('employee.payments.*', 'employee.expenses.*'),
        ],
        [
            'title' => 'Packages',
            'icon' => 'package',
            'url' => route('employee.packages.index'),
            'active' => request()->routeIs('employee.packages.*'),
        ],
        [
            'title' => 'Members',
            'icon' => 'users',
            'url' => route('employee.members.index'),
            'active' => request()->routeIs('employee.members.*'),
        ],
        // ContractModule (Phase 2): the account's contracts (made from a reservation)
        [
            'title' => 'Contracts',
            'icon' => 'file-text',
            'url' => route('employee.contracts.index'),
            'active' => request()->routeIs('employee.contracts.*'),
        ],
        // My Shift after Members (user 2026-10-22)
        [
            'title' => 'My Shift',
            'icon' => 'lock',
            'url' => route('employee.shift.index'),
            'active' => request()->routeIs('employee.shift.*'),
        ],
    ];
@endphp

@include('layoutmodule::partials.menu', ['items' => $items])
