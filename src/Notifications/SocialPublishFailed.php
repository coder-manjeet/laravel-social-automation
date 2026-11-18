<?php

namespace CoderManjeet\SocialAutomation\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use CoderManjeet\SocialAutomation\Models\SocialPostTarget;

class SocialPublishFailed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public SocialPostTarget $target) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $title = $this->target->socialPost?->post?->title ?? 'Social post';

        return (new MailMessage)
            ->subject('Social publish failed: '.$title)
            ->greeting('A social post could not be published')
            ->line('Post: '.$title)
            ->line('Platform: '.ucfirst($this->target->platform))
            ->line('Error: '.($this->target->last_error ?? 'Unknown error'))
            ->action('Open social dashboard', route('admin.social.index'))
            ->line('Retry the post from the dashboard once the API problem is fixed.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'social_post_id' => $this->target->social_post_id,
            'platform' => $this->target->platform,
            'error' => $this->target->last_error,
        ];
    }
}
