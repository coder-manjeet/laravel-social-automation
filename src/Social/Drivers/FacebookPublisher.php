<?php

namespace CoderManjeet\SocialAutomation\Social\Drivers;

use Illuminate\Support\Facades\Http;
use CoderManjeet\SocialAutomation\Models\SocialAccount;
use CoderManjeet\SocialAutomation\Models\SocialPost;
use CoderManjeet\SocialAutomation\Social\SocialImage;
use CoderManjeet\SocialAutomation\Social\SocialPublisher;
use CoderManjeet\SocialAutomation\Social\SocialPublishException;

class FacebookPublisher implements SocialPublisher
{
    public function supports(string $type): bool
    {
        return in_array($type, ['link', 'image', 'carousel'], true);
    }

    public function publish(SocialPost $post, SocialAccount $account): array
    {
        $base = 'https://graph.facebook.com/'.config('content-automation.social.meta_graph_version', 'v26.0');
        $page = $account->account_id;

        return match ($post->type) {
            'image' => $this->publishImage($base, $page, $post, $account),
            'carousel' => $this->publishCarousel($base, $page, $post, $account),
            default => $this->publishLink($base, $page, $post, $account),
        };
    }

    /**
     * @return array{remote_id:string, remote_url:?string}
     */
    private function publishLink(string $base, string $page, SocialPost $post, SocialAccount $account): array
    {
        $response = Http::timeout(30)->asForm()->withToken($account->token)
            ->post("{$base}/{$page}/feed", [
                'message' => $post->captionFor('facebook') ?? '',
                'link' => $post->linkFor('facebook'),
            ])->throw();

        $id = (string) $response->json('id');

        if ($id === '') {
            throw new SocialPublishException('Facebook did not return a post id.');
        }

        return ['remote_id' => $id, 'remote_url' => 'https://www.facebook.com/'.$id];
    }

    /**
     * @return array{remote_id:string, remote_url:?string}
     */
    private function publishImage(string $base, string $page, SocialPost $post, SocialAccount $account): array
    {
        $url = $this->firstImageUrl($post);

        $response = Http::timeout(30)->asForm()->withToken($account->token)
            ->post("{$base}/{$page}/photos", [
                'url' => $url,
                'caption' => $post->captionFor('facebook') ?? '',
            ])->throw();

        $id = (string) $response->json('id');

        if ($id === '') {
            throw new SocialPublishException('Facebook did not return a photo id.');
        }

        return ['remote_id' => $id, 'remote_url' => 'https://www.facebook.com/'.$id];
    }

    /**
     * @return array{remote_id:string, remote_url:?string}
     */
    private function publishCarousel(string $base, string $page, SocialPost $post, SocialAccount $account): array
    {
        $files = $post->imageFiles();

        if ($files === []) {
            throw new SocialPublishException('A Facebook carousel needs at least one image.');
        }

        $mediaIds = [];

        foreach ($files as $file) {
            $response = Http::timeout(30)->asForm()->withToken($account->token)
                ->post("{$base}/{$page}/photos", [
                    'url' => SocialImage::jpegUrl($file),
                    'published' => 'false',
                ])->throw();

            $mediaIds[] = (string) $response->json('id');
        }

        $payload = ['message' => $post->captionFor('facebook') ?? ''];

        foreach ($mediaIds as $index => $mediaId) {
            $payload["attached_media[{$index}]"] = json_encode(['media_fbid' => $mediaId]);
        }

        $response = Http::timeout(30)->asForm()->withToken($account->token)
            ->post("{$base}/{$page}/feed", $payload)->throw();

        $id = (string) $response->json('id');

        if ($id === '') {
            throw new SocialPublishException('Facebook did not return a post id.');
        }

        return ['remote_id' => $id, 'remote_url' => 'https://www.facebook.com/'.$id];
    }

    private function firstImageUrl(SocialPost $post): string
    {
        $files = $post->imageFiles();

        if ($files === []) {
            throw new SocialPublishException('A Facebook image post needs an image.');
        }

        return SocialImage::jpegUrl($files[0]);
    }
}
