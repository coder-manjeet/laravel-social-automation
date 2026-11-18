<?php

namespace CoderManjeet\SocialAutomation\Social\Connectors;

use Illuminate\Http\Request;

interface SocialConnector
{
    public function redirectUrl(string $state): string;

    /**
     * @return list<array{platform:string, account_id:string, name:string, token:string, expires_at:?\DateTimeInterface, meta:array}>
     */
    public function candidates(Request $request): array;
}
