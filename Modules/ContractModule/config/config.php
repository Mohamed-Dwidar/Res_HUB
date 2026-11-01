<?php

return [
    'name' => 'ContractModule',

    /*
     * The placeholders of a contract text, by group (docs/contracts-plan.md §3): {name} => what it is (the chip's tooltip).
     * A new one = here + ContractService::variables(). Values are HTML-escaped when they replace {name}.
     * Since 2026-11-01 the contract's OWN fields (the draft page) give the tenant / unit / term / value ones (the template page's
     * preview: the same defaults the draft page pre-fills).
     */
    'placeholders' => [
        'Contract' => [
            'contract_no' => 'Contract number',
            'contract_date' => 'The contract date (the contract\'s own, default: the day it is made)',
            'start_date' => 'The contract term starts',
            'end_date' => 'The contract term ends',
            'term' => 'The contract duration as written on the contract, e.g. سنة ميلادية',
            'unit_number' => 'The unit number as written on the contract',
            'contract_value' => 'The contract value (default: the price for the term)',
            'currency' => 'The currency of the value, e.g. جنيه مصري',
            'activity' => 'The tenant\'s activity in the unit (typed on the contract)',
            'deposit' => 'Security deposit (typed on the contract)',
            'previous_contract_no' => 'Renewal annex: the number of the contract it renews',
            'previous_contract_date' => 'Renewal annex: the date of the contract it renews',
        ],
        'Lessor' => [
            'lessor_name' => 'Lessor name (Settings › Contracts)',
            'lessor_phone' => 'The account\'s phone',
            'lessor_commercial_register' => 'Lessor commercial register',
            'lessor_tax_number' => 'Lessor tax registration number',
            'lessor_representative' => 'Lessor legal representative',
            'lessor_representative_title' => 'The representative\'s title',
            'lessor_representative_national_id' => 'The representative\'s national ID',
            'property_address' => 'The unit address on the contract (default: Settings › Contracts)',
            'unit_address' => 'The unit address on the contract (= {property_address})',
        ],
        'Tenant' => [
            'tenant_name' => 'The tenant\'s name on the contract (default: the member)',
            'tenant_representative' => 'The tenant\'s legal representative',
            'tenant_company' => 'The tenant\'s company name',
            'tenant_title' => 'The representative\'s title (الصفة)',
            'tenant_national_id' => 'The tenant\'s national ID / ID number',
            'tenant_mobile' => 'The tenant\'s mobile',
            'tenant_email' => 'The tenant\'s email',
            'member_name' => 'Member name',
            'member_phone' => 'Member phone',
            'member_email' => 'Member email',
            'member_id_type' => 'National ID / Passport',
            'member_id_number' => 'The member\'s ID number',
            'member_nationality' => 'Member nationality',
            'member_address' => 'Member address',
            'member_job' => 'Member job',
            'company_name' => 'Company name',
            'company_commercial_register' => 'Company commercial register',
            'company_tax_number' => 'Company tax number',
            'company_address' => 'Company address',
            'company_representative' => 'Company legal representative',
            'company_representative_title' => 'The representative\'s title',
            'company_representative_national_id' => 'The representative\'s national ID',
        ],
        'Place' => [
            'space' => 'Space',
            'unit' => 'Unit',
            'unit_description' => 'The unit\'s description',
            'capacity' => 'The unit\'s capacity (persons)',
            'plan' => 'Plan',
            'lease_period' => 'The plan\'s lease period (month, year …)',
        ],
        'Money' => [
            'price' => 'The price of one period (after discount)',
            'term_amount' => 'The price for the whole term',
            'billing_day' => 'The day of the month a period renews',
            'renewal_date' => 'The next renewal date of the reservation',
        ],
        'Taxes' => [
            'net_before_tax' => 'Net before tax (only with "Invoice requested")',
            'vat_rate' => 'VAT %',
            'vat' => 'VAT amount',
            'table_tax_rate' => 'Table tax %',
            'table_tax' => 'Table tax amount',
            'total_with_tax' => 'Total with taxes',
        ],
    ],

    // the term choices of a template (months); NULL = the reservation's own period
    'terms' => [1, 3, 6, 12, 24, 36],

    // the default currency label of a new contract's value (editable per contract)
    'currency' => 'جنيه مصري',

    // the Contracts list "Ending soon" filter: issued / signed contracts ending in the next N days
    'ending_days' => 30,
];
