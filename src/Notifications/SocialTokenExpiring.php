<?php

namespace CoderManjeet\SocialAutomation\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use CoderManjeet\SocialAutomation\Models\SocialAccount;

class SocialTokenExpiring extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  Collection<int, SocialAccount>  $accounts
     */
    public function __construct(public Collection $accounts) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('Social tokens expiring soon')
            ->greeting('Refresh these social tokens');

        foreach ($this->accounts as $account) {
            $message->line(sprintf(
                '%s (%s) expires %s',
                ucfirst($account->platform),
                $account->account_id,
                $account->expires_at?->toDayDateTimeString() ?? 'unknown',
            ));
        }

        return $message->action('Open social accounts', route('admin.social.accounts.index'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'platforms' => $this->accounts->pluck('platform')->all(),
        ];
    }
}
