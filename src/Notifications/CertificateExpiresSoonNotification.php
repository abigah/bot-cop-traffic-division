<?php

namespace Abigah\BotCopTrafficDivision\Notifications;

use Abigah\BotCopTrafficDivision\Models\Monitor;
use Abigah\BotCopTrafficDivision\Support\PushMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\VonageMessage;

class CertificateExpiresSoonNotification extends MonitoringNotification
{
    protected const PUSH_EVENT_TYPE = 'certificate_expires_soon';

    protected function monitor(): Monitor
    {
        return $this->subject;
    }

    /**
     * No date: the expiry date is the monitor's current state, which a renewal
     * can change before a worker describes the push. The date is one tap away.
     */
    public function toPush(object $notifiable): PushMessage
    {
        return $this->buildPushMessage(
            self::PUSH_EVENT_TYPE,
            'Certificate expiring soon',
            $this->pushBody('The SSL certificate for :subject expires soon.', $this->pushNameForMonitor($this->monitor())),
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->withAction((new MailMessage)
            ->error()
            ->greeting('Certificate Expiring Soon')
            ->subject("SSL certificate for {$this->monitor()->url} expires soon")
            ->line("The SSL certificate for {$this->monitor()->url} expires on {$this->monitor()->certificate_expiration_date->format('d/m/Y')}."), 'View monitor details');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'certificate_expires_soon',
            'monitor_id' => $this->monitor()->id,
            'monitor_url' => (string) $this->monitor()->url,
            'expiration_date' => $this->monitor()->certificate_expiration_date?->toIso8601String(),
        ];
    }

    public function toVonage(object $notifiable): VonageMessage
    {
        return (new VonageMessage)
            ->content("SSL certificate for {$this->monitor()->url} expires on {$this->monitor()->certificate_expiration_date->format('d/m/Y')}.");
    }
}
