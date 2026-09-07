<?php

namespace Abigah\BotCopTrafficDivision\Notifications;

use Abigah\BotCopTrafficDivision\Models\MonitorSiteException;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\VonageMessage;
use Illuminate\Support\Str;

/**
 * A kind of server error nobody has seen before.
 *
 * Only new fingerprints get here. One that keeps firing becomes a number going
 * up on the site's page, which is the whole difference between this and an
 * error tracker.
 */
class SiteExceptionReportedNotification extends MonitoringNotification
{
    protected function exception(): MonitorSiteException
    {
        return $this->subject;
    }

    protected function shortClass(): string
    {
        return class_basename(str_replace('\\', '/', $this->exception()->exception_class));
    }

    public function toMail(object $notifiable): MailMessage
    {
        $exception = $this->exception();

        $message = (new MailMessage)
            ->error()
            ->greeting('New server error')
            ->subject("New error: {$this->shortClass()}")
            ->line("A kind of error that has not been seen here before: {$exception->exception_class}.")
            ->line((string) $exception->message);

        if (filled($exception->file)) {
            $message->line("At {$exception->file}:{$exception->line}");
        }

        return $this->withAction($message, 'View site errors');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'exception_reported',
            'exception_id' => $this->exception()->getKey(),
            'site_id' => $this->exception()->site_id,
            'fingerprint' => $this->exception()->fingerprint,
            'exception_class' => $this->exception()->exception_class,
        ];
    }

    public function toVonage(object $notifiable): VonageMessage
    {
        return (new VonageMessage)->content(
            "New server error: {$this->shortClass()} — ".Str::limit((string) $this->exception()->message, 80)
        );
    }
}
