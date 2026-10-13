<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WalletTransaction extends Model
{
    protected $fillable = [
        'affiliate_id',
        'transaction_no',
        'type',
        'description',
        'amount',
        'status',
        'reference_type',
        'reference_id',
        'remarks',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
