<?php

return [
    'name' => 'PaymentModule',

    // the first id / payment_no of the payments added in the app: the numbers below are kept for the old data import
    // (old payments / incomes keep their old ids); the payments AUTO_INCREMENT is set to the same value by hand (to_run)
    // the system's expense categories of the company Cash Out (docs/hr-plan.md §6b; expense_categories.generated_key), made on
    // first use by ExpenseCategoryService::generated(): advances (H4), salaries (H5)
    'generated_categories' => [
        'advances' => ['en' => 'Advances', 'ar' => 'سلف'],
        'salaries' => ['en' => 'Salaries', 'ar' => 'مرتبات'],
    ],

    // the system's income categories (income_categories.generated_key, made on first use by IncomeCategoryService::generated()):
    // never deleted, not offered in Add Cash In; by default never income, not in Add Extra, their Cash In locked: a cash advance
    // repayment (HR H4, changed from HR) and VAT (manual) (#6 keyed by database/fixes/2026-11-08-vat-manual-system-category.sql);
    // flags: income = counts as income, extra = offered in Add Extra, untaxed = an extra of it never takes taxes
    // rounding (2026-11-10, payments:clean-overpayments): the small amount added so a reservation / package owes what was paid
    'generated_income_categories' => [
        'advance_repayment' => ['en' => 'Advance repayment', 'ar' => 'سداد سلفة'],
        'vat_manual' => ['en' => 'VAT (manual)', 'ar' => 'ضريبة القيمة المضافة (يدوي)'],
        'rounding' => ['en' => 'Rounding', 'ar' => 'تقريب', 'income' => true, 'extra' => true, 'untaxed' => true],
    ],

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
