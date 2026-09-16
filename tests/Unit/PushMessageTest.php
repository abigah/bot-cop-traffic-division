<?php

use Abigah\BotCopTrafficDivision\Support\PushMessage;
use Carbon\CarbonImmutable;

function makePushMessage(array $overrides = []): PushMessage
{
    return new PushMessage(...[
        'eventType' => 'uptime_failed',
        'title' => 'Site is down',
        'body' => 'site.test failed its uptime check.',
        'ownerId' => 10,
        'destinationType' => 'monitor',
        'destinationId' => 42,
        'incidentId' => 99,
        'occurredAt' => CarbonImmutable::parse('2026-09-15 00:00:00', 'UTC'),
        'timeSensitive' => true,
        ...$overrides,
    ]);
}

it('keeps what it was built with', function () {
    $message = makePushMessage();

    expect($message->eventType)->toBe('uptime_failed')
        ->and($message->title)->toBe('Site is down')
        ->and($message->body)->toBe('site.test failed its uptime check.')
        ->and($message->ownerId)->toBe(10)
        ->and($message->destinationType)->toBe('monitor')
        ->and($message->destinationId)->toBe(42)
        ->and($message->incidentId)->toBe(99)
        ->and($message->occurredAt->equalTo(CarbonImmutable::parse('2026-09-15 00:00:00', 'UTC')))->toBeTrue()
        ->and($message->timeSensitive)->toBeTrue();
});

it('describes itself as a flat snake_case array', function () {
    expect(makePushMessage()->toArray())->toBe([
        'event_type' => 'uptime_failed',
        'title' => 'Site is down',
        'body' => 'site.test failed its uptime check.',
        'owner_id' => 10,
        'destination_type' => 'monitor',
        'destination_id' => 42,
        'incident_id' => 99,
        'occurred_at' => '2026-09-15T00:00:00Z',
        'time_sensitive' => true,
    ]);
});

/**
 * A consumer tells "no incident" apart from "forgot to say" only if the key is
 * always there.
 */
it('says so explicitly when there is no incident', function () {
    $array = makePushMessage(['incidentId' => null, 'timeSensitive' => false])->toArray();

    expect($array)->toHaveKey('incident_id')
        ->and($array['incident_id'])->toBeNull()
        ->and($array['time_sensitive'])->toBeFalse();
});

it('accepts string owner and destination keys', function () {
    $array = makePushMessage(['ownerId' => 'team-uuid', 'destinationId' => 'heartbeat-uuid'])->toArray();

    expect($array['owner_id'])->toBe('team-uuid')
        ->and($array['destination_id'])->toBe('heartbeat-uuid');
});

/**
 * The moment is the same wherever the application's clock was set; only its
 * spelling is fixed — UTC, a trailing Z, and no fraction of a second.
 */
it('spells the moment in UTC whatever timezone it was given in', function () {
    $chicago = CarbonImmutable::parse('2026-09-14 19:00:00.654321', 'America/Chicago');

    $message = makePushMessage(['occurredAt' => $chicago]);

    expect($message->toArray()['occurred_at'])->toBe('2026-09-15T00:00:00Z')
        ->and($message->occurredAt->getTimezone()->getName())->toBe('America/Chicago');
});

it('accepts each destination a consumer knows how to open', function (string $destinationType) {
    expect(makePushMessage(['destinationType' => $destinationType])->toArray()['destination_type'])
        ->toBe($destinationType);
})->with(['monitor', 'heartbeat', 'exception']);

/**
 * The longest title and body a push carries are part of the contract, counted
 * in Unicode code points.
 */
it('publishes the longest title and body a push carries', function () {
    expect(PushMessage::TITLE_MAX_LENGTH)->toBe(64)
        ->and(PushMessage::BODY_MAX_LENGTH)->toBe(178);
});

/**
 * The package's own notifications keep inside the limits; a message built any
 * other way is kept as it was given, so a delivery channel checks for itself.
 */
it('keeps text past the limits as it was given', function () {
    $title = str_repeat('t', PushMessage::TITLE_MAX_LENGTH + 1);
    $body = str_repeat('b', PushMessage::BODY_MAX_LENGTH + 1);

    $message = makePushMessage(['title' => $title, 'body' => $body]);

    expect($message->title)->toBe($title)
        ->and($message->body)->toBe($body);
});

it('refuses a destination nobody knows how to open', function (string $destinationType) {
    makePushMessage(['destinationType' => $destinationType]);
})->with(['site', 'incident', 'Monitor', 'monitors', '', ' monitor'])
    ->throws(InvalidArgumentException::class);
