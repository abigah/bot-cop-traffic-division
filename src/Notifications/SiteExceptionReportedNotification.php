<?php

namespace Abigah\BotCopTrafficDivision\Notifications;

use Abigah\BotCopTrafficDivision\Models\MonitorSiteException;
use Abigah\BotCopTrafficDivision\Support\PushMessage;
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
    protected const PUSH_EVENT_TYPE = 'exception_reported';

    protected function exception(): MonitorSiteException
    {
        return $this->subject;
    }

    /**
     * Named by its class alone.
     *
     * The message is left out on purpose. It is whatever the reporting site's
     * code put there — an email address, a key, a query with its values — and a
     * push is shown on a lock screen after passing through a delivery provider.
     * The class is code rather than data, and the full error, with its message,
     * file and trace, is one tap away behind the recipient's own sign-in.
     *
     * The site is named by the name the subscriber read when the event
     * happened, whenever it had one, so describing the push never asks for it.
     *
     * A class that gives a person nothing to read, or leaves nothing to read
     * once it gives way to the rest of the sentence, is said to be unnamed,
     * rather than leaving a gap where its name would go.
     */
    public function toPush(object $notifiable): PushMessage
    {
        return $this->buildPushMessage(
            self::PUSH_EVENT_TYPE,
            'New server error',
            $this->pushBody(
                'A kind of error not seen here before: :subject.',
                (string) $this->pushNameForException($this->exception()),
                'A kind of error not seen on :site before: :subject.',
                unnamedSentence: 'An unnamed kind of error not seen here before.',
                unnamedSiteSentence: 'An unnamed kind of error not seen on :site before.',
            ),
        );
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
