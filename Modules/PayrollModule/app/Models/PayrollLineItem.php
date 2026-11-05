<?php

namespace Modules\PayrollModule\app\Models;

use Illuminate\Database\Eloquent\Model;

// what a payroll line is made of: kind earning | deduction | info (company insurance), source + source_id, label, days, amount
class PayrollLineItem extends Model
{
    protected $fillable = ['line_id', 'kind', 'source', 'source_id', 'label', 'quantity', 'amount'];

    protected $casts = ['quantity' => 'float', 'amount' => 'float', 'source_id' => 'integer'];
}
