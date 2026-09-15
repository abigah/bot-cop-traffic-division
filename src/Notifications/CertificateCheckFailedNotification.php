<?php

namespace Abigah\BotCopTrafficDivision\Notifications;

use Abigah\BotCopTrafficDivision\Models\Monitor;
use Abigah\BotCopTrafficDivision\Support\PushMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\VonageMessage;
use Illuminate\Support\Str;

class CertificateCheckFailedNotification extends MonitoringNotification
{
    /**
     * The push's event type. toArray() keeps its older `certificate_check_failed`
     * for the database channel's existing rows; a push uses the subscriber's name.
     */
    protected const PUSH_EVENT_TYPE = 'certificate_failed';

    protected function monitor(): Monitor
    {
        return $this->subject;
    }

    /**
     * The failure reason stays out: it is handshake output, not a name.
     */
    public function toPush(object $notifiable): PushMessage
    {
        return $this->buildPushMessage(
            self::PUSH_EVENT_TYPE,
            'Certificate check failed',
            $this->pushBody('The SSL certificate for :subject is invalid.', $this->pushNameForMonitor($this->monitor())),
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->withAction((new MailMessage)
            ->error()
            ->greeting('Certificate Check Failed')
            ->subject("SSL certificate for {$this->monitor()->url} is invalid")
            ->line("The SSL certificate for {$this->monitor()->url} is invalid.")
            ->line("Reason: {$this->monitor()->certificate_check_failure_reason}"), 'View monitor details');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'certificate_check_failed',
            'monitor_id' => $this->monitor()->id,
            'monitor_url' => (string) $this->monitor()->url,
            'failure_reason' => $this->monitor()->certificate_check_failure_reason,
        ];
    }

    public function toVonage(object $notifiable): VonageMessage
    {
        return (new VonageMessage)
            ->content("SSL certificate for {$this->monitor()->url} is invalid: ".Str::before($this->monitor()->certificate_check_failure_reason, "\n"));
    }
}
