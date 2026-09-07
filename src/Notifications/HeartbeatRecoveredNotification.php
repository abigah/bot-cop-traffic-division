<?php

namespace Abigah\BotCopTrafficDivision\Notifications;

use Abigah\BotCopTrafficDivision\Models\MonitorHeartbeat;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\VonageMessage;

class HeartbeatRecoveredNotification extends MonitoringNotification
{
    protected function heartbeat(): MonitorHeartbeat
    {
        return $this->subject;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->withAction(
            (new MailMessage)
                ->success()
                ->greeting('Scheduled work resumed')
                ->subject("{$this->heartbeat()->name} is running again")
                ->line("{$this->heartbeat()->name} is running again."),
            'View site',
        );
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'heartbeat_recovered',
            'heartbeat_id' => $this->heartbeat()->getKey(),
            'heartbeat_name' => $this->heartbeat()->name,
            'site_id' => $this->heartbeat()->site_id,
        ];
    }

    public function toVonage(object $notifiable): VonageMessage
    {
        return (new VonageMessage)->content("{$this->heartbeat()->name} is running again.");
    }
}
