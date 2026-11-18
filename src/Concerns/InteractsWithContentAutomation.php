<?php

namespace CoderManjeet\SocialAutomation\Concerns;

use Illuminate\Database\Eloquent\Builder;
use CoderManjeet\SocialAutomation\Enums\PostSource;
use CoderManjeet\SocialAutomation\Enums\PostStatus;

trait InteractsWithSocialAutomation
{
    /**
     * Posts visible on the public site.
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', PostStatus::Published->value);
    }

    /**
     * Posts written by the Claude writer.
     */
    public function scopeFromClaude(Builder $query): void
    {
        $query->where('source', PostSource::Claude->value);
    }

    /**
     * Posts waiting for human review.
     */
    public function scopePendingReview(Builder $query): void
    {
        $query->where('status', PostStatus::PendingReview->value);
    }

    /**
     * Whether the MCP writer may still edit this post.
     *
     * Manual posts, published posts and approved posts are locked.
     */
    public function isEditableByMcp(): bool
    {
        if ($this->source !== PostSource::Claude->value) {
            return false;
        }

        if ($this->approved_at !== null) {
            return false;
        }

        if ($this->status === PostStatus::Published->value) {
            return false;
        }

        return $this->published_at === null || $this->published_at->isFuture();
    }

    /**
     * Featured image alt text stored on the media item.
     */
    public function getFeaturedImageAltAttribute(): ?string
    {
        $alt = $this->getFirstMedia('featured_image')?->getCustomProperty('alt');

        return is_string($alt) && $alt !== '' ? $alt : null;
    }
}
