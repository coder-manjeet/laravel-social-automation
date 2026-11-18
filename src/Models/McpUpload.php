<?php

namespace CoderManjeet\SocialAutomation\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use CoderManjeet\SocialAutomation\SocialAutomation;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class McpUpload extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    protected $fillable = [
        'uuid',
        'user_id',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (McpUpload $upload): void {
            if (empty($upload->uuid)) {
                $upload->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * User who uploaded the temporary file.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(SocialAutomation::model('user'));
    }

    /**
     * Temporary files hold a single featured image until the post exists.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('featured_image')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
    }
}
