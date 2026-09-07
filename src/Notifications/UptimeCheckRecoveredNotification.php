<?php

namespace Abigah\BotCopTrafficDivision\Notifications;

use Abigah\BotCopTrafficDivision\Models\Monitor;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\VonageMessage;

class UptimeCheckRecoveredNotification extends MonitoringNotification
{
    protected function monitor(): Monitor
    {
        return $this->subject;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->withAction(
            (new MailMessage)
                ->success()
                ->greeting('Monitor recovered')
                ->subject("{$this->monitor()->url} has recovered")
                ->line("{$this->monitor()->url} has recovered."),
            'View monitor details',
        );
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'uptime_recovered',
            'monitor_id' => $this->monitor()->getKey(),
            'monitor_url' => (string) $this->monitor()->url,
        ];
    }

    public function toVonage(object $notifiable): VonageMessage
    {
        return (new VonageMessage)->content("{$this->monitor()->url} has recovered.");
    }
}
