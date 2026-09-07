<?php

namespace Abigah\BotCopTrafficDivision\Notifications;

use Abigah\BotCopTrafficDivision\Models\Monitor;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\VonageMessage;

class CertificateExpiresSoonNotification extends MonitoringNotification
{
    protected function monitor(): Monitor
    {
        return $this->subject;
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
