<?php

namespace CoderManjeet\SocialAutomation\Mcp\Tools\Blog\Concerns;

use Illuminate\Database\Eloquent\Model;
use CoderManjeet\SocialAutomation\Blog\BlogHtmlSanitizer;

trait InteractsWithPostPayload
{
    /**
     * Report HTML tags that the sanitizer will remove.
     *
     * @return list<string>
     */
    protected function sanitizerWarnings(?string $html): array
    {
        $html = (string) $html;

        if ($html === '') {
            return [];
        }

        $sanitized = app(BlogHtmlSanitizer::class)->sanitize($html);

        $removed = array_values(array_diff(
            $this->tagNames($html),
            $this->tagNames($sanitized),
        ));

        if ($removed === []) {
            return [];
        }

        return ['Removed unsupported HTML tags: '.implode(', ', $removed).'.'];
    }

    /**
     * @param  list<string>  $warnings
     * @return array<string, mixed>
     */
    protected function postResponse(Model $post, array $warnings): array
    {
        return [
            'id' => $post->id,
            'slug' => $post->slug,
            'status' => $post->status,
            'scheduled_at' => $post->scheduled_at?->toIso8601String(),
            'admin_url' => route('posts.edit', $post),
            'preview_url' => url('/blog/'.$post->slug),
            'warnings' => $warnings,
        ];
    }

    /**
     * @return list<string>
     */
    private function tagNames(string $html): array
    {
        preg_match_all('/<\s*([a-z][a-z0-9]*)\b/i', $html, $matches);

        return array_values(array_unique(array_map('strtolower', $matches[1])));
    }
}
