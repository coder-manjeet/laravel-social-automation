<?php

namespace CoderManjeet\SocialAutomation\Mcp\Tools\Blog;

use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use CoderManjeet\SocialAutomation\SocialAutomation;

#[Name('list_tags')]
#[Description('List blog tags with their id, name and slug. Use the ids in the tag_ids field when creating or updating a post.')]
#[IsReadOnly]
class ListTagsTool extends BlogTool
{
    protected function execute(Request $request): Response
    {
        $tags = SocialAutomation::model('tag')::query()
            ->orderBy('name')
            ->get(['id', 'name', 'slug'])
            ->map(fn (Model $tag): array => [
                'id' => $tag->id,
                'name' => $tag->name,
                'slug' => $tag->slug,
            ])
            ->values()
            ->all();

        return $this->json($tags);
    }
}
