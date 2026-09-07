<?php

namespace Abigah\BotCopTrafficDivision\Notifications;

use Abigah\BotCopTrafficDivision\Models\Monitor;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\VonageMessage;
use Illuminate\Support\Str;

class CertificateCheckFailedNotification extends MonitoringNotification
{
    protected function monitor(): Monitor
    {
        return $this->subject;
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
