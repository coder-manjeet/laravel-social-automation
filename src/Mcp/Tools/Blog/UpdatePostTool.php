<?php

namespace CoderManjeet\SocialAutomation\Mcp\Tools\Blog;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use CoderManjeet\SocialAutomation\Blog\PostWriter;
use CoderManjeet\SocialAutomation\Blog\ScheduleGuard;
use CoderManjeet\SocialAutomation\SocialAutomation;
use CoderManjeet\SocialAutomation\Enums\PostStatus;
use CoderManjeet\SocialAutomation\Mcp\Tools\Blog\Concerns\InteractsWithPostPayload;

#[Name('update_post')]
#[Description('Update a post that the MCP writer created and that has not been approved or published. Send the id plus any fields to change. Manual, published and approved posts are locked.')]
#[IsIdempotent]
class UpdatePostTool extends BlogTool
{
    use InteractsWithPostPayload;

    protected function execute(Request $request): Response
    {
        $data = $request->validate([
            'id' => ['required', 'integer', 'exists:posts,id'],
            'title' => ['sometimes', 'required', 'string', 'max:120'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'category_id' => ['sometimes', 'required', 'integer', 'exists:categories,id'],
            'tag_ids' => ['sometimes', 'nullable', 'array'],
            'tag_ids.*' => ['integer', 'exists:tags,id'],
            'content_html' => ['sometimes', 'required', 'string', 'min:2000', 'max:200000'],
            'excerpt' => ['sometimes', 'required', 'string', 'max:300'],
            'seo_title' => ['sometimes', 'required', 'string', 'max:60'],
            'meta_description' => ['sometimes', 'required', 'string', 'between:120,160'],
            'focus_keyword' => ['sometimes', 'required', 'string', 'max:120'],
            'faq' => ['sometimes', 'nullable', 'array', 'max:8'],
            'faq.*.question' => ['required', 'string', 'max:200'],
            'faq.*.answer' => ['required', 'string', 'max:1000'],
            'media_id' => ['sometimes', 'integer', 'exists:media,id'],
            'scheduled_at' => ['sometimes', 'nullable', 'string', 'date'],
            'status' => ['sometimes', 'required', 'string', Rule::in([
                PostStatus::PendingReview->value,
                PostStatus::Scheduled->value,
            ])],
            'share_to_social' => ['sometimes', 'boolean'],
            'social_captions' => ['sometimes', 'nullable', 'array'],
            'social_captions.facebook' => ['nullable', 'string', 'max:2200'],
            'social_captions.instagram' => ['nullable', 'string', 'max:2200'],
            'social_captions.linkedin' => ['nullable', 'string', 'max:3000'],
        ]);

        $post = SocialAutomation::model('post')::query()->findOrFail($data['id']);

        unset($data['id']);

        if (! $post->isEditableByMcp()) {
            return Response::error('This post can no longer be edited through MCP because it is manual, published or already approved.');
        }

        $this->guardSchedule($post, $data);

        $warnings = array_key_exists('content_html', $data)
            ? $this->sanitizerWarnings($data['content_html'])
            : [];

        /** @var User $user */
        $user = $request->user();

        $post = app(PostWriter::class)->updateFromMcp($post, $data, $user);

        return $this->json($this->postResponse($post, $warnings));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function guardSchedule(Model $post, array $data): void
    {
        $scheduledAt = array_key_exists('scheduled_at', $data)
            ? ($data['scheduled_at'] !== null ? CarbonImmutable::parse($data['scheduled_at'])->utc() : null)
            : $post->scheduled_at?->toImmutable();

        $status = $data['status'] ?? $post->status;

        if ($scheduledAt === null) {
            return;
        }

        if (! in_array($status, [PostStatus::PendingReview->value, PostStatus::Scheduled->value], true)) {
            return;
        }

        app(ScheduleGuard::class)->assertDayIsFree($scheduledAt, $post->id);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()
                ->required()
                ->description('The post id to update.'),
            'title' => $schema->string()
                ->max(120)
                ->description('The post title.'),
            'slug' => $schema->string()
                ->max(100)
                ->pattern('^[a-z0-9]+(?:-[a-z0-9]+)*$')
                ->description('Only send this to change the slug. It is left unchanged otherwise.'),
            'category_id' => $schema->integer()
                ->description('An id from list_categories.'),
            'tag_ids' => $schema->array()
                ->items($schema->integer())
                ->description('Replaces the post tags with these ids from list_tags.'),
            'content_html' => $schema->string()
                ->min(2000)
                ->max(200000)
                ->description('The full post body as HTML.'),
            'excerpt' => $schema->string()
                ->max(300)
                ->description('A short summary for listings.'),
            'seo_title' => $schema->string()
                ->max(60)
                ->description('The SEO title.'),
            'meta_description' => $schema->string()
                ->min(120)
                ->max(160)
                ->description('The meta description, between 120 and 160 characters.'),
            'focus_keyword' => $schema->string()
                ->max(120)
                ->description('The primary keyword for the post.'),
            'faq' => $schema->array()
                ->max(8)
                ->items($schema->object(fn (JsonSchema $schema): array => [
                    'question' => $schema->string()->required()->max(200),
                    'answer' => $schema->string()->required()->max(1000),
                ]))
                ->description('Replaces the FAQ entries stored with the post.'),
            'media_id' => $schema->integer()
                ->description('Replace the featured image with this media_id from upload_featured_image.'),
            'scheduled_at' => $schema->string()
                ->format('date-time')
                ->description('ISO 8601 date-time with an explicit timezone offset.'),
            'status' => $schema->string()
                ->enum([PostStatus::PendingReview->value, PostStatus::Scheduled->value])
                ->description('Use pending_review. scheduled is only allowed when autopilot is enabled.'),
            'share_to_social' => $schema->boolean()
                ->description('Share this post to the configured social platforms when it goes live.'),
            'social_captions' => $schema->object(fn (JsonSchema $schema): array => [
                'facebook' => $schema->string()->max(2200)->description('Facebook caption.'),
                'instagram' => $schema->string()->max(2200)->description('Instagram caption.'),
                'linkedin' => $schema->string()->max(3000)->description('LinkedIn caption.'),
            ])->description('Replaces the per-platform captions. Leave blank to use the template.'),
        ];
    }
}
