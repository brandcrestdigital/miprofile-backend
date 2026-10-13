<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashfreeOrder extends Model
{
    protected $fillable = [
        'organization_id',
        'cashfree_order_id',
        'cashfree_cf_order_id',
        'status',
        'order_data',
        'source',
    ];

    protected $casts = [
        'order_data' => 'array',
    ];
}
