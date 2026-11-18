<?php

namespace CoderManjeet\SocialAutomation\Mcp\Tools\Blog;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use CoderManjeet\SocialAutomation\SocialAutomation;

#[Name('get_post')]
#[Description('Read one blog post in full, including its HTML content, SEO fields, FAQ, review notes and featured image details. Identify it by id or slug.')]
#[IsReadOnly]
class GetPostTool extends BlogTool
{
    protected function execute(Request $request): Response
    {
        $data = $request->validate([
            'id' => ['nullable', 'integer'],
            'slug' => ['nullable', 'string', 'max:255'],
        ]);

        $hasId = isset($data['id']);
        $hasSlug = isset($data['slug']) && trim((string) $data['slug']) !== '';

        if (! $hasId && ! $hasSlug) {
            throw ValidationException::withMessages([
                'id' => 'Provide either the post id or the post slug.',
            ]);
        }

        $post = SocialAutomation::model('post')::query()
            ->with(['category', 'tags'])
            ->when($hasId, fn ($query) => $query->whereKey($data['id']))
            ->when(! $hasId, fn ($query) => $query->where('slug', $data['slug']))
            ->first();

        if ($post === null) {
            return Response::error('Post not found. Use list_posts to find a valid id or slug.');
        }

        return $this->json([
            'id' => $post->id,
            'title' => $post->title,
            'slug' => $post->slug,
            'url' => url('/blog/'.$post->slug),
            'category_id' => $post->category_id,
            'category' => $post->category?->name,
            'tags' => $post->tags->map(fn ($tag): array => [
                'id' => $tag->id,
                'name' => $tag->name,
                'slug' => $tag->slug,
            ])->values()->all(),
            'content_html' => $post->content,
            'excerpt' => $post->excerpt,
            'seo_title' => $post->seo_title,
            'meta_description' => $post->meta_description,
            'focus_keyword' => $post->focus_keyword,
            'faq' => $post->faq,
            'featured_image_url' => $this->absoluteImageUrl($post),
            'featured_image_alt' => $post->featured_image_alt,
            'review_notes' => $post->review_notes,
            'share_to_social' => (bool) $post->share_to_social,
            'social_captions' => $post->social_captions,
            'status' => $post->status,
            'source' => $post->source,
            'scheduled_at' => $post->scheduled_at?->toIso8601String(),
            'published_at' => $post->published_at?->toIso8601String(),
            'approved_at' => $post->approved_at?->toIso8601String(),
            'created_at' => $post->created_at?->toIso8601String(),
            'updated_at' => $post->updated_at?->toIso8601String(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()
                ->description('The post id. Provide this or slug.'),
            'slug' => $schema->string()
                ->max(255)
                ->description('The post slug. Provide this or id.'),
        ];
    }

    private function absoluteImageUrl(Model $post): ?string
    {
        $url = $post->image_url;

        if ($url === null) {
            return null;
        }

        return str_starts_with($url, 'http://') || str_starts_with($url, 'https://')
            ? $url
            : url($url);
    }
}
