<?php

namespace CoderManjeet\SocialAutomation\Blog;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use CoderManjeet\SocialAutomation\SocialAutomation;
use CoderManjeet\SocialAutomation\Enums\PostSource;
use CoderManjeet\SocialAutomation\Enums\PostStatus;
use CoderManjeet\SocialAutomation\Models\McpUpload;
use CoderManjeet\SocialAutomation\Notifications\PostSubmittedForReview;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class PostWriter
{
    public function __construct(private readonly BlogHtmlSanitizer $htmlSanitizer) {}

    /**
     * Create a post from the admin form.
     */
    public function createFromAdmin(array $data, Model $actor): Model
    {
        $featuredImage = $data['featured_image'] ?? null;
        $featuredImageAlt = $data['featured_image_alt'] ?? null;
        $tagIds = $data['tag_ids'] ?? null;

        unset($data['featured_image'], $data['featured_image_alt'], $data['tag_ids']);

        $data = $this->prepareCommon($data);
        $data = $this->applyAdminPublishing($data);

        $data['user_id'] = $actor->id;
        $data['author_id'] = $data['author_id'] ?? $actor->id;
        $data['source'] = PostSource::Manual->value;

        $post = SocialAutomation::model('post')::create($data);

        if ($featuredImage instanceof UploadedFile) {
            $this->replaceFeaturedImage($post, $featuredImage, $featuredImageAlt);
        }

        $this->syncTags($post, $tagIds);

        return $post;
    }

    /**
     * Update a post from the admin form.
     */
    public function updateFromAdmin(Model $post, array $data, Model $actor): Model
    {
        $featuredImage = $data['featured_image'] ?? null;
        $featuredImageAlt = $data['featured_image_alt'] ?? null;
        $tagIds = $data['tag_ids'] ?? null;

        unset($data['featured_image'], $data['featured_image_alt'], $data['tag_ids']);

        $data = $this->prepareCommon($data, $post);
        $data = $this->applyAdminPublishing($data, $post);

        $post->update($data);

        if ($featuredImage instanceof UploadedFile) {
            $this->replaceFeaturedImage($post, $featuredImage, $featuredImageAlt);
        }

        $this->syncTags($post, $tagIds);

        return $post->refresh();
    }

    /**
     * Approve a Claude draft for its scheduled time, or publish it now.
     */
    public function approve(Model $post, Model $actor, bool $publishNow = false): Model
    {
        $this->assertReviewable($post);

        $scheduledAt = $post->scheduled_at?->toImmutable();

        if ($scheduledAt !== null && $scheduledAt->isFuture()) {
            $data = [
                'status' => PostStatus::Scheduled->value,
                'published_at' => null,
                'scheduled_at' => $scheduledAt,
            ];
        } elseif ($publishNow) {
            $data = [
                'status' => PostStatus::Published->value,
                'published_at' => CarbonImmutable::now(),
                'scheduled_at' => null,
            ];
        } else {
            throw ValidationException::withMessages([
                'scheduled_at' => 'This post has no future scheduled time. Confirm publishing it now.',
            ]);
        }

        $data['approved_by'] = $actor->id;
        $data['approved_at'] = CarbonImmutable::now();
        $data['review_notes'] = null;

        $post->update($data);

        return $post->refresh();
    }

    /**
     * Send a Claude draft back to the writer without approving it.
     */
    public function requestChanges(Model $post, string $notes): Model
    {
        $this->assertReviewable($post);

        $post->update([
            'review_notes' => trim($notes),
            'status' => PostStatus::PendingReview->value,
            'approved_by' => null,
            'approved_at' => null,
        ]);

        return $post->refresh();
    }

    /**
     * Only unapproved Claude posts can be approved or sent back.
     */
    private function assertReviewable(Model $post): void
    {
        if (
            $post->source !== PostSource::Claude->value
            || $post->status !== PostStatus::PendingReview->value
            || $post->approved_at !== null
        ) {
            throw ValidationException::withMessages([
                'post' => 'Only unapproved Claude posts that are pending review can be reviewed.',
            ]);
        }
    }

    /**
     * Create a post submitted through the MCP server.
     */
    public function createFromMcp(array $data, Model $actor): Model
    {
        $mediaId = $data['media_id'] ?? null;
        $featuredImageAlt = $data['featured_image_alt'] ?? null;
        $tagIds = $data['tag_ids'] ?? null;

        unset($data['media_id'], $data['featured_image_alt'], $data['tag_ids']);

        $status = $data['status'] ?? PostStatus::PendingReview->value;
        $scheduledAt = $this->parseMcpScheduledAt($data['scheduled_at'] ?? null);

        if ($scheduledAt === null) {
            throw ValidationException::withMessages([
                'scheduled_at' => 'scheduled_at is required and must be ISO 8601 with an explicit timezone offset.',
            ]);
        }

        $this->assertMinimumLeadTime($scheduledAt);

        $data = $this->prepareCommon($data);
        $data['status'] = $this->resolveMcpStatus($status, $scheduledAt);
        $data['scheduled_at'] = $scheduledAt;
        $data['published_at'] = null;
        $data['source'] = PostSource::Claude->value;
        $data['user_id'] = $actor->id;
        $data['author_id'] = $this->defaultAuthorId($actor);

        $post = SocialAutomation::model('post')::create($data);

        if ($mediaId !== null) {
            $this->moveMcpUpload($post, (int) $mediaId, $actor, $featuredImageAlt);
        }

        $this->syncTags($post, $tagIds);

        $this->notifyReview($post);

        return $post->refresh();
    }

    /**
     * Update a post submitted through the MCP server.
     */
    public function updateFromMcp(Model $post, array $data, Model $actor): Model
    {
        if (! $post->isEditableByMcp()) {
            throw ValidationException::withMessages([
                'id' => 'This post can no longer be edited through MCP because it is manual, published or already approved.',
            ]);
        }

        $mediaId = $data['media_id'] ?? null;
        $featuredImageAlt = $data['featured_image_alt'] ?? null;
        $tagIds = $data['tag_ids'] ?? null;

        unset($data['media_id'], $data['featured_image_alt'], $data['tag_ids']);

        $data = $this->prepareCommon($data, $post);

        if (array_key_exists('scheduled_at', $data)) {
            $data['scheduled_at'] = $this->parseMcpScheduledAt($data['scheduled_at']);

            if ($data['scheduled_at'] !== null) {
                $this->assertMinimumLeadTime($data['scheduled_at']);
            }
        }

        if (array_key_exists('status', $data)) {
            $scheduledAt = array_key_exists('scheduled_at', $data)
                ? $data['scheduled_at']
                : $post->scheduled_at?->toImmutable();

            $data['status'] = $this->resolveMcpStatus($data['status'], $scheduledAt);
        }

        $post->update($data);

        if ($mediaId !== null) {
            $this->moveMcpUpload($post, (int) $mediaId, $actor, $featuredImageAlt);
        }

        $this->syncTags($post, $tagIds);

        return $post->refresh();
    }

    /**
     * Sanitize HTML and enforce a unique slug when those fields are present.
     */
    private function prepareCommon(array $data, ?Model $post = null): array
    {
        if (array_key_exists('content_html', $data) && ! array_key_exists('content', $data)) {
            $data['content'] = $data['content_html'];
        }

        unset($data['content_html']);

        if (array_key_exists('content', $data)) {
            $data['content'] = $this->htmlSanitizer->sanitize($data['content']);
        }

        if (array_key_exists('slug', $data) || $post === null) {
            $data['slug'] = $this->uniqueSlug(
                (string) ($data['slug'] ?? ''),
                $data['title'] ?? $post?->title,
                $post?->id,
            );
        }

        return $data;
    }

    /**
     * Replicate the existing admin publishing rules.
     */
    private function applyAdminPublishing(array $data, ?Model $post = null): array
    {
        $status = $data['status'] ?? $post?->status ?? PostStatus::Draft->value;

        switch ($status) {
            case PostStatus::Published->value:
                $data['published_at'] = ! empty($data['published_at'])
                    ? CarbonImmutable::parse($data['published_at'])
                    : ($post?->published_at ?? now());
                $data['scheduled_at'] = null;
                break;

            case PostStatus::Scheduled->value:
                $data['scheduled_at'] = ! empty($data['published_at'])
                    ? CarbonImmutable::parse($data['published_at'])
                    : ($data['scheduled_at'] ?? $post?->scheduled_at ?? now()->addDay());
                $data['published_at'] = null;
                break;

            case PostStatus::PendingReview->value:
                $data['scheduled_at'] = ! empty($data['scheduled_at'])
                    ? CarbonImmutable::parse($data['scheduled_at'])
                    : $post?->scheduled_at;
                $data['published_at'] = null;
                break;

            default:
                $data['published_at'] = null;
                $data['scheduled_at'] = null;
                break;
        }

        $data['status'] = $status;

        return $data;
    }

    /**
     * Append -2, -3, ... until the slug is free (including trashed posts).
     */
    private function uniqueSlug(string $slug, ?string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($slug !== '' ? $slug : (string) $title);
        $base = $base !== '' ? $base : 'post';

        $candidate = $base;
        $suffix = 1;

        while (SocialAutomation::model('post')::withTrashed()
            ->where('slug', $candidate)
            ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists()) {
            $suffix++;
            $candidate = $base.'-'.$suffix;
        }

        return $candidate;
    }

    /**
     * Parse an ISO 8601 value that must include a timezone offset.
     */
    private function parseMcpScheduledAt(?string $value): ?CarbonImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        if (! preg_match('/(Z|[+-]\d{2}:?\d{2})$/i', $value)) {
            throw ValidationException::withMessages([
                'scheduled_at' => 'scheduled_at must be ISO 8601 with an explicit timezone offset, for example 2026-11-20T10:00:00+05:30.',
            ]);
        }

        return CarbonImmutable::parse($value)->utc();
    }

    /**
     * Give humans at least two hours before a scheduled time.
     */
    private function assertMinimumLeadTime(CarbonImmutable $scheduledAt): void
    {
        if ($scheduledAt->lessThan(now()->addHours(2))) {
            throw ValidationException::withMessages([
                'scheduled_at' => 'scheduled_at must be at least two hours in the future.',
            ]);
        }
    }

    /**
     * MCP posts are reviewable by default; scheduling requires autopilot.
     */
    private function resolveMcpStatus(string $status, ?CarbonImmutable $scheduledAt): string
    {
        if (! in_array($status, [PostStatus::PendingReview->value, PostStatus::Scheduled->value], true)) {
            throw ValidationException::withMessages([
                'status' => 'MCP posts can only be pending_review or scheduled.',
            ]);
        }

        if ($status === PostStatus::Scheduled->value && ! config('content-automation.blog.mcp_autopilot')) {
            throw ValidationException::withMessages([
                'status' => 'Scheduling is disabled. Create the post as pending_review for human approval.',
            ]);
        }

        if ($status === PostStatus::Scheduled->value && $scheduledAt === null) {
            throw ValidationException::withMessages([
                'scheduled_at' => 'A scheduled post needs a scheduled_at time.',
            ]);
        }

        return $status;
    }

    private function defaultAuthorId(Model $actor): int
    {
        $configuredId = config('content-automation.blog.default_author_id');

        if ($configuredId !== null && SocialAutomation::model('user')::query()->whereKey($configuredId)->exists()) {
            return (int) $configuredId;
        }

        return $actor->id;
    }

    private function replaceFeaturedImage(Model $post, UploadedFile $file, ?string $alt): void
    {
        if ($post->hasMedia('featured_image')) {
            $post->clearMediaCollection('featured_image');
        }

        $media = $post->addMedia($file)->toMediaCollection('featured_image');

        if (is_string($alt) && trim($alt) !== '') {
            $media->setCustomProperty('alt', trim($alt))->save();
        }
    }

    /**
     * Move a temporary MCP upload onto the post.
     */
    private function moveMcpUpload(Model $post, int $mediaId, Model $actor, ?string $alt): void
    {
        $media = Media::query()->find($mediaId);

        if (! $media || $media->model_type !== McpUpload::class) {
            throw ValidationException::withMessages([
                'media_id' => 'The uploaded image could not be found. Upload it again with upload_featured_image.',
            ]);
        }

        $upload = McpUpload::query()->find($media->model_id);

        if (! $upload || $upload->user_id !== $actor->id) {
            throw ValidationException::withMessages([
                'media_id' => 'The uploaded image belongs to another user.',
            ]);
        }

        if ($post->hasMedia('featured_image')) {
            $post->clearMediaCollection('featured_image');
        }

        $newMedia = $media->move($post, 'featured_image');

        if (is_string($alt) && trim($alt) !== '') {
            $newMedia->setCustomProperty('alt', trim($alt))->save();
        }
    }

    private function syncTags(Model $post, mixed $tagIds): void
    {
        if (! is_array($tagIds)) {
            return;
        }

        $post->tags()->sync(array_map('intval', $tagIds));
    }

    private function notifyReview(Model $post): void
    {
        $recipients = config('content-automation.blog.review_notify_emails', []);

        if ($recipients === []) {
            Log::warning('No blog review recipients configured; skipping notification.', [
                'post_id' => $post->id,
            ]);

            return;
        }

        $post->loadMissing(['author', 'user']);

        Notification::route('mail', $recipients)
            ->notify(new PostSubmittedForReview($post));
    }
}
