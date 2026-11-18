<?php

namespace CoderManjeet\SocialAutomation\Mcp\Tools\Blog;

use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use CoderManjeet\SocialAutomation\SocialAutomation;

#[Name('list_categories')]
#[Description('List blog categories with their id, name, slug, description and published post count. Use the id as category_id when creating a post.')]
#[IsReadOnly]
#[IsIdempotent]
class ListCategoriesTool extends BlogTool
{
    protected function execute(Request $request): Response
    {
        $categories = SocialAutomation::model('category')::query()
            ->withCount(['posts' => fn ($query) => $query->published()])
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'description'])
            ->map(fn (Model $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'description' => $category->description,
                'posts_count' => $category->posts_count,
            ])
            ->values()
            ->all();

        return $this->json($categories);
    }
}
