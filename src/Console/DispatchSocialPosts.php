<?php

namespace CoderManjeet\SocialAutomation\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use CoderManjeet\SocialAutomation\SocialAutomation;
use CoderManjeet\SocialAutomation\Enums\PostStatus;
use CoderManjeet\SocialAutomation\Enums\SocialPostStatus;
use CoderManjeet\SocialAutomation\Enums\SocialTargetStatus;
use CoderManjeet\SocialAutomation\Jobs\PublishSocialTarget;
use CoderManjeet\SocialAutomation\Models\SocialAccount;
use CoderManjeet\SocialAutomation\Models\SocialPost;
use CoderManjeet\SocialAutomation\Social\SocialPostManager;

class DispatchSocialPosts extends Command
{
    protected $signature = 'social:dispatch';

    protected $description = 'Create blog shares for newly published posts and publish approved social posts.';

    public function handle(SocialPostManager $manager): int
    {
        if (! config('content-automation.social.enabled')) {
            return self::SUCCESS;
        }

        if ((bool) config('content-automation.social.auto_share_blog', true)) {
            $this->createBlogShares();
        }

        $this->publishApproved($manager);

        return self::SUCCESS;
    }

    private function createBlogShares(): void
    {
        $since = config('content-automation.social.share_posts_published_after');

        if (! is_string($since) || trim($since) === '') {
            Log::warning('social.share_posts_published_after is not set; skipping blog auto-share.');

            return;
        }

        $platforms = $this->enabledPlatforms();

        if ($platforms === []) {
            return;
        }

        $delay = (int) config('content-automation.social.share_delay_minutes', 10);
        $postModel = SocialAutomation::model('post');

        $posts = $postModel::query()
            ->where('status', PostStatus::Published->value)
            ->where('share_to_social', true)
            ->whereNotNull('published_at')
            ->where('published_at', '>=', Carbon::parse($since))
            ->where('published_at', '<=', now()->subMinutes($delay))
            ->whereNotIn('id', SocialPost::query()->whereNotNull('post_id')->pluck('post_id'))
            ->orderBy('id')
            ->get();

        foreach ($posts as $post) {
            SocialPost::create([
                'source' => 'blog_share',
                'post_id' => $post->id,
                'type' => 'link',
                'status' => SocialPostStatus::Approved->value,
                'captions' => $this->captionsFor($post, $platforms),
                'platforms' => $platforms,
                'link_url' => url('/blog/'.$post->slug),
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function enabledPlatforms(): array
    {
        $platforms = [];

        foreach ((array) config('content-automation.social.platforms', []) as $platform => $settings) {
            if (! (bool) ($settings['enabled'] ?? false)) {
                continue;
            }

            if (! SocialAccount::query()->where('platform', $platform)->exists()) {
                continue;
            }

            $platforms[] = $platform;
        }

        return $platforms;
    }

    /**
     * @param  list<string>  $platforms
     * @return array<string, string>
     */
    private function captionsFor(mixed $post, array $platforms): array
    {
        $custom = is_array($post->social_captions) ? $post->social_captions : [];
        $captions = [];

        foreach ($platforms as $platform) {
            $customCaption = trim((string) ($custom[$platform] ?? ''));

            $captions[$platform] = $customCaption !== ''
                ? $customCaption
                : $this->renderCaption($post, $platform);
        }

        return $captions;
    }

    private function renderCaption(mixed $post, string $platform): string
    {
        $template = (string) config(
            "content-automation.social.caption_templates.{$platform}",
            "{title}\n\n{excerpt}",
        );

        $url = url('/blog/'.$post->slug);

        if ((bool) config('content-automation.social.utm', true)) {
            $url = SocialPost::withUtm($url, $platform);
        }

        return str_replace(
            ['{title}', '{excerpt}', '{url}'],
            [(string) $post->title, (string) $post->excerpt, $url],
            $template,
        );
    }

    private function publishApproved(SocialPostManager $manager): void
    {
        $posts = SocialPost::query()
            ->where('status', SocialPostStatus::Approved->value)
            ->where(function ($query): void {
                $query->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', now());
            })
            ->orderBy('id')
            ->get();

        foreach ($posts as $socialPost) {
            $socialPost->update(['status' => SocialPostStatus::Publishing->value]);

            foreach ((array) $socialPost->platforms as $platform) {
                try {
                    $driver = $manager->driverFor($platform);
                } catch (\Throwable $exception) {
                    Log::warning('Skipping unconfigured social platform.', [
                        'platform' => $platform,
                        'error' => $exception->getMessage(),
                    ]);

                    continue;
                }

                $target = $socialPost->targets()->firstOrCreate(
                    ['platform' => $platform],
                    ['status' => SocialTargetStatus::Pending->value],
                );

                if ($target->status === SocialTargetStatus::Sent->value) {
                    continue;
                }

                if (! $driver->supports($socialPost->type)) {
                    $target->update(['status' => SocialTargetStatus::Skipped->value]);

                    continue;
                }

                if ($target->status === SocialTargetStatus::Pending->value) {
                    PublishSocialTarget::dispatch($target->id);
                }
            }

            $manager->syncStatus($socialPost->refresh());
        }
    }
}
