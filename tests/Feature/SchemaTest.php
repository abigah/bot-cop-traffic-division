<?php

use Abigah\BotCopTrafficDivision\Enums\HeartbeatKind;
use Abigah\BotCopTrafficDivision\Enums\HeartbeatStatus;
use Abigah\BotCopTrafficDivision\Models\Monitor;
use Abigah\BotCopTrafficDivision\Models\MonitorCheck;
use Abigah\BotCopTrafficDivision\Models\MonitoredSite;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

it('relates a site to its monitors, heartbeats and exceptions', function () {
    $site = MonitoredSite::create(['name' => 'Marketing site', 'owner_id' => 1]);

    $monitor = $site->monitors()->create(['url' => 'https://marketing.test/']);
    $heartbeat = $site->heartbeats()->create([
        'name' => 'Nightly digest',
        'token' => Str::random(48),
        'interval_minutes' => 60,
    ]);
    $exception = $site->siteExceptions()->create([
        'fingerprint' => str_repeat('a', 64),
        'exception_class' => RuntimeException::class,
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);

    expect($site->monitors)->toHaveCount(1)
        ->and($site->heartbeats)->toHaveCount(1)
        ->and($site->siteExceptions)->toHaveCount(1)
        ->and($monitor->site->is($site))->toBeTrue()
        ->and($heartbeat->site->is($site))->toBeTrue()
        ->and($exception->site->is($site))->toBeTrue();
});

it('lets two sites monitor the same url but not one site twice', function () {
    $first = MonitoredSite::create(['name' => 'Client A', 'owner_id' => 1]);
    $second = MonitoredSite::create(['name' => 'Client B', 'owner_id' => 2]);

    $first->monitors()->create(['url' => 'https://shared.test/']);
    $second->monitors()->create(['url' => 'https://shared.test/']);

    expect(Monitor::count())->toBe(2);

    expect(fn () => $first->monitors()->create(['url' => 'https://shared.test/']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('keeps a check idempotent on its ulid', function () {
    $site = MonitoredSite::create(['name' => 'Site', 'owner_id' => 1]);
    $monitor = $site->monitors()->create(['url' => 'https://site.test/']);

    $checkId = (string) Str::ulid();

    $monitor->checks()->create([
        'check_id' => $checkId,
        'status' => 'up',
        'response_time_ms' => 120,
        'status_code' => 200,
        'checked_at' => now(),
        'prober_id' => 'cf-prober-1',
        'location' => 'cloudflare',
    ]);

    expect(fn () => $monitor->checks()->create([
        'check_id' => $checkId,
        'status' => 'up',
        'checked_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);

    expect(MonitorCheck::first()->isRemote())->toBeTrue();
});

it('mints an ingest token once per site', function () {
    $site = MonitoredSite::create(['name' => 'Site', 'owner_id' => 1]);

    $token = $site->ingestToken();

    expect($token)->toHaveLength(48)
        ->and($site->fresh()->ingestToken())->toBe($token);
});

it('reads hibernates through the settings row', function () {
    $site = MonitoredSite::create(['name' => 'Extranet', 'owner_id' => 1]);

    expect($site->hibernates)->toBeFalse();

    $site->monitoringSettings()->create([
        'hibernates' => true,
        'ingest_token' => Str::random(48),
    ]);

    expect($site->fresh()->hibernates)->toBeTrue();
});

it('stores a heartbeat kind and status as enums', function () {
    $site = MonitoredSite::create(['name' => 'Site', 'owner_id' => 1]);

    $deploy = $site->heartbeats()->create([
        'name' => 'Deployment',
        'kind' => 'event',
        'token' => Str::random(48),
        'timeout_minutes' => 20,
    ]);

    expect($deploy->kind)->toBe(HeartbeatKind::EVENT)
        ->and($deploy->status)->toBe(HeartbeatStatus::PENDING)
        ->and($deploy->isEvent())->toBeTrue();
});
