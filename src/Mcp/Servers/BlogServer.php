<?php

namespace CoderManjeet\SocialAutomation\Mcp\Servers;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Contracts\Transport;
use CoderManjeet\SocialAutomation\Mcp\Tools\Blog\CreatePostTool;
use CoderManjeet\SocialAutomation\Mcp\Tools\Blog\GetPostTool;
use CoderManjeet\SocialAutomation\Mcp\Tools\Blog\ListCategoriesTool;
use CoderManjeet\SocialAutomation\Mcp\Tools\Blog\ListPostsTool;
use CoderManjeet\SocialAutomation\Mcp\Tools\Blog\ListSitePagesTool;
use CoderManjeet\SocialAutomation\Mcp\Tools\Blog\ListTagsTool;
use CoderManjeet\SocialAutomation\Mcp\Tools\Blog\UpdatePostTool;
use CoderManjeet\SocialAutomation\Mcp\Tools\Blog\UploadFeaturedImageTool;

#[Version('1.0.0')]
class BlogServer extends Server
{
    private const DEFAULT_INSTRUCTIONS = 'Tools to plan and draft blog posts for erppe.com. Posts you create are saved for human review; you cannot publish immediately or delete anything. Times are ISO 8601 with an explicit timezone offset; the business timezone is Asia/Kolkata. Only one post can be scheduled per calendar day.';

    /**
     * @var array<int, class-string>
     */
    protected array $tools = [
        ListCategoriesTool::class,
        ListTagsTool::class,
        ListPostsTool::class,
        GetPostTool::class,
        ListSitePagesTool::class,
        UploadFeaturedImageTool::class,
        CreatePostTool::class,
        UpdatePostTool::class,
    ];

    public function __construct(Transport $transport)
    {
        parent::__construct($transport);

        $this->name = (string) config('content-automation.mcp.server_name', 'ERPpe Blog');
        $this->instructions = (string) (config('content-automation.mcp.instructions') ?? self::DEFAULT_INSTRUCTIONS);
    }
}
