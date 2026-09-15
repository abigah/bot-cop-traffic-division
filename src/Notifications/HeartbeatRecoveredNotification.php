<?php

namespace Abigah\BotCopTrafficDivision\Notifications;

use Abigah\BotCopTrafficDivision\Models\MonitorHeartbeat;
use Abigah\BotCopTrafficDivision\Support\PushMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\VonageMessage;

class HeartbeatRecoveredNotification extends MonitoringNotification
{
    protected const PUSH_EVENT_TYPE = 'heartbeat_recovered';

    protected function heartbeat(): MonitorHeartbeat
    {
        return $this->subject;
    }

    /**
     * Named with its site, when the subscriber had a site name to hand in, and
     * by its number when its own name gives a person nothing to read.
     */
    public function toPush(object $notifiable): PushMessage
    {
        return $this->buildPushMessage(
            self::PUSH_EVENT_TYPE,
            'Scheduled work resumed',
            $this->pushBody(
                ':subject is running again.',
                $this->pushNameForHeartbeat($this->heartbeat()),
                ':subject on :site is running again.',
            ),
        );
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
