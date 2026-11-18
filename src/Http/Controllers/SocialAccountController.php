<?php

namespace CoderManjeet\SocialAutomation\Http\Controllers;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use CoderManjeet\SocialAutomation\Models\SocialAccount;
use CoderManjeet\SocialAutomation\Social\Connectors\SocialConnector;
use Throwable;

class SocialAccountController
{
    /**
     * @var list<string>
     */
    protected array $platforms = ['facebook', 'instagram', 'linkedin'];

    public function index(Request $request): Response
    {
        $accounts = SocialAccount::query()->get()->keyBy('platform');
        $payload = $this->sessionPayload($request);

        return Inertia::render((string) config('content-automation.social.accounts_page', 'social/accounts'), [
            'accounts' => collect($this->platforms)->map(function (string $platform) use ($accounts): array {
                $account = $accounts->get($platform);
                $connector = $this->connectorFor($platform, $account);
                $isInstagram = $platform === 'instagram';

                return [
                    'platform' => $platform,
                    'connected' => $account !== null,
                    'name' => $account?->name,
                    'account_id' => $account?->account_id,
                    'expires_at' => $account?->expires_at?->toIso8601String(),
                    'expiring_soon' => $account?->expires_at !== null && $account->expires_at->lte(now()->addDays(7)),
                    'connected_at' => $account?->connected_at?->toIso8601String(),
                    'auth' => $account?->meta['auth'] ?? null,
                    'connector' => $connector,
                    'connector_configured' => $this->isConfigured($connector),
                    'redirect_url' => route('admin.social.accounts.redirect', ['connector' => $connector]),
                    'instagram_redirect_url' => $isInstagram ? route('admin.social.accounts.redirect', ['connector' => 'instagram']) : null,
                    'facebook_redirect_url' => $isInstagram ? route('admin.social.accounts.redirect', ['connector' => 'meta']) : null,
                    'instagram_configured' => $isInstagram ? $this->isConfigured('instagram') : false,
                    'facebook_configured' => $isInstagram ? $this->isConfigured('meta') : false,
                    'disconnect_url' => route('admin.social.accounts.destroy', ['platform' => $platform]),
                ];
            })->values()->all(),
            'connector' => $payload['connector'] ?? null,
            'candidates' => $payload === null ? [] : collect($payload['candidates'])
                ->map(fn (array $candidate): array => [
                    'platform' => $candidate['platform'],
                    'account_id' => $candidate['account_id'],
                    'name' => $candidate['name'],
                ])->values()->all(),
            'choose_url' => $payload === null ? null : route('admin.social.accounts.choose', ['connector' => $payload['connector']]),
        ]);
    }

    public function redirect(Request $request, string $connector): RedirectResponse
    {
        $state = bin2hex(random_bytes(20));
        $request->session()->put('social.connect.state', $state);
        $request->session()->forget('social.connect.candidates');

        return redirect()->away($this->connector($connector)->redirectUrl($state));
    }

    public function callback(Request $request, string $connector): RedirectResponse
    {
        if ($request->filled('error')) {
            return $this->failed($request->string('error_description')->value() ?: $request->string('error')->value());
        }

        $expected = (string) $request->session()->get('social.connect.state');

        if ($expected === '' || ! hash_equals($expected, (string) $request->query('state'))) {
            abort(403);
        }

        $request->session()->forget('social.connect.state');

        try {
            $candidates = $this->connector($connector)->candidates($request);
        } catch (Throwable $exception) {
            Log::error('Social account connect failed.', [
                'connector' => $connector,
                'message' => $exception->getMessage(),
            ]);

            return $this->failed($exception->getMessage() ?: 'Could not connect the account. Check the app configuration and try again.');
        }

        if ($candidates === []) {
            return $this->failed('No accounts were found for this login.');
        }

        $allSingle = collect($candidates)->countBy('platform')->every(fn (int $count): bool => $count === 1);

        if ($allSingle) {
            $this->save($candidates, $request->user()?->getAuthIdentifier());
            $request->session()->forget('social.connect.candidates');

            return $this->connected();
        }

        $request->session()->put('social.connect.candidates', Crypt::encryptString(json_encode([
            'connector' => $connector,
            'candidates' => $candidates,
        ], JSON_THROW_ON_ERROR)));

        return redirect()->route('admin.social.accounts.index')
            ->with('success', 'Choose the accounts to connect.');
    }

    public function choose(Request $request, string $connector): RedirectResponse
    {
        $payload = $this->sessionPayload($request);

        abort_if($payload === null || $payload['connector'] !== $connector, 404);

        $validated = $request->validate([
            'selected' => ['required', 'array'],
        ]);

        $chosen = collect($payload['candidates'])
            ->filter(fn (array $candidate): bool => ($validated['selected'][$candidate['platform']] ?? null) === $candidate['account_id'])
            ->values()
            ->all();

        if ($chosen === []) {
            return $this->failed('Select at least one account.');
        }

        $this->save($chosen, $request->user()?->getAuthIdentifier());
        $request->session()->forget('social.connect.candidates');

        return $this->connected();
    }

    public function destroy(string $platform): RedirectResponse
    {
        SocialAccount::query()->where('platform', $platform)->delete();

        return redirect()->route('admin.social.accounts.index')
            ->with('success', 'Account disconnected.');
    }

    /**
     * @param  list<array{platform:string, account_id:string, name:string, token:string, expires_at:mixed, meta:array}>  $candidates
     */
    protected function save(array $candidates, mixed $connectedBy): void
    {
        foreach ($candidates as $candidate) {
            SocialAccount::updateOrCreate(
                ['platform' => $candidate['platform']],
                [
                    'account_id' => $candidate['account_id'],
                    'name' => $candidate['name'],
                    'token' => $candidate['token'],
                    'expires_at' => $candidate['expires_at'] === null ? null : Carbon::parse($candidate['expires_at']),
                    'meta' => $candidate['meta'],
                    'connected_by' => $connectedBy,
                    'connected_at' => now(),
                ],
            );
        }
    }

    protected function connected(): RedirectResponse
    {
        return redirect()->route('admin.social.accounts.index')
            ->with('success', 'Social account connected.');
    }

    protected function failed(string $message): RedirectResponse
    {
        return redirect()->route('admin.social.accounts.index')->with('error', $message);
    }

    protected function connector(string $key): SocialConnector
    {
        $class = config('content-automation.social.connectors.'.$key);
        abort_unless(is_string($class) && class_exists($class), 404);

        return app($class);
    }

    protected function connectorFor(string $platform, ?SocialAccount $account = null): string
    {
        if ($platform === 'linkedin') {
            return 'linkedin';
        }

        if ($platform === 'instagram' && ($account?->meta['auth'] ?? null) === 'instagram_login') {
            return 'instagram';
        }

        return 'meta';
    }

    protected function isConfigured(string $connector): bool
    {
        $keys = $connector === 'linkedin' ? ['client_id', 'client_secret'] : ['app_id', 'app_secret'];

        foreach ($keys as $key) {
            if (blank(config('content-automation.social.oauth.'.$connector.'.'.$key))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{connector:string, candidates:list<array<string, mixed>>}|null
     */
    protected function sessionPayload(Request $request): ?array
    {
        $encrypted = $request->session()->get('social.connect.candidates');

        if (! is_string($encrypted) || $encrypted === '') {
            return null;
        }

        try {
            $payload = json_decode(Crypt::decryptString($encrypted), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException) {
            return null;
        }

        return is_array($payload) && isset($payload['connector'], $payload['candidates']) ? $payload : null;
    }
}
