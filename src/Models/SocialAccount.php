<?php

namespace CoderManjeet\SocialAutomation\Models;

use Illuminate\Database\Eloquent\Model;

class SocialAccount extends Model
{
    protected $fillable = [
        'platform',
        'account_id',
        'name',
        'token',
        'expires_at',
        'meta',
        'connected_by',
        'connected_at',
    ];

    protected $casts = [
        'token' => 'encrypted',
        'expires_at' => 'datetime',
        'meta' => 'array',
        'connected_at' => 'datetime',
    ];
}
