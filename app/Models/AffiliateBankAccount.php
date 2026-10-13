<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AffiliateBankAccount extends Model
{
    protected $fillable = [
        'affiliate_id',
        'account_holder_name',
        'account_number',
        'ifsc_code',
        'bank_name',
        'branch_name',
        'upi_id',
        'status',
        'admin_remark',
        'submitted_at',
        'approved_at',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
    ];
}
