<?php

namespace CoderManjeet\SocialAutomation\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PostSubmittedForReview extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Model $post) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $timezone = config('content-automation.blog.timezone', 'Asia/Kolkata');

        $schedule = $this->post->scheduled_at
            ? $this->post->scheduled_at->setTimezone($timezone)->format('d M Y, H:i T')
                .' ('.$this->post->scheduled_at->utc()->format('d M Y, H:i \U\T\C').')'
            : 'Not scheduled';

        $author = $this->post->author?->name
            ?? $this->post->user?->name
            ?? 'Unknown';

        return (new MailMessage)
            ->subject('Blog post ready for review: '.$this->post->title)
            ->greeting('A blog post is waiting for review')
            ->line('Title: '.$this->post->title)
            ->line('Author: '.$author)
            ->line('Intended schedule: '.$schedule)
            ->action('Review post', route('posts.edit', $this->post))
            ->line('Approve it or request changes from the admin.');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'post_id' => $this->post->id,
            'title' => $this->post->title,
            'scheduled_at' => $this->post->scheduled_at?->toIso8601String(),
        ];
    }
}
