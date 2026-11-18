<?php

namespace CoderManjeet\SocialAutomation\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use CoderManjeet\SocialAutomation\SocialAutomation;
use CoderManjeet\SocialAutomation\Enums\PostStatus;

class PublishScheduledPosts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'blog:publish-scheduled';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Publish scheduled blog posts whose scheduled time has passed';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $published = 0;

        SocialAutomation::model('post')::query()
            ->where('status', PostStatus::Scheduled->value)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->select('id')
            ->chunkById(200, function ($posts) use (&$published): void {
                $published += SocialAutomation::model('post')::query()
                    ->whereIn('id', $posts->pluck('id'))
                    ->update([
                        'status' => PostStatus::Published->value,
                        'published_at' => DB::raw('scheduled_at'),
                        'scheduled_at' => null,
                    ]);
            });

        Log::info('Published scheduled blog posts.', ['count' => $published]);

        $this->info("Published {$published} scheduled post(s).");

        return self::SUCCESS;
    }
}
