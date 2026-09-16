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

/**
 * Push has no provider in this package. The host names the channel class that
 * delivers it, and a preference turned on with nothing named adds nothing.
 */
it('adds the host\'s push channel after the others when push is on', function () {
    config()->set('monitoring.notification_channels.push', 'Host\\Notifications\\PushChannel');

    $preference = preference([
        'uptime_check_failed' => true,
        'email_enabled' => true,
        'database_enabled' => true,
        'sms_enabled' => true,
        'push_enabled' => true,
    ]);

    expect($preference->channelsForEvent('uptime_check_failed'))
        ->toBe(['mail', 'database', 'vonage', 'Host\\Notifications\\PushChannel']);
});

it('adds nothing for push when the host has not named a channel', function (?string $configured) {
    config()->set('monitoring.notification_channels.push', $configured);

    $preference = preference([
        'uptime_check_failed' => true,
        'email_enabled' => true,
        'database_enabled' => false,
        'sms_enabled' => false,
        'push_enabled' => true,
    ]);

    expect($preference->channelsForEvent('uptime_check_failed'))->toBe(['mail']);
})->with([
    'null' => [null],
    'empty string' => [''],
]);

it('returns no channels for a disabled event even with push on', function () {
    config()->set('monitoring.notification_channels.push', 'Host\\Notifications\\PushChannel');

    $preference = preference([
        'uptime_check_failed' => false,
        'email_enabled' => true,
        'database_enabled' => true,
        'sms_enabled' => true,
        'push_enabled' => true,
    ]);

    expect($preference->channelsForEvent('uptime_check_failed'))->toBe([]);
});

it('leaves mail, database and vonage exactly as they were when push is off', function (array $switches, array $expected) {
    config()->set('monitoring.notification_channels.push', 'Host\\Notifications\\PushChannel');

    $preference = preference(['uptime_check_failed' => true, 'push_enabled' => false, ...$switches]);

    expect($preference->channelsForEvent('uptime_check_failed'))->toBe($expected);
})->with([
    'all three' => [['email_enabled' => true, 'database_enabled' => true, 'sms_enabled' => true], ['mail', 'database', 'vonage']],
    'mail and database' => [['email_enabled' => true, 'database_enabled' => true, 'sms_enabled' => false], ['mail', 'database']],
    'database and vonage' => [['email_enabled' => false, 'database_enabled' => true, 'sms_enabled' => true], ['database', 'vonage']],
    'vonage alone' => [['email_enabled' => false, 'database_enabled' => false, 'sms_enabled' => true], ['vonage']],
    'none' => [['email_enabled' => false, 'database_enabled' => false, 'sms_enabled' => false], []],
]);

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
