<?php

namespace CoderManjeet\SocialAutomation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialPostTarget extends Model
{
    protected $fillable = [
        'social_post_id',
        'platform',
        'status',
        'remote_id',
        'remote_url',
        'attempts',
        'last_error',
        'sent_at',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'sent_at' => 'datetime',
    ];

    public function socialPost(): BelongsTo
    {
        return $this->belongsTo(SocialPost::class);
    }
}
