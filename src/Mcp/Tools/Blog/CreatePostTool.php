<?php

namespace CoderManjeet\SocialAutomation\Mcp\Tools\Blog;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Foundation\Auth\User;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use CoderManjeet\SocialAutomation\Blog\PostWriter;
use CoderManjeet\SocialAutomation\Blog\ScheduleGuard;
use CoderManjeet\SocialAutomation\Enums\PostStatus;
use CoderManjeet\SocialAutomation\Mcp\Tools\Blog\Concerns\InteractsWithPostPayload;

#[Name('create_post')]
#[Description('Create a blog post as pending_review for a human to approve. Supply the full HTML content, SEO fields, category, intended schedule time and an optional uploaded media_id. You cannot publish immediately or delete posts.')]
class CreatePostTool extends BlogTool
{
    use InteractsWithPostPayload;

    protected function execute(Request $request): Response
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => ['integer', 'exists:tags,id'],
            'content_html' => ['required', 'string', 'min:2000', 'max:200000'],
            'excerpt' => ['required', 'string', 'max:300'],
            'seo_title' => ['required', 'string', 'max:60'],
            'meta_description' => ['required', 'string', 'between:120,160'],
            'focus_keyword' => ['required', 'string', 'max:120'],
            'faq' => ['nullable', 'array', 'max:8'],
            'faq.*.question' => ['required', 'string', 'max:200'],
            'faq.*.answer' => ['required', 'string', 'max:1000'],
            'media_id' => ['required', 'integer', 'exists:media,id'],
            'scheduled_at' => ['required', 'string', 'date'],
            'status' => ['required', 'string', Rule::in([
                PostStatus::PendingReview->value,
                PostStatus::Scheduled->value,
            ])],
            'share_to_social' => ['sometimes', 'boolean'],
            'social_captions' => ['sometimes', 'nullable', 'array'],
            'social_captions.facebook' => ['nullable', 'string', 'max:2200'],
            'social_captions.instagram' => ['nullable', 'string', 'max:2200'],
            'social_captions.linkedin' => ['nullable', 'string', 'max:3000'],
        ]);

        app(ScheduleGuard::class)->assertDayIsFree(
            CarbonImmutable::parse($data['scheduled_at'])->utc(),
        );

        $warnings = $this->sanitizerWarnings($data['content_html']);

        /** @var User $user */
        $user = $request->user();

        $post = app(PostWriter::class)->createFromMcp($data, $user);

        return $this->json($this->postResponse($post, $warnings));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()
                ->required()
                ->max(120)
                ->description('The post title.'),
            'slug' => $schema->string()
                ->required()
                ->max(100)
                ->pattern('^[a-z0-9]+(?:-[a-z0-9]+)*$')
                ->description('Lowercase slug. A suffix is added automatically if it is already taken.'),
            'category_id' => $schema->integer()
                ->required()
                ->description('An id from list_categories.'),
            'tag_ids' => $schema->array()
                ->items($schema->integer())
                ->description('Optional ids from list_tags.'),
            'content_html' => $schema->string()
                ->required()
                ->min(2000)
                ->max(200000)
                ->description('The full post body as sanitized HTML.'),
            'excerpt' => $schema->string()
                ->required()
                ->max(300)
                ->description('A short summary for listings.'),
            'seo_title' => $schema->string()
                ->required()
                ->max(60)
                ->description('The SEO title.'),
            'meta_description' => $schema->string()
                ->required()
                ->min(120)
                ->max(160)
                ->description('The meta description, between 120 and 160 characters.'),
            'focus_keyword' => $schema->string()
                ->required()
                ->max(120)
                ->description('The primary keyword for the post.'),
            'faq' => $schema->array()
                ->max(8)
                ->items($schema->object(fn (JsonSchema $schema): array => [
                    'question' => $schema->string()->required()->max(200),
                    'answer' => $schema->string()->required()->max(1000),
                ]))
                ->description('Optional FAQ entries stored with the post.'),
            'media_id' => $schema->integer()
                ->required()
                ->description('The media_id returned by upload_featured_image.'),
            'scheduled_at' => $schema->string()
                ->required()
                ->format('date-time')
                ->description('ISO 8601 date-time with an explicit timezone offset, at least two hours in the future.'),
            'status' => $schema->string()
                ->required()
                ->enum([PostStatus::PendingReview->value, PostStatus::Scheduled->value])
                ->description('Use pending_review for human approval. scheduled is only allowed when autopilot is enabled.'),
            'share_to_social' => $schema->boolean()
                ->description('Share this post to the configured social platforms when it goes live. Defaults to true.'),
            'social_captions' => $schema->object(fn (JsonSchema $schema): array => [
                'facebook' => $schema->string()->max(2200)->description('Facebook caption.'),
                'instagram' => $schema->string()->max(2200)->description('Instagram caption.'),
                'linkedin' => $schema->string()->max(3000)->description('LinkedIn caption.'),
            ])->description('Optional captions per platform; leave blank to use the template.'),
        ];
    }
}
