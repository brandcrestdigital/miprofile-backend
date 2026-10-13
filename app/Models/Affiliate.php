<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;

class Affiliate extends Authenticatable implements JWTSubject
{
    use Notifiable;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'status',
        'approved_at',
        'approved_by',
        'rejection_remarks',
        'commission',
        'type',
        'is_active',
        'affiliate_code',

    ];

    protected $hidden = [
        'password',
        'remember_token',
        'is_active'   => 'boolean',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
    ];

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims(): array
    {
        return [];
    }
}
