<?php

namespace CoderManjeet\SocialAutomation\Mcp\Tools\Blog;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use CoderManjeet\SocialAutomation\Blog\SitePages;

#[Name('list_site_pages')]
#[Description('List the public ERPpe pages that a blog post can link to, with a title, relative URL and type (page, solution or industry).')]
#[IsReadOnly]
#[IsIdempotent]
class ListSitePagesTool extends BlogTool
{
    protected function execute(Request $request): Response
    {
        return $this->json(app(SitePages::class)->all());
    }
}
