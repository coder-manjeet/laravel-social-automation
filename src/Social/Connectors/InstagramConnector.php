<?php

namespace CoderManjeet\SocialAutomation\Social\Connectors;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class InstagramConnector implements SocialConnector
{
    public function redirectUrl(string $state): string
    {
        $query = [
            'client_id' => $this->config('app_id'),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => implode(',', $this->config('scopes', [])),
            'state' => $state,
        ];

        return 'https://www.instagram.com/oauth/authorize?'.http_build_query($query);
    }

    public function candidates(Request $request): array
    {
        $code = (string) $request->query('code', '');
        $code = str_ends_with($code, '#_') ? substr($code, 0, -2) : $code;

        if ($code === '') {
            throw new RuntimeException('Missing authorization code.');
        }

        $short = Http::asForm()->post('https://api.instagram.com/oauth/access_token', [
            'client_id' => $this->config('app_id'),
            'client_secret' => $this->config('app_secret'),
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirectUri(),
            'code' => $code,
        ])->throw()->json();

        $shortToken = (string) ($short['access_token'] ?? '');
        $userId = (string) ($short['user_id'] ?? '');

        if ($shortToken === '' || $userId === '') {
            throw new RuntimeException('Instagram did not return an access token.');
        }

        $long = Http::get('https://graph.instagram.com/access_token', [
            'grant_type' => 'ig_exchange_token',
            'client_secret' => $this->config('app_secret'),
            'access_token' => $shortToken,
        ])->throw()->json();

        $token = (string) ($long['access_token'] ?? '');
        $expiresIn = (int) ($long['expires_in'] ?? 0);

        if ($token === '' || $expiresIn <= 0) {
            throw new RuntimeException('Instagram did not return a long-lived token.');
        }

        $profile = Http::get('https://graph.instagram.com/'.$this->version().'/me', [
            'fields' => 'user_id,username,account_type',
            'access_token' => $token,
        ])->throw()->json();

        if (! in_array($profile['account_type'] ?? null, ['BUSINESS', 'MEDIA_CREATOR'], true)) {
            throw new RuntimeException('Switch Instagram to a Professional account first');
        }

        return [[
            'platform' => 'instagram',
            'account_id' => (string) ($profile['user_id'] ?? $userId),
            'name' => '@'.(string) ($profile['username'] ?? ''),
            'token' => $token,
            'expires_at' => now()->addSeconds($expiresIn),
            'meta' => ['auth' => 'instagram_login'],
        ]];
    }

    protected function redirectUri(): string
    {
        return route('admin.social.accounts.callback', ['connector' => 'instagram']);
    }

    protected function version(): string
    {
        return (string) config('content-automation.social.meta_graph_version', 'v26.0');
    }

    protected function config(string $key, mixed $default = null): mixed
    {
        return config('content-automation.social.oauth.instagram.'.$key, $default);
    }
}
