<?php

namespace Abigah\BotCopTrafficDivision\Notifications;

use Abigah\BotCopTrafficDivision\Models\Monitor;
use Abigah\BotCopTrafficDivision\Support\PushMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\VonageMessage;

class DomainExpiresSoonNotification extends MonitoringNotification
{
    protected const PUSH_EVENT_TYPE = 'domain_expires_soon';

    protected function monitor(): Monitor
    {
        return $this->subject;
    }

    /**
     * No date, days left or registrar: those are the monitor's current state,
     * and the days left would change with the hour a worker got round to it.
     */
    public function toPush(object $notifiable): PushMessage
    {
        return $this->buildPushMessage(
            self::PUSH_EVENT_TYPE,
            'Domain expiring soon',
            $this->pushBody('The domain registration for :subject expires soon.', $this->pushNameForMonitor($this->monitor())),
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $daysLeft = (int) now()->diffInDays($this->monitor()->domain_expiration_date, false);

        return $this->withAction((new MailMessage)
            ->error()
            ->greeting('Domain Expiring Soon')
            ->subject("Domain registration for {$this->monitor()->url} expires soon")
            ->line("The domain registration for {$this->monitor()->url} expires on {$this->monitor()->domain_expiration_date->format('M j, Y')} ({$daysLeft} days).")
            ->when($this->monitor()->domain_registrar, fn (MailMessage $mail) => $mail->line("Registrar: {$this->monitor()->domain_registrar}")), 'View monitor details');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'domain_expires_soon',
            'monitor_id' => $this->monitor()->id,
            'monitor_url' => (string) $this->monitor()->url,
            'expiration_date' => $this->monitor()->domain_expiration_date?->toIso8601String(),
            'registrar' => $this->monitor()->domain_registrar,
        ];
    }

    public function toVonage(object $notifiable): VonageMessage
    {
        $daysLeft = (int) now()->diffInDays($this->monitor()->domain_expiration_date, false);

        return (new VonageMessage)
            ->content("Domain for {$this->monitor()->url} expires on {$this->monitor()->domain_expiration_date->format('M j, Y')} ({$daysLeft} days).");
    }
}
