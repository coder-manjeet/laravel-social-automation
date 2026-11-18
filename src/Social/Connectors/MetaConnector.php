<?php

namespace CoderManjeet\SocialAutomation\Social\Connectors;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MetaConnector implements SocialConnector
{
    public function redirectUrl(string $state): string
    {
        $query = [
            'client_id' => $this->config('app_id'),
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
            'response_type' => 'code',
        ];

        $loginConfigId = $this->config('login_config_id');

        if (filled($loginConfigId)) {
            $query['config_id'] = $loginConfigId;
        } else {
            $query['scope'] = implode(',', $this->config('scopes', []));
        }

        return 'https://www.facebook.com/'.$this->version().'/dialog/oauth?'.http_build_query($query);
    }

    public function candidates(Request $request): array
    {
        $code = (string) $request->query('code', '');

        if ($code === '') {
            throw new RuntimeException('Missing authorization code.');
        }

        $shortToken = Http::get($this->graphUrl('/oauth/access_token'), [
            'client_id' => $this->config('app_id'),
            'redirect_uri' => $this->redirectUri(),
            'client_secret' => $this->config('app_secret'),
            'code' => $code,
        ])->throw()->json('access_token');

        $userToken = Http::get($this->graphUrl('/oauth/access_token'), [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $this->config('app_id'),
            'client_secret' => $this->config('app_secret'),
            'fb_exchange_token' => $shortToken,
        ])->throw()->json('access_token');

        $pages = Http::get($this->graphUrl('/me/accounts'), [
            'fields' => 'id,name,access_token,instagram_business_account{id,username}',
            'access_token' => $userToken,
        ])->throw()->json('data', []);

        $candidates = [];

        foreach ($pages as $page) {
            $pageToken = $page['access_token'] ?? $userToken;

            $candidates[] = [
                'platform' => 'facebook',
                'account_id' => (string) $page['id'],
                'name' => (string) $page['name'],
                'token' => (string) $pageToken,
                'expires_at' => null,
                'meta' => ['auth' => 'facebook_page', 'page_id' => (string) $page['id']],
            ];

            $instagram = $page['instagram_business_account'] ?? null;

            if (is_array($instagram) && filled($instagram['id'] ?? null)) {
                $candidates[] = [
                    'platform' => 'instagram',
                    'account_id' => (string) $instagram['id'],
                    'name' => isset($instagram['username']) ? '@'.$instagram['username'] : (string) $page['name'],
                    'token' => (string) $pageToken,
                    'expires_at' => null,
                    'meta' => ['auth' => 'facebook_page', 'page_id' => (string) $page['id']],
                ];
            }
        }

        return $candidates;
    }

    protected function redirectUri(): string
    {
        return route('admin.social.accounts.callback', ['connector' => 'meta']);
    }

    protected function graphUrl(string $path): string
    {
        return 'https://graph.facebook.com/'.$this->version().$path;
    }

    protected function version(): string
    {
        return (string) config('content-automation.social.meta_graph_version', 'v26.0');
    }

    protected function config(string $key, mixed $default = null): mixed
    {
        return config('content-automation.social.oauth.meta.'.$key, $default);
    }
}
