<?php

namespace CoderManjeet\SocialAutomation\Social;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use CoderManjeet\SocialAutomation\Enums\SocialPostStatus;
use CoderManjeet\SocialAutomation\Enums\SocialTargetStatus;
use CoderManjeet\SocialAutomation\Jobs\PublishSocialTarget;
use CoderManjeet\SocialAutomation\Models\SocialPost;
use CoderManjeet\SocialAutomation\Models\SocialPostTarget;

class SocialPostManager
{
    public function driverFor(string $platform): SocialPublisher
    {
        $driver = config("content-automation.social.drivers.{$platform}");

        if (! is_string($driver) || $driver === '') {
            throw new SocialPublishException("No social driver is configured for {$platform}.");
        }

        return app($driver);
    }

    public function approve(SocialPost $post, ?Model $actor = null, ?CarbonImmutable $scheduledAt = null): SocialPost
    {
        $post->update([
            'status' => SocialPostStatus::Approved->value,
            'review_notes' => null,
            'approved_by' => $actor?->getKey(),
            'approved_at' => Carbon::now(),
            'scheduled_at' => $scheduledAt,
        ]);

        return $post->refresh();
    }

    public function requestChanges(SocialPost $post, string $notes): SocialPost
    {
        $post->update([
            'status' => SocialPostStatus::PendingReview->value,
            'review_notes' => trim($notes),
            'approved_by' => null,
            'approved_at' => null,
        ]);

        return $post->refresh();
    }

    public function retryTarget(SocialPostTarget $target): void
    {
        $target->update([
            'status' => SocialTargetStatus::Pending->value,
            'attempts' => 0,
            'last_error' => null,
            'sent_at' => null,
        ]);

        $target->socialPost?->update(['status' => SocialPostStatus::Publishing->value]);

        PublishSocialTarget::dispatch($target->id);
    }

    public function retryFailed(SocialPost $post): void
    {
        $post->targets()
            ->where('status', SocialTargetStatus::Failed->value)
            ->get()
            ->each(fn (SocialPostTarget $target) => $this->retryTarget($target));
    }

    /**
     * Recompute the parent status after a target finishes.
     */
    public function syncStatus(SocialPost $post): void
    {
        if (! in_array($post->status, [
            SocialPostStatus::Publishing->value,
            SocialPostStatus::PartiallyFailed->value,
            SocialPostStatus::Failed->value,
        ], true)) {
            return;
        }

        $statuses = $post->targets()->pluck('status');

        if ($statuses->isEmpty()) {
            return;
        }

        $sent = [SocialTargetStatus::Sent->value, SocialTargetStatus::Skipped->value];

        if ($statuses->every(fn (string $status): bool => in_array($status, $sent, true))) {
            $post->update([
                'status' => SocialPostStatus::Published->value,
                'published_at' => $post->published_at ?? Carbon::now(),
            ]);

            return;
        }

        if ($statuses->contains(SocialTargetStatus::Pending->value)) {
            return;
        }

        $post->update([
            'status' => $statuses->every(fn (string $status): bool => $status === SocialTargetStatus::Failed->value)
                ? SocialPostStatus::Failed->value
                : SocialPostStatus::PartiallyFailed->value,
        ]);
    }

    /**
     * @return list<string>
     */
    public function notifyEmails(): array
    {
        $configured = config('content-automation.social.notify_emails');

        if (is_string($configured)) {
            $configured = explode(',', $configured);
        }

        if (is_array($configured)) {
            $configured = array_values(array_filter(array_map('trim', $configured)));
        }

        return is_array($configured) && $configured !== []
            ? $configured
            : (array) config('content-automation.blog.review_notify_emails', []);
    }
}
