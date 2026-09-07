<?php

namespace Abigah\BotCopTrafficDivision\Concerns;

trait HasNotificationChannels
{
    /**
     * The active notification channels for a given event type.
     *
     * @return array<int, string>
     */
    public function channelsForEvent(string $eventType): array
    {
        if (! ($this->{$eventType} ?? false)) {
            return [];
        }

        $channels = [];

        if ($this->email_enabled) {
            $channels[] = 'mail';
        }

        if ($this->database_enabled) {
            $channels[] = 'database';
        }

        if ($this->sms_enabled) {
            $channels[] = 'vonage';
        }

        return $channels;
    }

    /**
     * Channels for an event, dropping vonage when the notifiable cannot receive
     * SMS.
     *
     * @return array<int, string>
     */
    public function channelsForEventAndUser(string $eventType, object $notifiable): array
    {
        $channels = $this->channelsForEvent($eventType);

        if (in_array('vonage', $channels, true) && ! $this->notifiableCanReceiveSms($notifiable)) {
            $channels = array_values(array_diff($channels, ['vonage']));
        }

        return $channels;
    }

    /**
     * Whether the notifiable can receive SMS. Override when eligibility is not
     * expressed by a `phone` attribute.
     */
    protected function notifiableCanReceiveSms(object $notifiable): bool
    {
        return ! empty($notifiable->phone);
    }
}
