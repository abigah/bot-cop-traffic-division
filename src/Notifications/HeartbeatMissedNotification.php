<?php

namespace Abigah\BotCopTrafficDivision\Notifications;

use Abigah\BotCopTrafficDivision\Enums\HeartbeatStatus;
use Abigah\BotCopTrafficDivision\Models\MonitorHeartbeat;
use Abigah\BotCopTrafficDivision\Support\PushMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\VonageMessage;

/**
 * Work that should have happened and did not.
 *
 * This only ever reaches a person when the site was up at the time. A job that
 * stopped because the whole site was down is part of that outage, and is folded
 * into the incident rather than paged separately — otherwise one outage would
 * arrive as a dozen separate alarms.
 */
class HeartbeatMissedNotification extends MonitoringNotification
{
    protected const PUSH_EVENT_TYPE = 'heartbeat_missing';

    protected function heartbeat(): MonitorHeartbeat
    {
        return $this->subject;
    }

    /**
     * Not headline(), which reads the heartbeat's status: a worker reloading
     * the heartbeat may find it running again, and the push would then say
     * something other than what happened. "Did not run as expected" is true of
     * a job that never ran, one that never finished and one that reported
     * failing. The last message stays out too — it is the job's own output.
     *
     * A job's name does not say which site it runs on, so the site's name goes
     * with it whenever the subscriber had one to hand in. A job with no name a
     * person could read is called by its number.
     */
    public function toPush(object $notifiable): PushMessage
    {
        return $this->buildPushMessage(
            self::PUSH_EVENT_TYPE,
            'Scheduled work missed',
            $this->pushBody(
                ':subject did not run as expected.',
                $this->pushNameForHeartbeat($this->heartbeat()),
                ':subject on :site did not run as expected.',
            ),
        );
    }

    protected function headline(): string
    {
        $heartbeat = $this->heartbeat();

        return match ($heartbeat->status) {
            HeartbeatStatus::TIMED_OUT => "{$heartbeat->name} started but never finished",
            HeartbeatStatus::FAILED => "{$heartbeat->name} reported a failure",
            default => "{$heartbeat->name} has not run",
        };
    }

    public function toMail(object $notifiable): MailMessage
    {
        $heartbeat = $this->heartbeat();

        $message = (new MailMessage)
            ->error()
            ->greeting('Scheduled work missed')
            ->subject($this->headline())
            ->line($this->headline().'.');

        if ($heartbeat->last_ping_at !== null) {
            $message->line("Last seen: {$heartbeat->last_ping_at->diffForHumans()}.");
        }

        if (filled($heartbeat->last_message)) {
            $message->line($heartbeat->last_message);
        }

        return $this->withAction($message, 'View site');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'heartbeat_missing',
            'heartbeat_id' => $this->heartbeat()->getKey(),
            'heartbeat_name' => $this->heartbeat()->name,
            'site_id' => $this->heartbeat()->site_id,
            'status' => $this->heartbeat()->status->value,
        ];
    }

    public function toVonage(object $notifiable): VonageMessage
    {
        return (new VonageMessage)->content($this->headline().'.');
    }
}
