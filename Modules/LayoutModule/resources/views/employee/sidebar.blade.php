@php
    /*
     * The employee sidebar (2026-10-13): their daily pages only, direct links (Members since 2026-10-14; order + Payments = Cash In & Cash Out, no Expenses item: 2026-10-14;
     * Schedule first instead of Dashboard: 2026-10-14);
     * Change Password stays in the header menu (employee/main $menuLinks).
     * Roles (docs/roles-plan.md, R0): each item has its permission ('can'), an item the role lacks is left out.
     */
    $items = [
        ['divider' => 'Daily'],
        // Schedule (was Today, 2026-10-16) = the employee's home page (2026-10-14); no Dashboard item (employee.dashboard = their profile: header menu "My Profile")
        [
            'title' => 'Schedule',
            'can' => 'schedule.view',
            'icon' => 'calendar-days',
            'url' => route('employee.schedule.index'),
            'active' => request()->routeIs('employee.schedule.*'),
        ],
        [
            'title' => 'Reservations',
            'can' => 'reservations.view',
            'icon' => 'calendar-check',
            'url' => route('employee.reservations.index'),
            'active' => request()->routeIs('employee.reservations.*'),
        ],
        // Payments before Packages (user 2026-10-22)
        [
            'title' => 'Payments',
            'can' => 'payments.view',
            'icon' => 'wallet',
            'url' => route('employee.payments.index'),
            // Cash In + Cash Out (2026-10-14): the expense pages too (/expenses → the Cash Out tab)
            'active' => request()->routeIs('employee.payments.*', 'employee.expenses.*'),
        ],
        [
            'title' => 'Packages',
            'can' => 'packages.view',
            'icon' => 'package',
            'url' => route('employee.packages.index'),
            'active' => request()->routeIs('employee.packages.*'),
        ],
        [
            'title' => 'Members',
            'can' => 'members.view',
            'icon' => 'users',
            'url' => route('employee.members.index'),
            'active' => request()->routeIs('employee.members.*'),
        ],
        // ContractModule (Phase 2): the account's contracts (made from a reservation)
        [
            'title' => 'Contracts',
            'can' => 'contracts.view',
            'icon' => 'file-text',
            'url' => route('employee.contracts.index'),
            'active' => request()->routeIs('employee.contracts.*'),
        ],
        // My Shift after Members (user 2026-10-22)
        [
            'title' => 'My Shift',
            'can' => 'shift.has',
            'icon' => 'lock',
            'url' => route('employee.shift.index'),
            'active' => request()->routeIs('employee.shift.*'),
        ],
        // HrModule (docs/hr-plan.md H1): an HR role only (hr.view); the badge = expired + expiring documents (documents.view)
        [
            'title' => 'Staff',
            'can' => 'hr.view',
            'icon' => 'contact',
            'url' => route('employee.hr.staff.index'),
            'active' => request()->routeIs('employee.hr.staff.*'),
            'badge' => Gate::allows('documents.view')
                ? (app(\Modules\HrModule\app\Services\StaffService::class)->alertCount(Auth::user()->userable->ownerAccountId()) ?: null) : null,
        ],
        // PayrollModule H5: preparing drafts (hr.salary); lock / pay need payroll.approve / payroll.pay
        [
            'title' => 'Payroll',
            'can' => 'hr.salary',
            'icon' => 'wallet',
            'url' => route('employee.payroll.runs.index'),
            'active' => request()->routeIs('employee.payroll.runs.*'),
        ],
    ];
    $items = array_values(array_filter($items, fn ($item) => empty($item['can']) || Gate::allows($item['can'])));
    // nothing left under "Daily": no divider either
    $items = count($items) > 1 ? $items : [];
    // ReportModule (docs/reports-plan.md, 2026-11-06): a "Reports" section with the reports the role has
    $reports = array_values(array_filter([
        [
            'title' => 'Outstanding & Credits',
            'can' => 'reports.outstanding',
            'icon' => 'scale',
            'url' => route('employee.reports.outstanding'),
            'active' => request()->routeIs('employee.reports.outstanding*'),
        ],
        [
            'title' => 'Member Statement',
            'can' => 'reports.member_statement',
            'icon' => 'scroll-text',
            'url' => route('employee.reports.member-statement'),
            'active' => request()->routeIs('employee.reports.member-statement*'),
        ],
        [
            'title' => 'Income',
            'can' => 'reports.income',
            'icon' => 'trending-up',
            'url' => route('employee.reports.income'),
            'active' => request()->routeIs('employee.reports.income*'),
        ],
        [
            'title' => 'Expenses',
            'can' => 'reports.expenses',
            'icon' => 'trending-down',
            'url' => route('employee.reports.expenses'),
            'active' => request()->routeIs('employee.reports.expenses*'),
        ],
    ], fn ($item) => Gate::allows($item['can'])));
    if ($reports) {
        $items = array_merge($items, [['divider' => 'Reports']], $reports);
    }
@endphp

@include('layoutmodule::partials.menu', ['items' => $items])
