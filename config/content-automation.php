<?php

use App\Models\Category;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use CoderManjeet\SocialAutomation\Social\Connectors\InstagramConnector;
use CoderManjeet\SocialAutomation\Social\Connectors\LinkedInConnector;
use CoderManjeet\SocialAutomation\Social\Connectors\MetaConnector;
use CoderManjeet\SocialAutomation\Social\Drivers\FacebookPublisher;
use CoderManjeet\SocialAutomation\Social\Drivers\InstagramPublisher;
use CoderManjeet\SocialAutomation\Social\Drivers\LinkedInPublisher;

return [

    'models' => [
        'post' => Post::class,
        'category' => Category::class,
        'tag' => Tag::class,
        'user' => User::class,
    ],

    'log_channel' => env('CONTENT_AUTOMATION_LOG', 'mcp'),

    'mcp' => [
        'path' => '/mcp/blog',
        'server_name' => env('BLOG_MCP_SERVER_NAME', 'ERPpe Blog'),
        'instructions' => null,
        'rate_limit_per_minute' => 60,
    ],

    'blog' => [

        'timezone' => 'Asia/Kolkata',

        'mcp_autopilot' => (bool) env('BLOG_MCP_AUTOPILOT', false),

        'mcp_writer_email' => env('MCP_WRITER_EMAIL'),

        'default_author_id' => env('BLOG_DEFAULT_AUTHOR_ID'),

        'review_notify_emails' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('ADMIN_EMAIL', '')),
        ))),

        'publish_days' => ['tue', 'fri'],

        'one_post_per_day' => true,

        'site_pages' => [],

    ],

    'social' => [
        'enabled' => env('SOCIAL_ENABLED', false),
        'auto_share_blog' => env('SOCIAL_AUTO_SHARE_BLOG', true),
        'share_posts_published_after' => env('SOCIAL_SHARE_AFTER'),
        'share_delay_minutes' => 10,
        'utm' => true,
        'notify_emails' => null,
        'platforms' => [
            'facebook' => ['enabled' => env('SOCIAL_FACEBOOK', false)],
            'instagram' => ['enabled' => env('SOCIAL_INSTAGRAM', false)],
            'linkedin' => ['enabled' => env('SOCIAL_LINKEDIN', false)],
        ],
        'meta_graph_version' => env('META_GRAPH_VERSION', 'v26.0'),
        'linkedin_version' => env('LINKEDIN_VERSION', '202610'),
        'drivers' => [
            'facebook' => FacebookPublisher::class,
            'instagram' => InstagramPublisher::class,
            'linkedin' => LinkedInPublisher::class,
        ],
        'caption_templates' => [
            'facebook' => "{title}\n\n{excerpt}\n\n{url}",
            'instagram' => "{title}\n\n{excerpt}\n\nFull article: link in bio.",
            'linkedin' => "{title}\n\n{excerpt}",
        ],
        'accounts_page' => env('SOCIAL_ACCOUNTS_PAGE', 'social/accounts'),
        'admin_middleware' => ['web', 'auth', 'verified'],
        'oauth' => [
            'meta' => [
                'app_id' => env('META_APP_ID'),
                'app_secret' => env('META_APP_SECRET'),
                'login_config_id' => env('META_LOGIN_CONFIG_ID'),
                'scopes' => ['pages_show_list', 'pages_read_engagement', 'pages_manage_posts', 'business_management', 'instagram_basic', 'instagram_content_publish'],
            ],
            'linkedin' => [
                'client_id' => env('LINKEDIN_CLIENT_ID'),
                'client_secret' => env('LINKEDIN_CLIENT_SECRET'),
                'scopes' => ['openid', 'profile', 'w_member_social'],
                'organization_scopes' => env('LINKEDIN_ORG_POSTING', false) ? ['w_organization_social', 'r_organization_admin'] : [],
            ],
            'instagram' => [
                'app_id' => env('INSTAGRAM_APP_ID'),
                'app_secret' => env('INSTAGRAM_APP_SECRET'),
                'scopes' => ['instagram_business_basic', 'instagram_business_content_publish'],
            ],
        ],
        'connectors' => [
            'meta' => MetaConnector::class,
            'linkedin' => LinkedInConnector::class,
            'instagram' => InstagramConnector::class,
        ],
    ],

];
