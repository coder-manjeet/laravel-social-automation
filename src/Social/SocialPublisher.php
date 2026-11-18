<?php

namespace CoderManjeet\SocialAutomation\Social;

use CoderManjeet\SocialAutomation\Models\SocialAccount;
use CoderManjeet\SocialAutomation\Models\SocialPost;

interface SocialPublisher
{
    public function supports(string $type): bool;

    /**
     * @return array{remote_id:string, remote_url:?string}
     */
    public function publish(SocialPost $post, SocialAccount $account): array;
}
