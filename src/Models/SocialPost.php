<?php

namespace CoderManjeet\SocialAutomation\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use CoderManjeet\SocialAutomation\SocialAutomation;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class SocialPost extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $fillable = [
        'source',
        'post_id',
        'type',
        'status',
        'captions',
        'platforms',
        'link_url',
        'scheduled_at',
        'review_notes',
        'created_by',
        'approved_by',
        'approved_at',
        'published_at',
    ];

    protected $casts = [
        'captions' => 'array',
        'platforms' => 'array',
        'scheduled_at' => 'datetime',
        'approved_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    public function post(): BelongsTo
    {
        return $this->belongsTo(SocialAutomation::model('post'), 'post_id');
    }

    public function targets(): HasMany
    {
        return $this->hasMany(SocialPostTarget::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('images');
    }

    public function captionFor(string $platform): ?string
    {
        $caption = $this->captions[$platform] ?? null;

        return is_string($caption) && trim($caption) !== '' ? $caption : null;
    }

    /**
     * The article link with the social UTM parameters for this platform.
     */
    public function linkFor(string $platform): ?string
    {
        if (! is_string($this->link_url) || $this->link_url === '') {
            return null;
        }

        if (! config('content-automation.social.utm', true)) {
            return $this->link_url;
        }

        return self::withUtm($this->link_url, $platform);
    }

    public static function withUtm(string $url, string $platform): string
    {
        $query = http_build_query([
            'utm_source' => $platform,
            'utm_medium' => 'social',
            'utm_campaign' => 'blog',
        ]);

        return $url.(str_contains($url, '?') ? '&' : '?').$query;
    }

    /**
     * @return list<string>
     */
    public function imageFiles(): array
    {
        /** @var Collection<int, Media> $media */
        $media = $this->source === 'blog_share'
            ? ($this->post?->getMedia('featured_image') ?? new Collection)
            : $this->getMedia('images');

        return $media
            ->sortBy('order_column')
            ->map(fn (Media $item): string => $item->getPath())
            ->filter(fn (string $path): bool => is_file($path))
            ->values()
            ->all();
    }
}
