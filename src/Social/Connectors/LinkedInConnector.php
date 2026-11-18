<?php

namespace CoderManjeet\SocialAutomation\Social\Connectors;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class LinkedInConnector implements SocialConnector
{
    public function redirectUrl(string $state): string
    {
        return 'https://www.linkedin.com/oauth/v2/authorization?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $this->config('client_id'),
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
            'scope' => implode(' ', array_merge($this->config('scopes', []), $this->config('organization_scopes', []))),
        ]);
    }

    public function candidates(Request $request): array
    {
        $code = (string) $request->query('code', '');

        if ($code === '') {
            throw new RuntimeException('Missing authorization code.');
        }

        $token = Http::asForm()->post('https://www.linkedin.com/oauth/v2/accessToken', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
            'client_id' => $this->config('client_id'),
            'client_secret' => $this->config('client_secret'),
        ])->throw()->json();

        $accessToken = (string) ($token['access_token'] ?? '');
        $expiresAt = isset($token['expires_in']) ? Carbon::now()->addSeconds((int) $token['expires_in']) : null;

        $profile = Http::withToken($accessToken)
            ->get('https://api.linkedin.com/v2/userinfo')
            ->throw()
            ->json();

        $meta = array_filter([
            'refresh_token' => $token['refresh_token'] ?? null,
            'scope' => $token['scope'] ?? null,
        ], fn (mixed $value): bool => filled($value));

        $candidates = [[
            'platform' => 'linkedin',
            'account_id' => 'urn:li:person:'.($profile['sub'] ?? ''),
            'name' => (string) ($profile['name'] ?? trim(($profile['given_name'] ?? '').' '.($profile['family_name'] ?? ''))),
            'token' => $accessToken,
            'expires_at' => $expiresAt,
            'meta' => $meta,
        ]];

        if ($this->config('organization_scopes', []) === []) {
            return $candidates;
        }

        $acls = Http::withToken($accessToken)
            ->withHeaders($this->headers())
            ->get('https://api.linkedin.com/rest/organizationAcls', [
                'q' => 'roleAssignee',
                'role' => 'ADMINISTRATOR',
                'state' => 'APPROVED',
            ])->throw()->json('elements', []);

        foreach ($acls as $acl) {
            $organization = (string) ($acl['organization'] ?? '');

            if ($organization === '') {
                continue;
            }

            $id = str_contains($organization, ':') ? substr($organization, strrpos($organization, ':') + 1) : $organization;

            $details = Http::withToken($accessToken)
                ->withHeaders($this->headers())
                ->get('https://api.linkedin.com/rest/organizations/'.$id)
                ->throw()
                ->json();

            $candidates[] = [
                'platform' => 'linkedin',
                'account_id' => 'urn:li:organization:'.$id,
                'name' => (string) ($details['localizedName'] ?? $id),
                'token' => $accessToken,
                'expires_at' => $expiresAt,
                'meta' => $meta,
            ];
        }

        return $candidates;
    }

    /**
     * @return array<string, string>
     */
    protected function headers(): array
    {
        return [
            'Linkedin-Version' => (string) config('content-automation.social.linkedin_version', '202610'),
            'X-Restli-Protocol-Version' => '2.0.0',
        ];
    }

    protected function redirectUri(): string
    {
        return route('admin.social.accounts.callback', ['connector' => 'linkedin']);
    }

    protected function config(string $key, mixed $default = null): mixed
    {
        return config('content-automation.social.oauth.linkedin.'.$key, $default);
    }
}
