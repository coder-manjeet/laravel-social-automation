<?php

namespace CoderManjeet\SocialAutomation\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use CoderManjeet\SocialAutomation\Models\SocialAccount;
use CoderManjeet\SocialAutomation\Notifications\SocialTokenExpiring;
use CoderManjeet\SocialAutomation\Social\SocialPostManager;
use RuntimeException;
use Throwable;

class CheckSocialTokens extends Command
{
    protected $signature = 'social:check-tokens';

    protected $description = 'Email admins when a social token expires within seven days.';

    public function handle(SocialPostManager $manager): int
    {
        $accounts = SocialAccount::query()
            ->whereNotNull('expires_at')
            ->get()
            ->filter(function (SocialAccount $account): bool {
                if (($account->meta['auth'] ?? null) !== 'instagram_login') {
                    return $account->expires_at->lte(now()->addDays(7));
                }

                if ($account->expires_at->lte(now())) {
                    return true;
                }

                if ($account->expires_at->gt(now()->addDays(15))) {
                    return false;
                }

                try {
                    $response = Http::get('https://graph.instagram.com/refresh_access_token', [
                        'grant_type' => 'ig_refresh_token',
                        'access_token' => $account->token,
                    ])->throw()->json();

                    $token = (string) ($response['access_token'] ?? '');
                    $expiresIn = (int) ($response['expires_in'] ?? 0);

                    if ($token === '' || $expiresIn <= 0) {
                        throw new RuntimeException('Instagram did not return a refreshed token.');
                    }

                    $account->update([
                        'token' => $token,
                        'expires_at' => now()->addSeconds($expiresIn),
                    ]);

                    return false;
                } catch (Throwable $exception) {
                    Log::warning('Instagram token refresh failed.', [
                        'account_id' => $account->account_id,
                        'message' => $exception->getMessage(),
                    ]);

                    return true;
                }
            })
            ->values();

        if ($accounts->isEmpty()) {
            return self::SUCCESS;
        }

        $recipients = $manager->notifyEmails();

        if ($recipients === []) {
            $this->warn('No social notification recipients configured.');

            return self::SUCCESS;
        }

        Notification::route('mail', $recipients)
            ->notify(new SocialTokenExpiring($accounts));

        return self::SUCCESS;
    }
}
