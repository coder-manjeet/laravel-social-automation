<?php

namespace CoderManjeet\SocialAutomation\Mcp\Tools\Blog;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use CoderManjeet\SocialAutomation\SocialAutomation;

#[Name('list_posts')]
#[Description('List blog posts as summaries without full content. Filter by status, category or a text search, and use get_post to read the full HTML of one post.')]
#[IsReadOnly]
class ListPostsTool extends BlogTool
{
    protected function execute(Request $request): Response
    {
        $data = $request->validate([
            'status' => ['nullable', 'string', Rule::in(['any', 'published', 'scheduled', 'pending_review', 'draft'])],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'search' => ['nullable', 'string', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $limit = (int) ($data['limit'] ?? 100);

        $posts = SocialAutomation::model('post')::query()
            ->with('category')
            ->select([
                'id',
                'title',
                'slug',
                'category_id',
                'status',
                'source',
                'focus_keyword',
                'scheduled_at',
                'published_at',
                'review_notes',
                'updated_at',
            ])
            ->when(
                isset($data['status']) && $data['status'] !== 'any',
                fn (Builder $query) => $query->where('status', $data['status']),
            )
            ->when(
                isset($data['category_id']),
                fn (Builder $query) => $query->where('category_id', $data['category_id']),
            )
            ->when(
                isset($data['search']) && $data['search'] !== '',
                function (Builder $query) use ($data): void {
                    $search = '%'.$data['search'].'%';

                    $query->where(function (Builder $query) use ($search): void {
                        $query->where('title', 'like', $search)
                            ->orWhere('slug', 'like', $search)
                            ->orWhere('excerpt', 'like', $search);
                    });
                },
            )
            ->latest('updated_at')
            ->limit($limit)
            ->get()
            ->map(fn (Model $post): array => [
                'id' => $post->id,
                'title' => $post->title,
                'slug' => $post->slug,
                'url' => url('/blog/'.$post->slug),
                'category' => $post->category?->name,
                'status' => $post->status,
                'source' => $post->source,
                'focus_keyword' => $post->focus_keyword,
                'scheduled_at' => $post->scheduled_at?->toIso8601String(),
                'published_at' => $post->published_at?->toIso8601String(),
                'has_review_notes' => filled($post->review_notes),
            ])
            ->values()
            ->all();

        return $this->json($posts);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->enum(['any', 'published', 'scheduled', 'pending_review', 'draft'])
                ->default('any')
                ->description('Filter by post status. Defaults to any.'),
            'category_id' => $schema->integer()
                ->description('Only return posts in this category id.'),
            'search' => $schema->string()
                ->max(120)
                ->description('Case-insensitive text search on title, slug and excerpt.'),
            'limit' => $schema->integer()
                ->min(1)
                ->max(200)
                ->default(100)
                ->description('Maximum number of posts to return.'),
        ];
    }
}
