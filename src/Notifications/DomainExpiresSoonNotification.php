<?php

namespace Abigah\BotCopTrafficDivision\Notifications;

use Abigah\BotCopTrafficDivision\Models\Monitor;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\VonageMessage;

class DomainExpiresSoonNotification extends MonitoringNotification
{
    protected function monitor(): Monitor
    {
        return $this->subject;
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
