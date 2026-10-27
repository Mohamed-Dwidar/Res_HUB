<?php

return [
    'name' => 'PaymentModule',

    // the first id / payment_no of the payments added in the app: the numbers below are kept for the old data import
    // (old payments / incomes keep their old ids); the payments AUTO_INCREMENT is set to the same value by hand (to_run)
    'number_start' => (int) env('PAYMENT_NUMBER_START', 100001),

    // employees' shift reminders (docs/payments-plan.md §2b): the oldest payment of an open shift older than this → the
    // "close your shift" popup; after "I'm still working" (a reason is required) it comes back after the snooze
    'shift_reminder_hours' => 12,
    'shift_snooze_hours' => 4,

    // the ETA tax codes (docs/payments-plan.md §1; the rates are in Settings › General › Taxes): VAT standard, VAT exempt
    // (null until the accountant gives the exempt subtype), table tax %
    'tax' => [
        'vat_type' => 'T1',
        'vat_subtype' => 'V009',
        'exempt_subtype' => null,
        'table_type' => 'T2',
        'table_subtype' => 'Tbl01',
    ],
];
