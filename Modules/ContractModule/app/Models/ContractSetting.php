<?php

namespace Modules\ContractModule\app\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Settings › Contracts of an account: the lessor data printed on its contracts + number_start (one row, created on the first save;
 * ContractService::settings() gives the defaults until then).
 */
class ContractSetting extends Model
{
    protected $fillable = [
        'account_id', 'lessor_name_ar', 'lessor_name_en', 'commercial_register', 'tax_number', 'representative_name',
        'representative_title', 'representative_national_id', 'property_address_ar', 'property_address_en', 'number_start',
    ];

    protected $casts = [
        'number_start' => 'integer',
    ];
}
