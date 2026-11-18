<?php

namespace CoderManjeet\SocialAutomation\Blog;

class SitePages
{
    /**
     * Linkable public pages for the MCP writer.
     *
     * @return list<array{title: string, url: string, type: string}>
     */
    public function all(): array
    {
        return config('content-automation.blog.site_pages', []);
    }
}
