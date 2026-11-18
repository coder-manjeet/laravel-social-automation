<?php

namespace CoderManjeet\SocialAutomation\Blog;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use CoderManjeet\SocialAutomation\SocialAutomation;
use CoderManjeet\SocialAutomation\Enums\PostStatus;

class ScheduleGuard
{
    /**
     * Reject a date that already has a post scheduled in the business timezone.
     */
    public function assertDayIsFree(CarbonImmutable $scheduledAt, ?int $ignorePostId = null): void
    {
        $timezone = $this->timezone();
        $local = $scheduledAt->setTimezone($timezone);

        if (! $this->dayHasPost($local, $ignorePostId)) {
            return;
        }

        throw ValidationException::withMessages([
            'scheduled_at' => sprintf(
                'Another post is already scheduled for %s (%s time). Only one post can be scheduled per day. The next free Tuesday or Friday is %s.',
                $local->format('D, d M Y'),
                $timezone,
                $this->nextFreeTuesdayOrFriday($local),
            ),
        ]);
    }

    /**
     * Find the next Tuesday or Friday without a scheduled post.
     */
    public function nextFreeTuesdayOrFriday(CarbonImmutable $after): string
    {
        $timezone = $this->timezone();
        $cursor = $after->setTimezone($timezone)->startOfDay();
        $fallback = null;

        for ($day = 1; $day <= 60; $day++) {
            $candidate = $cursor->addDays($day);

            if (! in_array($candidate->dayOfWeek, $this->publishDayNumbers(), true)) {
                continue;
            }

            $fallback ??= $candidate;

            if (! $this->dayHasPost($candidate)) {
                return $candidate->format('D, d M Y');
            }
        }

        return ($fallback ?? $cursor->addDay())->format('D, d M Y');
    }

    private function dayHasPost(CarbonImmutable $localDate, ?int $ignorePostId = null): bool
    {
        return SocialAutomation::model('post')::query()
            ->whereIn('status', [PostStatus::Scheduled->value, PostStatus::PendingReview->value])
            ->whereNotNull('scheduled_at')
            ->when($ignorePostId !== null, fn ($query) => $query->where('id', '!=', $ignorePostId))
            ->whereBetween('scheduled_at', [
                $localDate->startOfDay()->utc(),
                $localDate->endOfDay()->utc(),
            ])
            ->exists();
    }

    private function timezone(): string
    {
        return (string) config('content-automation.blog.timezone', 'Asia/Kolkata');
    }

    /**
     * @return list<int>
     */
    private function publishDayNumbers(): array
    {
        $map = ['sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6];

        return array_values(array_filter(array_map(
            fn (string $day): ?int => $map[strtolower($day)] ?? null,
            (array) config('content-automation.blog.publish_days', ['tue', 'fri']),
        ), fn (?int $day): bool => $day !== null));
    }
}
