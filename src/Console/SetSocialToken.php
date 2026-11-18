<?php

namespace CoderManjeet\SocialAutomation\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use CoderManjeet\SocialAutomation\Models\SocialAccount;

class SetSocialToken extends Command
{
    protected $signature = 'social:set-token {platform} {account_id} {--expires=}';

    protected $description = 'Store an access token for a social platform account.';

    public function handle(): int
    {
        $platform = (string) $this->argument('platform');
        $token = (string) $this->secret('Token');

        if (trim($token) === '') {
            $this->error('A token is required.');

            return self::FAILURE;
        }

        $expires = $this->option('expires');

        SocialAccount::updateOrCreate(
            ['platform' => $platform],
            [
                'account_id' => (string) $this->argument('account_id'),
                'token' => trim($token),
                'expires_at' => filled($expires) ? Carbon::parse($expires) : null,
            ],
        );

        $this->info("Saved the {$platform} token.");

        return self::SUCCESS;
    }
}
