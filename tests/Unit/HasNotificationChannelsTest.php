<?php

use Abigah\BotCopTrafficDivision\Concerns\HasNotificationChannels;

function preference(array $attributes = []): object
{
    return new class($attributes)
    {
        use HasNotificationChannels;

        public function __construct(array $attributes)
        {
            foreach ($attributes as $key => $value) {
                $this->{$key} = $value;
            }
        }
    };
}

function notifiable(?string $phone = null): object
{
    return new class($phone)
    {
        public function __construct(public ?string $phone) {}
    };
}

it('returns no channels when the event type is disabled', function () {
    $preference = preference([
        'uptime_check_failed' => false,
        'email_enabled' => true,
        'database_enabled' => true,
        'sms_enabled' => true,
    ]);

    expect($preference->channelsForEvent('uptime_check_failed'))->toBe([]);
});

it('returns each enabled channel for the event', function () {
    $preference = preference([
        'uptime_check_failed' => true,
        'email_enabled' => true,
        'database_enabled' => true,
        'sms_enabled' => true,
    ]);

    expect($preference->channelsForEvent('uptime_check_failed'))
        ->toBe(['mail', 'database', 'vonage']);
});

it('omits channels that are disabled', function () {
    $preference = preference([
        'uptime_check_failed' => true,
        'email_enabled' => true,
        'database_enabled' => false,
        'sms_enabled' => false,
    ]);

    expect($preference->channelsForEvent('uptime_check_failed'))->toBe(['mail']);
});

it('filters out vonage when the notifiable has no phone number', function () {
    $preference = preference([
        'uptime_check_failed' => true,
        'email_enabled' => true,
        'database_enabled' => false,
        'sms_enabled' => true,
    ]);

    expect($preference->channelsForEventAndUser('uptime_check_failed', notifiable()))
        ->toBe(['mail']);
});

it('keeps vonage when the notifiable has a phone number', function () {
    $preference = preference([
        'uptime_check_failed' => true,
        'email_enabled' => true,
        'database_enabled' => false,
        'sms_enabled' => true,
    ]);

    expect($preference->channelsForEventAndUser('uptime_check_failed', notifiable('+15555550100')))
        ->toBe(['mail', 'vonage']);
});
