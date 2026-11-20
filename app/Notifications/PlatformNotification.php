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

    public int $tries = 6;

    /**
     * Seconds between retries when the mail server is unavailable (e.g. a DNS or network
     * blip): retries over about an hour before the job is given up and logged as failed.
     */
    public array $backoff = [30, 120, 300, 900, 1800];

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
        // Email subjects, headings and buttons are title case, as across the web app.
        $title = titleCase($this->title);

        return (new MailMessage())
            ->subject($title)
            ->view('emails.notification', [
                'title' => $title,
                'body' => $this->message,
                'url' => $this->url,
                'code' => $this->options['code'] ?? null,
                'button' => titleCase($this->options['button'] ?? 'Open e-Offering'),
                // On-demand (address-only) recipients have no model, so the sender passes the name.
                'name' => $this->options['name'] ?? $notifiable->name ?? null,
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
