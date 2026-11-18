<?php

namespace CoderManjeet\SocialAutomation;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schedule;
use Laravel\Mcp\Facades\Mcp;
use CoderManjeet\SocialAutomation\Blog\BlogHtmlSanitizer;
use CoderManjeet\SocialAutomation\Console\CheckSocialTokens;
use CoderManjeet\SocialAutomation\Console\CleanupMcpUploads;
use CoderManjeet\SocialAutomation\Console\CreateMcpWriter;
use CoderManjeet\SocialAutomation\Console\DispatchSocialPosts;
use CoderManjeet\SocialAutomation\Console\PublishScheduledPosts;
use CoderManjeet\SocialAutomation\Console\SetSocialToken;
use CoderManjeet\SocialAutomation\Mcp\Servers\BlogServer;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class SocialAutomationServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('content-automation')
            ->hasConfigFile()
            ->runsMigrations()
            ->hasMigrations([
                'add_mcp_fields_to_posts_table',
                'create_tags_table',
                'create_mcp_uploads_table',
                '2026_11_18_000001_create_social_accounts_table',
                '2026_11_18_000002_create_social_posts_table',
                '2026_11_18_000003_create_social_post_targets_table',
                '2026_11_18_000004_add_connected_fields_to_social_accounts_table',
                'add_social_fields_to_posts_table',
            ])
            ->hasCommands([
                PublishScheduledPosts::class,
                CreateMcpWriter::class,
                CleanupMcpUploads::class,
                DispatchSocialPosts::class,
                SetSocialToken::class,
                CheckSocialTokens::class,
            ]);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(BlogHtmlSanitizer::class);
    }

    public function packageBooted(): void
    {
        $this->registerGate();
        $this->registerRateLimiting();
        $this->registerMcpRoute();
        $this->registerRoutes();

        Schedule::command('blog:publish-scheduled')
            ->everyMinute()
            ->withoutOverlapping()
            ->runInBackground();

        Schedule::command('social:dispatch')
            ->everyFiveMinutes()
            ->withoutOverlapping();

        Schedule::command('social:check-tokens')
            ->dailyAt('09:00')
            ->timezone(config('content-automation.blog.timezone', 'Asia/Kolkata'));
    }

    /**
     * Only the configured MCP writer may use the blog server.
     */
    protected function registerGate(): void
    {
        Gate::define('write-blog-via-mcp', function (User $user): bool {
            $writerEmail = config('content-automation.blog.mcp_writer_email');

            return is_string($writerEmail)
                && $writerEmail !== ''
                && $user->email !== null
                && strcasecmp($user->email, $writerEmail) === 0;
        });
    }

    /**
     * Rate limit MCP requests per writer or IP.
     */
    protected function registerRateLimiting(): void
    {
        $perMinute = (int) config('content-automation.mcp.rate_limit_per_minute', 60);

        RateLimiter::for('mcp', function (Request $request) use ($perMinute): Limit {
            return Limit::perMinute($perMinute)->by(
                $request->user()?->getAuthIdentifier() ?: $request->ip(),
            );
        });
    }

    /**
     * Register the admin social account routes with configurable middleware.
     */
    protected function registerRoutes(): void
    {
        $middleware = (array) config('content-automation.social.admin_middleware', ['web', 'auth', 'verified']);

        Route::middleware($middleware)->group(function (): void {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        });
    }

    /**
     * Register the blog MCP endpoint with the same conditional middleware as before.
     */
    protected function registerMcpRoute(): void
    {
        $middleware = ['throttle:mcp'];

        if (config('auth.guards.api.driver') === 'passport') {
            $middleware = ['auth:api', 'can:write-blog-via-mcp', 'throttle:mcp'];
        }

        Mcp::web((string) config('content-automation.mcp.path', '/mcp/blog'), BlogServer::class)
            ->middleware($middleware);
    }
}
