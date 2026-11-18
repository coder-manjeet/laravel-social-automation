# Laravel Content Automation

Blog MCP connector (Laravel MCP + Passport) for CoderManjeet apps.

## Install

```
composer config repositories.content-automation vcs https://github.com/coder-manjeet/laravel-social-automation
composer require coder-manjeet/laravel-social-automation:^0.1
php artisan vendor:publish --tag=content-automation-config --tag=content-automation-migrations
php artisan migrate
```

## Host requirements

- PHP 8.3, Laravel 13.
- Passport installed with an `api` guard (driver `passport`) and `Mcp::oauthRoutes()` in `routes/ai.php`.
- Host models `Post`, `Category`, `Tag`, `User` exist with the published migrations applied; `Post` uses `Concerns\InteractsWithSocialAutomation`.
- A `public` media disk and an `mcp` log channel (or set `CONTENT_AUTOMATION_LOG`).

## Config (`config/content-automation.php`)

| Key | Purpose |
|---|---|
| `models.*` | Host post, category, tag and user classes |
| `log_channel` | Tool-call log channel (default `mcp`) |
| `mcp.path`, `mcp.server_name`, `mcp.instructions` | Endpoint `/mcp/blog`, connector name, instructions (`null` = built-in text) |
| `mcp.rate_limit_per_minute` | `mcp` limiter (default 60) |
| `blog.timezone`, `blog.publish_days`, `blog.one_post_per_day` | Business timezone, suggestion days, one post per day |
| `blog.mcp_autopilot` | Allow `scheduled` status; keep false |
| `blog.mcp_writer_email` | Only this user may use the server (`MCP_WRITER_EMAIL`) |
| `blog.default_author_id` | Public byline user id |
| `blog.review_notify_emails` | Review recipients (from `ADMIN_EMAIL`) |
| `blog.site_pages` | Pages returned by `list_site_pages` |

## Social module

Published blog posts auto-share to Facebook, Instagram and LinkedIn when `SOCIAL_ENABLED=true`.
Connect accounts at **Admin → Social → Accounts**: Meta via Facebook Page, Instagram via Instagram Login or Facebook Page, LinkedIn via LinkedIn.

Required `.env`:

- `SOCIAL_ENABLED`, `SOCIAL_AUTO_SHARE_BLOG`, `SOCIAL_FACEBOOK`, `SOCIAL_INSTAGRAM`, `SOCIAL_LINKEDIN`
- `SOCIAL_SHARE_AFTER` (ISO date, required — no shares before this date)
- `META_APP_ID`, `META_APP_SECRET`, `META_LOGIN_CONFIG_ID` (optional Facebook Login for Business config id)
- `INSTAGRAM_APP_ID`, `INSTAGRAM_APP_SECRET` (direct Instagram Login)
- `LINKEDIN_CLIENT_ID`, `LINKEDIN_CLIENT_SECRET`
- `META_GRAPH_VERSION` (default `v26.0`), `LINKEDIN_VERSION` (default `202610`)

Register these callback URLs in the apps:

- `{APP_URL}/admin/social/accounts/meta/callback`
- `{APP_URL}/admin/social/accounts/instagram/callback`
- `{APP_URL}/admin/social/accounts/linkedin/callback`

`social:dispatch` runs every 5 minutes; the three social tables are auto-loaded.
