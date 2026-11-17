<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One notification type for every platform event: stored for the in-app bell
 * and emailed with the branded layout.
 *
 * Email is queued: each SMTP send takes 1-3 s, and a single action can notify many users
 * (every bidder, every eligible investor), which used to hold the request — and, on the
 * single-threaded dev server, every other request — for tens of seconds. The in-app copy
 * is still written immediately. With QUEUE_CONNECTION=sync nothing changes.
 */
class PlatformNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Seconds between retries when the mail server is unavailable. */
    public array $backoff = [30, 120];

    public string $title;

    public string $message;

    public ?string $url;

    public array $options;

    public function __construct(string $title, string $message, ?string $url = null, array $options = [])
    {
        $this->title = $title;
        $this->message = $message;
        $this->url = $url;
        $this->options = $options;
    }

    public function via($notifiable): array
    {
        if (! empty($this->options['mail_only'])) {
            return ['mail'];
        }

        return ['database', 'mail'];
    }

    /** The bell entry is stored in-request; only the email goes to the queue. */
    public function viaConnections(): array
    {
        return [
            'database' => 'sync',
            'mail' => config('queue.default'),
        ];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject($this->title)
            ->view('emails.notification', [
                'title' => $this->title,
                'body' => $this->message,
                'url' => $this->url,
                'code' => $this->options['code'] ?? null,
                'button' => $this->options['button'] ?? 'Open e-Offering',
                'name' => $notifiable->name ?? null,
            ]);
    }

    public function toArray($notifiable): array
    {
        return [
            'title' => $this->title,
            'message' => $this->message,
            'url' => $this->url,
        ];
    }
}
