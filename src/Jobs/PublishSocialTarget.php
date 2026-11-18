<?php

namespace CoderManjeet\SocialAutomation\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use CoderManjeet\SocialAutomation\Enums\SocialTargetStatus;
use CoderManjeet\SocialAutomation\Models\SocialAccount;
use CoderManjeet\SocialAutomation\Models\SocialPostTarget;
use CoderManjeet\SocialAutomation\Notifications\SocialPublishFailed;
use CoderManjeet\SocialAutomation\Social\SocialPostManager;
use CoderManjeet\SocialAutomation\Social\SocialPublishException;
use Throwable;

class PublishSocialTarget implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public int $uniqueFor = 3600;

    public function __construct(public int $targetId) {}

    public function uniqueId(): string
    {
        return (string) $this->targetId;
    }

    public function handle(SocialPostManager $manager): void
    {
        $target = SocialPostTarget::with('socialPost.post')->find($this->targetId);

        if ($target === null || $target->status === SocialTargetStatus::Sent->value) {
            return;
        }

        $post = $target->socialPost;
        $account = SocialAccount::query()->where('platform', $target->platform)->first();

        if ($post === null || $account === null) {
            throw new SocialPublishException('The social account for this platform is missing.');
        }

        $startedAt = microtime(true);

        try {
            $result = $manager->driverFor($target->platform)->publish($post, $account);

            $target->update([
                'status' => SocialTargetStatus::Sent->value,
                'remote_id' => $result['remote_id'],
                'remote_url' => $result['remote_url'],
                'last_error' => null,
                'sent_at' => now(),
            ]);

            $this->log('info', $post->id, $target->platform, null, $startedAt);
        } catch (Throwable $exception) {
            $target->increment('attempts');
            $target->update(['last_error' => $exception->getMessage()]);

            $this->log('error', $post->id, $target->platform, $exception->getMessage(), $startedAt);

            throw $exception;
        } finally {
            $manager->syncStatus($target->socialPost()->first() ?? $post);
        }
    }

    public function failed(Throwable $exception): void
    {
        $target = SocialPostTarget::with('socialPost')->find($this->targetId);

        if ($target === null) {
            return;
        }

        if ($target->status !== SocialTargetStatus::Sent->value) {
            $target->update([
                'status' => SocialTargetStatus::Failed->value,
                'last_error' => $exception->getMessage(),
            ]);
        }

        $manager = app(SocialPostManager::class);
        $recipients = $manager->notifyEmails();

        if ($recipients !== [] && $target->socialPost !== null) {
            Notification::route('mail', $recipients)
                ->notify(new SocialPublishFailed($target));
        }

        if ($target->socialPost !== null) {
            $manager->syncStatus($target->socialPost->refresh());
        }
    }

    private function log(string $level, int $socialPostId, string $platform, ?string $error, float $startedAt): void
    {
        Log::channel(config('content-automation.log_channel', 'mcp'))->{$level}('Social publish attempt', [
            'social_post_id' => $socialPostId,
            'platform' => $platform,
            'ok' => $error === null,
            'error' => $error,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
    }
}
