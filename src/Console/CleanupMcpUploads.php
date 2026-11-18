<?php

namespace CoderManjeet\SocialAutomation\Console;

use Illuminate\Console\Command;
use CoderManjeet\SocialAutomation\Models\McpUpload;

class CleanupMcpUploads extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'blog:cleanup-mcp-uploads';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete temporary MCP image uploads older than 24 hours';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $deleted = 0;

        McpUpload::query()
            ->where('created_at', '<', now()->subDay())
            ->chunkById(100, function ($uploads) use (&$deleted): void {
                foreach ($uploads as $upload) {
                    $upload->clearMediaCollection('featured_image');
                    $upload->delete();
                    $deleted++;
                }
            });

        $this->info("Deleted {$deleted} unused MCP upload(s).");

        return self::SUCCESS;
    }
}
