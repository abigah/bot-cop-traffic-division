<?php

namespace Abigah\BotCopTrafficDivision\Notifications;

use Abigah\BotCopTrafficDivision\Models\Monitor;
use Abigah\BotCopTrafficDivision\Models\MonitorIncident;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\VonageMessage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class UptimeCheckFailedNotification extends MonitoringNotification
{
    protected function monitor(): Monitor
    {
        return $this->subject;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $monitor = $this->monitor();

        $message = (new MailMessage)
            ->error()
            ->greeting('Uptime check failed')
            ->subject("{$monitor->url} seems down")
            ->line("{$monitor->url} seems down.")
            ->line("Failure reason: {$monitor->uptime_check_failure_reason}");

        if ($muteUrl = $this->muteUrl($notifiable)) {
            $message->line(
                "Getting too many messages about this outage? [Mute them]({$muteUrl}) — "
                ."they resume automatically when {$monitor->url} recovers."
            );
        }

        // Last: a MailMessage puts every line after an action below the button,
        // and the way out of an alert at 3am belongs in the body, not the
        // footer.
        return $this->withAction($message, 'View monitor details');
    }

    /**
     * A signed, per-recipient link that silences this one outage. It mutes
     * every channel, not just email: an outage that stopped mailing but kept
     * sending SMS would not be muted in any sense the recipient meant.
     */
    protected function muteUrl(object $notifiable): ?string
    {
        $incident = MonitorIncident::query()
            ->where('monitor_id', $this->monitor()->getKey())
            ->ongoing()
            ->latest('started_at')
            ->first();

        if ($incident === null) {
            return null;
        }

        return URL::signedRoute('monitoring.incident.mute', [
            'incident' => $incident->getKey(),
            'notifiable' => $notifiable->getKey(),
        ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'uptime_failed',
            'monitor_id' => $this->monitor()->getKey(),
            'monitor_url' => (string) $this->monitor()->url,
            'failure_reason' => $this->monitor()->uptime_check_failure_reason,
        ];
    }

    public function toVonage(object $notifiable): VonageMessage
    {
        $reason = Str::before((string) $this->monitor()->uptime_check_failure_reason, "\n");

        if (preg_match('/(\d{3})\s+(\w[\w\s]*)/', $reason, $matches)) {
            $reason = $matches[1].' '.trim($matches[2]);
        }

        return (new VonageMessage)->content("{$this->monitor()->url} is down: {$reason}");
    }
}
