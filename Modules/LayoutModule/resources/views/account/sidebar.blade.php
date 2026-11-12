@php
    /*
     * The account sidebar, by how often things are used (2026-10-13; Daily order + Payments = Cash In & Cash Out: 2026-10-14;
     * Schedule (was Today, 2026-10-16) under Dashboard: 2026-10-14):
     * Daily (direct links) | Management | Settings (one group,
     * collapsed unless the current page is in it — partials/menu opens a group with an active child).
     * Settings › General: withholding tax off → no Withholding Certificates item.
     */
    $withholdingOn = Auth::user()->userable->withholdingEnabled();
    // HrModule (docs/hr-plan.md H1): expired + expiring staff documents
    $hrAlerts = app(\Modules\HrModule\app\Services\StaffService::class)->alertCount(Auth::user()->userable_id);
    $items = [
        ['divider' => 'Daily'],
        [
            'title' => 'Dashboard',
            'icon' => 'gauge-circle',
            'url' => route('account.dashboard'),
            'active' => request()->routeIs('account.dashboard'),
        ],
        [
            'title' => 'Schedule',
            'icon' => 'calendar-days',
            'url' => route('account.schedule.index'),
            'active' => request()->routeIs('account.schedule.*'),
        ],
        [
            'title' => 'Reservations',
            'icon' => 'calendar-check',
            'url' => route('account.reservations.index'),
            'active' => request()->routeIs('account.reservations.*'),
        ],
        // Payments before Packages (user 2026-10-22)
        [
            'title' => 'Payments',
            'icon' => 'wallet',
            'url' => route('account.payments.index'),
            // Cash In + Cash Out (2026-10-14): the expense pages too (/expenses → the Cash Out tab)
            'active' => request()->routeIs('account.payments.*', 'account.expenses.*'),
        ],
        [
            'title' => 'Packages',
            'icon' => 'package',
            'url' => route('account.packages.index'),
            'active' => request()->routeIs('account.packages.*'),
        ],
        [
            'title' => 'Members',
            'icon' => 'users',
            'url' => route('account.members.index'),
            'active' => request()->routeIs('account.members.*') && !request()->routeIs('account.members.create'),
        ],
        // My Shift after Members (user 2026-10-22)
        [
            'title' => 'My Shift',
            'icon' => 'lock',
            'url' => route('account.shift.index'),
            'active' => request()->routeIs('account.shift.*'),
        ],

        ['divider' => 'Management'],
        [
            'title' => 'Daily Summary',
            'icon' => 'calendar-days',
            'url' => route('account.daily-summary.index'),
            'active' => request()->routeIs('account.daily-summary.*', 'account.shifts.*'),
        ],
        [
            'title' => 'Payment Exceptions',
            'icon' => 'shield-alert',
            'url' => route('account.payment-exceptions.index'),
            'active' => request()->routeIs('account.payment-exceptions.*'),
        ],
        // ContractModule (Phase 2): the contracts (made from a reservation); Settings › Contracts = the templates
        [
            'title' => 'Contracts',
            'icon' => 'file-signature',
            'url' => route('account.contracts.index'),
            'active' => request()->routeIs('account.contracts.*'),
        ],
        ...($withholdingOn ? [[
            'title' => 'Withholding Certificates',
            'icon' => 'file-badge',
            'url' => route('account.withholding-certificates.index'),
            'active' => request()->routeIs('account.withholding-certificates.*'),
        ]] : []),

        // ReportModule (docs/reports-plan.md, 2026-11-06): one item per report
        ['divider' => 'Reports'],
        [
            'title' => 'Financial Dashboard',
            'icon' => 'pie-chart',
            'url' => route('account.reports.dashboard'),
            'active' => request()->routeIs('account.reports.dashboard*'),
        ],
        [
            'title' => 'Outstanding & Credits',
            'icon' => 'scale',
            'url' => route('account.reports.outstanding'),
            'active' => request()->routeIs('account.reports.outstanding*'),
        ],
        [
            'title' => 'Member Statement',
            'icon' => 'scroll-text',
            'url' => route('account.reports.member-statement'),
            'active' => request()->routeIs('account.reports.member-statement*'),
        ],
        [
            'title' => 'Income',
            'icon' => 'trending-up',
            'url' => route('account.reports.income'),
            'active' => request()->routeIs('account.reports.income*'),
        ],
        [
            'title' => 'Expenses',
            'icon' => 'trending-down',
            'url' => route('account.reports.expenses'),
            'active' => request()->routeIs('account.reports.expenses*'),
        ],
        [
            'title' => 'Subscriptions',
            'icon' => 'repeat',
            'url' => route('account.reports.subscriptions'),
            'active' => request()->routeIs('account.reports.subscriptions*'),
        ],
        [
            'title' => 'Occupancy',
            'icon' => 'gauge',
            'url' => route('account.reports.occupancy'),
            'active' => request()->routeIs('account.reports.occupancy*'),
        ],
        [
            'title' => 'Profit & Loss',
            'icon' => 'scale',
            'url' => route('account.reports.pnl'),
            'active' => request()->routeIs('account.reports.pnl*'),
        ],
        [
            'title' => 'Members',
            'icon' => 'users',
            'url' => route('account.reports.members'),
            'active' => request()->routeIs('account.reports.members*'),
        ],

        // HrModule (docs/hr-plan.md, H1 2026-11-04): ONE Staff list + the staff page; later Payroll
        ['divider' => 'HR'],
        [
            'title' => 'Staff',
            'icon' => 'contact',
            'url' => route('account.hr.staff.index'),
            'active' => request()->routeIs('account.hr.staff.*'),
            'badge' => $hrAlerts ?: null,
        ],
        // PayrollModule H5 (2026-11-06): the month's payroll
        [
            'title' => 'Payroll',
            'icon' => 'wallet',
            'url' => route('account.payroll.runs.index'),
            'active' => request()->routeIs('account.payroll.runs.*'),
        ],
        // the HR setup lists (user 2026-11-05: moved here from Settings; hr.manage — the owner always has it; employees: later)
        ...(Gate::allows('hr.manage') ? [
            [
                'title' => 'Employee Departments',
                'icon' => 'network',
                'url' => route('account.hr.departments.index'),
                'active' => request()->routeIs('account.hr.departments.*'),
            ],
            [
                'title' => 'Employee Positions',
                'icon' => 'award',
                'url' => route('account.employee-positions.index'),
                'active' => request()->routeIs('account.employee-positions.*'),
            ],
            [
                'title' => 'Employee Shifts',
                'icon' => 'clock',
                'url' => route('account.employee-shifts.index'),
                'active' => request()->routeIs('account.employee-shifts.*'),
            ],
            [
                'title' => 'Leave Types',
                'icon' => 'calendar-days',
                'url' => route('account.hr.leave-types.index'),
                'active' => request()->routeIs('account.hr.leave-types.*'),
            ],
            [
                'title' => 'Document Types',
                'icon' => 'files',
                'url' => route('account.hr.document-types.index'),
                'active' => request()->routeIs('account.hr.document-types.*'),
            ],
            // PayrollModule setup (H2, 2026-11-05)
            [
                'title' => 'Allowance Types',
                'icon' => 'banknote',
                'url' => route('account.hr.allowance-types.index'),
                'active' => request()->routeIs('account.hr.allowance-types.*'),
            ],
            [
                'title' => 'Adjustment Types',
                'icon' => 'badge-percent',
                'url' => route('account.hr.adjustment-types.index'),
                'active' => request()->routeIs('account.hr.adjustment-types.*'),
            ],
            [
                'title' => 'Payroll Settings',
                'icon' => 'sliders-horizontal',
                'url' => route('account.hr.payroll-settings.edit'),
                'active' => request()->routeIs('account.hr.payroll-settings.*'),
            ],
        ] : []),

        ['divider' => 'Settings'],
        [
            'title' => 'Settings',
            'icon' => 'settings',
            'children' => [
                // 2026-11-04 (user): no Users page — HR › Staff is the one place for people (System Access in the staff form);
                // Roles and Access Log first in Settings
                // EmployeeModule (R0 2026-11-03, docs/roles-plan.md): what each user may do
                [
                    'title' => 'Roles',
                    'icon' => 'shield-check',
                    'url' => route('account.settings.roles.index'),
                    'active' => request()->routeIs('account.settings.roles.*'),
                ],
                // EmployeeModule (2026-11-03): the users' logins, timeouts, exports, HR views / downloads
                [
                    'title' => 'Access Log',
                    'icon' => 'scroll-text',
                    'url' => route('account.access-log.index'),
                    'active' => request()->routeIs('account.access-log.*'),
                ],
                [
                    'title' => 'General',
                    'icon' => 'sliders-horizontal',
                    'url' => route('account.settings.general.edit'),
                    'active' => request()->routeIs('account.settings.general.*'),
                ],
                // MessagingModule (2026-10-29): settings + the message texts
                [
                    'title' => 'WhatsApp Messages',
                    'icon' => 'message-circle',
                    'url' => route('account.settings.messages.edit'),
                    'active' => request()->routeIs('account.settings.messages.*'),
                ],
                // ContractModule (2026-10-31): the lessor data + the contract templates
                [
                    'title' => 'Contracts',
                    'icon' => 'file-text',
                    'url' => route('account.settings.contracts.edit'),
                    'active' => request()->routeIs('account.settings.contracts.*'),
                ],
                [
                    'title' => 'Spaces & Units',
                    'icon' => 'layout-grid',
                    'url' => route('account.spaces.index'),
                    // the units pages are under a space (account.spaces.units.*)
                    'active' => request()->routeIs('account.spaces.*'),
                ],
                [
                    'title' => 'Plans',
                    'icon' => 'receipt',
                    'url' => route('account.plans.index'),
                    'active' => request()->routeIs('account.plans.*'),
                ],
                [
                    'title' => 'Subscription Types',
                    'icon' => 'tags',
                    'url' => route('account.subscription-types.index'),
                    'active' => request()->routeIs('account.subscription-types.*'),
                ],
                [
                    'title' => 'Reservation Statuses',
                    'icon' => 'list-checks',
                    'url' => route('account.reservation-statuses.index'),
                    'active' => request()->routeIs('account.reservation-statuses.*'),
                ],
                [
                    'title' => 'Payment Methods',
                    'icon' => 'credit-card',
                    'url' => route('account.payment-methods.index'),
                    'active' => request()->routeIs('account.payment-methods.*'),
                ],
                [
                    'title' => 'Income Categories',
                    'icon' => 'tags',
                    'url' => route('account.income-categories.index'),
                    'active' => request()->routeIs('account.income-categories.*'),
                ],
                [
                    'title' => 'Expense Categories',
                    'icon' => 'tags',
                    'url' => route('account.expense-categories.index'),
                    'active' => request()->routeIs('account.expense-categories.*'),
                ],
                [
                    'title' => 'Companies',
                    'icon' => 'briefcase',
                    'url' => route('account.companies.index'),
                    'active' => request()->routeIs('account.companies.*') && !request()->routeIs('account.companies.create'),
                ],
                [
                    'title' => 'Jobs',
                    'icon' => 'clipboard-list',
                    'url' => route('account.jobs.index'),
                    'active' => request()->routeIs('account.jobs.*') && !request()->routeIs('account.jobs.create'),
                ],
            ],
        ],
    ];
@endphp

@include('layoutmodule::partials.menu', ['items' => $items])
