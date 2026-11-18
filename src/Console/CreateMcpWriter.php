<?php

namespace CoderManjeet\SocialAutomation\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use CoderManjeet\SocialAutomation\SocialAutomation;

class CreateMcpWriter extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'blog:create-mcp-writer {email : Email address of the Claude Writer account}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create or reset the unverified user who may use the blog MCP server';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = trim((string) $this->argument('email'));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('Provide a valid email address.');

            return self::FAILURE;
        }

        $password = Str::password(24);

        $user = SocialAutomation::model('user')::query()->firstOrNew(['email' => $email]);

        if (! $user->exists) {
            $user->name = 'Claude Writer';
        }

        $user->password = $password;
        $user->email_verified_at = null;
        $user->save();

        $this->info(($user->wasRecentlyCreated ? 'Created' : 'Updated')." the MCP writer account {$user->email}.");
        $this->line('Password (shown once): '.$password);
        $this->line('Set MCP_WRITER_EMAIL='.$user->email.' in .env so the gate allows this account.');

        return self::SUCCESS;
    }
}
