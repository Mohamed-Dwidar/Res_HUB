<?php

return [
    'name' => 'EmployeeModule',

    // the Access Log events (access_logs.event) and their labels (docs/roles-plan.md); exports / sensitive actions from R1
    'access_events' => [
        'login' => 'Login',
        'login_failed' => 'Failed login',
        'logout' => 'Logout',
        'timeout' => 'Idle timeout',
        'export' => 'Export',
        'action' => 'Sensitive action',
        // HR (docs/hr-plan.md): an employee opening a staff page; any download of an HR file (the owner's too)
        'view' => 'Viewed',
        'download' => 'Download',
    ],

    /*
     * Roles (docs/roles-plan.md): the permission catalog, by group = the Settings › Roles checklist. A key is checked by the
     * Gate (routes `can:`, views @can, $user->can()); the owner (Account) passes every one. R0 = what employees can do today;
     * later phases add their keys (a new key is OFF in the existing roles: its migration adds it where needed).
     */
    'permissions' => [
        'Schedule' => [
            'schedule.view' => 'See the Schedule page',
        ],
        'Reservations' => [
            'reservations.view' => 'See reservations (list, view)',
            'reservations.create' => 'Add reservations',
            'reservations.edit' => 'Edit reservations',
            'reservations.delete' => 'Delete reservations (without payments)',
            'reservations.status' => 'Change the status / restore a cancelled reservation',
            'reservations.renew' => 'Renew subscriptions',
            'reservations.mark_left' => 'Mark left / undo left',
        ],
        'Packages' => [
            'packages.view' => 'See packages (list, view)',
            'packages.create' => 'Add packages',
            'packages.edit' => 'Edit packages',
            'packages.delete' => 'Delete packages (without reservations / payments)',
            'packages.statement' => 'Export the package statement PDF',
            'extras.create' => 'Add extras to a package / reservation',
        ],
        'Cash In' => [
            'payments.view' => 'See the Payments page (own cash in, one day) and the payments of a reservation / package / member',
            'payments.create' => 'Add cash in / withholding',
            'payments.receipt' => 'Print receipts',
            'payments.refund' => 'Refund a cash in',
            'payments.edit_own_open' => 'Edit own cash in while the shift is open',
            // R1 (2026-11-03): two separate "see more" keys, never in Front desk (PaymentModule CashScope)
            'payments.date_range' => 'See own cash in over any dates (date range + Today / This month / All)',
            'payments.view_all' => 'See all cash in — all employees, any date',
        ],
        'Cash Out' => [
            'expenses.view' => 'See own cash out (Payments page All / Cash Out tabs)',
            'expenses.create' => 'Add cash out',
            'expenses.edit_own_open' => 'Edit own cash out while the shift is open',
            'expenses.date_range' => 'See own cash out over any dates (date range + Today / This month / All)',
            'expenses.view_all' => 'See all cash out — all employees, any date',
        ],
        'Shift' => [
            'shift.has' => 'Collects money: My Shift, close the shift, reminders, logout prompt',
        ],
        'Members' => [
            'members.view' => 'See members (list, view)',
            'members.create' => 'Add members (also the quick add in the forms)',
            'members.edit' => 'Edit members',
        ],
        'WhatsApp' => [
            'messages.send' => 'Send WhatsApp messages',
        ],
        'Contracts' => [
            'contracts.view' => 'See contracts (list, view, signed scan)',
            'contracts.draft' => 'Create / edit contract drafts',
            'contracts.issue' => 'Issue contracts',
            'contracts.upload_signed' => 'Upload the signed contract',
            'contracts.delete_own_draft' => 'Delete own drafts',
            'contracts.pdf' => 'Contract PDF',
        ],
        // HR (docs/hr-plan.md, H1 2026-11-04): never in Front desk; views / downloads are written in the Access Log
        'HR' => [
            'hr.view' => 'See staff files (list + profile, without ID / bank / emergency data)',
            'hr.sensitive' => 'See and edit ID, birth date, address, bank / wallet and emergency contact',
            'hr.manage' => 'Add / edit staff files, change their status, link a login',
            'documents.view' => 'See and download staff documents',
            'documents.manage' => 'Add / edit / delete staff documents',
            // H2 (2026-11-05): salaries + social insurance (H4: advances, bonuses / deductions)
            'hr.salary' => 'See and edit salaries and social insurance',
        ],
        // H5 payroll (2026-11-06): preparing a draft = hr.salary; locking / paying = these two (no preset: the owner only for now)
        'Payroll' => [
            'payroll.approve' => 'Lock / reopen a month\'s payroll (salaries frozen, advances deducted)',
            'payroll.pay' => 'Pay salaries from a locked payroll (company Cash Out)',
        ],
        // ReportModule (docs/reports-plan.md, 2026-11-06): one key per report = the whole account's data + its Excel / PDF;
        // no preset, never in Front desk
        'Reports' => [
            'reports.outstanding' => 'Report: Outstanding & credits',
            'reports.member_statement' => 'Report: Member statement',
            'reports.income' => 'Report: Income',
            'reports.expenses' => 'Report: Expenses',
            'reports.subscriptions' => 'Report: Subscriptions',
            'reports.occupancy' => 'Report: Occupancy',
        ],
        // 2026-11-04 (user): logins are made / changed / switched off ONLY from the staff form's System Access section
        'System Access' => [
            'users.manage' => 'Turn system access on / off, set the login email / password / role (staff form)',
        ],
    ],

    // a ticked key brings these with it (the role save adds them)
    'permission_requires' => [
        'reservations.create' => ['reservations.view'],
        'reservations.edit' => ['reservations.view'],
        'reservations.delete' => ['reservations.view'],
        'reservations.status' => ['reservations.view'],
        'reservations.renew' => ['reservations.view'],
        'reservations.mark_left' => ['reservations.view'],
        'packages.create' => ['packages.view'],
        'packages.edit' => ['packages.view'],
        'packages.delete' => ['packages.view'],
        'packages.statement' => ['packages.view'],
        'extras.create' => ['packages.view'],
        'payments.create' => ['payments.view', 'shift.has'],
        'payments.receipt' => ['payments.view'],
        'payments.refund' => ['payments.view', 'shift.has'],
        'payments.edit_own_open' => ['payments.view'],
        'payments.date_range' => ['payments.view'],
        'payments.view_all' => ['payments.view'],
        'expenses.view' => ['payments.view'],
        'expenses.date_range' => ['expenses.view'],
        'expenses.view_all' => ['expenses.view'],
        'expenses.create' => ['expenses.view', 'shift.has'],
        'expenses.edit_own_open' => ['expenses.view'],
        'members.create' => ['members.view'],
        'members.edit' => ['members.view'],
        'messages.send' => ['reservations.view'],
        'contracts.draft' => ['contracts.view'],
        'contracts.issue' => ['contracts.view'],
        'contracts.upload_signed' => ['contracts.view'],
        'contracts.delete_own_draft' => ['contracts.view'],
        'contracts.pdf' => ['contracts.view'],
        'hr.sensitive' => ['hr.view'],
        'hr.manage' => ['hr.view'],
        'documents.view' => ['hr.view'],
        'documents.manage' => ['documents.view'],
        'hr.salary' => ['hr.view'],
        'payroll.approve' => ['hr.salary'],
        'payroll.pay' => ['hr.salary'],
        'users.manage' => ['hr.manage'],
    ],

    // Settings › Roles "Start from" (R2 adds the Accountant presets); front_desk = the default role's list (also the fallback
    // when an account has no default role yet) — keep it = the list in the 2026_11_03_100030 migration
    'role_presets' => [
        'front_desk' => [
            'name' => 'Front desk',
            'permissions' => [
                'schedule.view',
                'reservations.view', 'reservations.create', 'reservations.edit', 'reservations.delete', 'reservations.status',
                'reservations.renew', 'reservations.mark_left',
                'packages.view', 'packages.create', 'packages.edit', 'packages.delete', 'packages.statement', 'extras.create',
                'payments.view', 'payments.create', 'payments.receipt', 'payments.refund', 'payments.edit_own_open',
                'expenses.view', 'expenses.create', 'expenses.edit_own_open',
                'shift.has',
                'members.view', 'members.create', 'members.edit',
                'messages.send',
                'contracts.view', 'contracts.draft', 'contracts.issue', 'contracts.upload_signed', 'contracts.delete_own_draft',
                'contracts.pdf',
            ],
        ],
        'empty' => [
            'name' => 'Nothing ticked',
            'permissions' => [],
        ],
    ],

    // an employee's first page when he can't see the Schedule: the first of these he may open (sidebar order)
    'home_pages' => [
        'schedule.view' => 'employee.schedule.index',
        'reservations.view' => 'employee.reservations.index',
        'payments.view' => 'employee.payments.index',
        'packages.view' => 'employee.packages.index',
        'members.view' => 'employee.members.index',
        'contracts.view' => 'employee.contracts.index',
        'shift.has' => 'employee.shift.index',
        'hr.view' => 'employee.hr.staff.index',
    ],
];
