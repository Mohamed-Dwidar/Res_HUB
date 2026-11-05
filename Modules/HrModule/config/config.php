<?php

// HrModule (docs/hr-plan.md): the choice lists of the staff file. Nothing legal / numeric here (that is PayrollModule's dated rule sets).
return [
    'name' => 'HrModule',

    'statuses' => [
        'active' => 'Active',
        'on_leave' => 'On leave',
        'resigned' => 'Resigned',
        'terminated' => 'Terminated',
    ],

    // the statuses that end the work (the Staff list "Current" filter leaves them out, the status form offers Mark Left)
    'ended_statuses' => ['resigned', 'terminated'],

    'id_types' => [
        'national_id' => 'National ID',
        'passport' => 'Passport',
    ],

    'marital_statuses' => [
        'single' => 'Single',
        'married' => 'Married',
        'divorced' => 'Divorced',
        'widowed' => 'Widowed',
    ],

    // males only
    'military_statuses' => [
        'completed' => 'Completed',
        'exempt' => 'Exempt',
        'postponed' => 'Postponed',
        'not_due' => 'Not due yet',
    ],

    'pay_methods' => [
        'cash' => 'Cash',
        'bank' => 'Bank transfer',
        'wallet' => 'Wallet',
    ],

    // staff document files (private disk hr/{account}/{staff}/), KB
    'document_max_kb' => 10240,
];
