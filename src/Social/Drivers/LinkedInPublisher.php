<?php

namespace CoderManjeet\SocialAutomation\Social\Drivers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use CoderManjeet\SocialAutomation\Models\SocialAccount;
use CoderManjeet\SocialAutomation\Models\SocialPost;
use CoderManjeet\SocialAutomation\Social\SocialImage;
use CoderManjeet\SocialAutomation\Social\SocialPublisher;
use CoderManjeet\SocialAutomation\Social\SocialPublishException;

class LinkedInPublisher implements SocialPublisher
{
    public function supports(string $type): bool
    {
        return in_array($type, ['link', 'image', 'carousel'], true);
    }

    public function publish(SocialPost $post, SocialAccount $account): array
    {
        $author = $account->account_id;
        $files = $post->imageFiles();
        $images = [];

        foreach ($files as $file) {
            $images[] = $this->uploadImage($account, $author, $file);
        }

        $body = [
            'author' => $author,
            'commentary' => $this->escape($post->captionFor('linkedin') ?? ''),
            'visibility' => 'PUBLIC',
            'distribution' => [
                'feedDistribution' => 'MAIN_FEED',
                'targetEntities' => [],
                'thirdPartyDistributionChannels' => [],
            ],
            'lifecycleState' => 'PUBLISHED',
            'isReshareDisabledByAuthor' => false,
        ];

        $title = (string) ($post->post?->title ?? '');
        $excerpt = (string) ($post->post?->excerpt ?? '');

        if (in_array($post->type, ['image', 'carousel'], true)) {
            if ($images === []) {
                throw new SocialPublishException('A LinkedIn image post needs an image.');
            }

            $body['content'] = $post->type === 'carousel'
                ? ['multiImage' => ['images' => array_map(fn (string $urn): array => ['id' => $urn, 'altText' => $title], $images)]]
                : ['media' => ['id' => $images[0], 'altText' => $title]];
        } else {
            $body['content'] = ['article' => [
                'source' => $post->linkFor('linkedin'),
                'title' => $title,
                'description' => $excerpt,
                ...($images !== [] ? ['thumbnail' => $images[0]] : []),
            ]];
        }

        $response = $this->request($account)
            ->post('https://api.linkedin.com/rest/posts', $body)->throw();

        $id = (string) $response->header('x-restli-id');

        if ($id === '') {
            throw new SocialPublishException('LinkedIn did not return a post id.');
        }

        return [
            'remote_id' => $id,
            'remote_url' => 'https://www.linkedin.com/feed/update/'.$id,
        ];
    }

    private function uploadImage(SocialAccount $account, string $author, string $path): string
    {
        $jpegPath = SocialImage::jpegPath($path, false);
        $contents = @file_get_contents($jpegPath);

        if ($contents === false) {
            throw new SocialPublishException('The image file could not be read.');
        }

        $init = $this->request($account)
            ->post('https://api.linkedin.com/rest/images?action=initializeUpload', [
                'initializeUploadRequest' => ['owner' => $author],
            ])->throw();

        $uploadUrl = (string) $init->json('value.uploadUrl');
        $image = (string) $init->json('value.image');

        if ($uploadUrl === '' || $image === '') {
            throw new SocialPublishException('LinkedIn did not return an upload URL.');
        }

        Http::timeout(30)
            ->withHeaders(['Authorization' => 'Bearer '.$account->token])
            ->withBody($contents, 'image/jpeg')
            ->put($uploadUrl)->throw();

        return $image;
    }

    private function request(SocialAccount $account): PendingRequest
    {
        return Http::timeout(30)
            ->withToken($account->token)
            ->withHeaders([
                'Linkedin-Version' => (string) config('content-automation.social.linkedin_version', '202610'),
                'X-Restli-Protocol-Version' => '2.0.0',
            ]);
    }

    /**
     * Apply LinkedIn's "little text" escaping rules.
     */
    private function escape(string $text): string
    {
        return (string) preg_replace('/([\\\\|{}\[\]()<>#*_~@])/', '\\\\$1', $text);
    }
}
