<?php

namespace Abigah\BotCopTrafficDivision\Notifications;

use Abigah\BotCopTrafficDivision\Facades\Monitoring;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Everything this package sends, whatever it is about.
 *
 * The channels are decided before the notification is built — by the recipient's
 * own preferences and by whether they have muted the outage — so `via()` has
 * nothing left to work out.
 *
 * The subject is a monitor, a heartbeat or a reported exception. What they have
 * in common is that a person wants to be told, and that the same mute covers
 * all of them.
 */
abstract class MonitoringNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  array<int, string>  $channels */
    public function __construct(
        public Model $subject,
        public array $channels,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    /**
     * Add the "go and look" button, when this application has somewhere to
     * point at. An install with no UI wired still sends a usable email.
     */
    protected function withAction(MailMessage $message, string $label = 'View in monitoring'): MailMessage
    {
        $url = Monitoring::urlFor($this->subject);

        return $url === null ? $message : $message->action($label, $url);
    }
}
