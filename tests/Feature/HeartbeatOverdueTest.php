<?php

use Abigah\BotCopTrafficDivision\Enums\HeartbeatStatus;
use Abigah\BotCopTrafficDivision\Models\MonitoredSite;
use Abigah\BotCopTrafficDivision\Models\MonitorHeartbeat;
use Illuminate\Support\Str;

function heartbeat(array $attributes = []): MonitorHeartbeat
{
    $site = MonitoredSite::create(['name' => 'Site', 'owner_id' => 1]);

    return $site->heartbeats()->create(array_merge([
        'name' => 'Nightly digest',
        'token' => Str::random(48),
        'interval_minutes' => 60,
        'grace_minutes' => 10,
    ], $attributes));
}

it('is overdue once interval plus grace has passed', function () {
    $beat = heartbeat(['last_ping_at' => now()->subMinutes(69)]);

    expect($beat->isOverdue())->toBeFalse();

    $beat->last_ping_at = now()->subMinutes(71);

    expect($beat->isOverdue())->toBeTrue();
});

it('falls back to the configured grace', function () {
    config()->set('monitoring.heartbeats.default_grace_minutes', 30);

    $beat = heartbeat(['grace_minutes' => null, 'last_ping_at' => now()->subMinutes(85)]);

    expect($beat->graceMinutes())->toBe(30)
        ->and($beat->isOverdue())->toBeFalse();
});

/**
 * A heartbeat that has never pinged still has to become overdue eventually,
 * or a job that was declared and never ran would sit unnoticed forever.
 */
it('runs the clock from declaration when nothing has ever pinged', function () {
    $beat = heartbeat();
    $beat->forceFill(['created_at' => now()->subMinutes(90)]);

    expect($beat->isOverdue())->toBeTrue();
});

it('judges an event by its timeout, and only while it is running', function () {
    $deploy = heartbeat([
        'kind' => 'event',
        'interval_minutes' => null,
        'grace_minutes' => null,
        'timeout_minutes' => 20,
    ]);

    // Nothing was expected of it: no start, no verdict.
    expect($deploy->overdueAt())->toBeNull()
        ->and($deploy->isOverdue())->toBeFalse();

    $deploy->status = HeartbeatStatus::RUNNING;
    $deploy->last_start_at = now()->subMinutes(21);

    expect($deploy->isOverdue())->toBeTrue();

    $deploy->status = HeartbeatStatus::OK;

    expect($deploy->isOverdue())->toBeFalse();
});

it('knows which statuses are worth telling someone about', function () {
    expect(HeartbeatStatus::MISSING->isAlerting())->toBeTrue()
        ->and(HeartbeatStatus::FAILED->isAlerting())->toBeTrue()
        ->and(HeartbeatStatus::TIMED_OUT->isAlerting())->toBeTrue()
        ->and(HeartbeatStatus::OK->isAlerting())->toBeFalse()
        ->and(HeartbeatStatus::RUNNING->isAlerting())->toBeFalse()
        ->and(HeartbeatStatus::PENDING->isAlerting())->toBeFalse();
});
