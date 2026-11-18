<?php

namespace CoderManjeet\SocialAutomation\Social\Drivers;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use CoderManjeet\SocialAutomation\Models\SocialAccount;
use CoderManjeet\SocialAutomation\Models\SocialPost;
use CoderManjeet\SocialAutomation\Social\SocialImage;
use CoderManjeet\SocialAutomation\Social\SocialPublisher;
use CoderManjeet\SocialAutomation\Social\SocialPublishException;

class InstagramPublisher implements SocialPublisher
{
    public function supports(string $type): bool
    {
        return in_array($type, ['link', 'image', 'carousel'], true);
    }

    public function publish(SocialPost $post, SocialAccount $account): array
    {
        $version = config('content-automation.social.meta_graph_version', 'v26.0');
        $base = ($account->meta['auth'] ?? null) === 'instagram_login'
            ? 'https://graph.instagram.com/'.$version
            : 'https://graph.facebook.com/'.$version;
        $ig = $account->account_id;
        $caption = $post->captionFor('instagram') ?? '';

        $containerId = $post->type === 'carousel'
            ? $this->createCarouselContainer($base, $ig, $post, $account, $caption)
            : $this->createImageContainer($base, $ig, $post, $account, $caption);

        $this->waitUntilFinished($base, $containerId, $account);

        $publish = Http::timeout(30)->asForm()->withToken($account->token)
            ->post("{$base}/{$ig}/media_publish", ['creation_id' => $containerId])->throw();

        $mediaId = (string) $publish->json('id');

        if ($mediaId === '') {
            throw new SocialPublishException('Instagram did not return a media id.');
        }

        $permalink = Http::timeout(30)->withToken($account->token)
            ->get("{$base}/{$mediaId}", ['fields' => 'permalink'])->throw()
            ->json('permalink');

        return [
            'remote_id' => $mediaId,
            'remote_url' => is_string($permalink) && $permalink !== '' ? $permalink : null,
        ];
    }

    private function createImageContainer(string $base, string $ig, SocialPost $post, SocialAccount $account, string $caption): string
    {
        $files = $post->imageFiles();

        if ($files === []) {
            throw new SocialPublishException('An Instagram post needs an image.');
        }

        $response = Http::timeout(30)->asForm()->withToken($account->token)
            ->post("{$base}/{$ig}/media", [
                'image_url' => SocialImage::jpegUrl($files[0]),
                'caption' => $caption,
            ])->throw();

        return $this->containerId($response);
    }

    private function createCarouselContainer(string $base, string $ig, SocialPost $post, SocialAccount $account, string $caption): string
    {
        $files = $post->imageFiles();

        if (count($files) < 2 || count($files) > 10) {
            throw new SocialPublishException('An Instagram carousel needs 2 to 10 images.');
        }

        $children = [];

        foreach ($files as $file) {
            $response = Http::timeout(30)->asForm()->withToken($account->token)
                ->post("{$base}/{$ig}/media", [
                    'image_url' => SocialImage::jpegUrl($file),
                    'is_carousel_item' => 'true',
                ])->throw();

            $children[] = $this->containerId($response);
        }

        $response = Http::timeout(30)->asForm()->withToken($account->token)
            ->post("{$base}/{$ig}/media", [
                'media_type' => 'CAROUSEL',
                'children' => implode(',', $children),
                'caption' => $caption,
            ])->throw();

        return $this->containerId($response);
    }

    private function containerId(Response $response): string
    {
        $id = (string) $response->json('id');

        if ($id === '') {
            throw new SocialPublishException('Instagram did not return a container id.');
        }

        return $id;
    }

    private function waitUntilFinished(string $base, string $containerId, SocialAccount $account): void
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $status = Http::timeout(30)->withToken($account->token)
                ->get("{$base}/{$containerId}", ['fields' => 'status_code'])->throw()
                ->json('status_code');

            if ($status === 'FINISHED') {
                return;
            }

            if ($status === 'ERROR') {
                throw new SocialPublishException('Instagram could not process the media.');
            }

            if ($attempt < 10) {
                sleep(3);
            }
        }

        throw new SocialPublishException('Instagram media processing timed out.');
    }
}
