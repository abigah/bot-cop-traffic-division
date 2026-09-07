<?php

use Abigah\BotCopTrafficDivision\Enums\UptimeStatus;
use Abigah\BotCopTrafficDivision\Models\MonitoredSite;
use Illuminate\Support\Str;

beforeEach(function () {
    config()->set('monitoring.uptime.minimum_interval_minutes', 5);
    config()->set('monitoring.uptime.hibernating_interval_minutes', 60);
});

it('holds every monitor at the tenant minimum', function () {
    $site = MonitoredSite::create(['name' => 'Site', 'owner_id' => 1]);
    $monitor = $site->monitors()->create([
        'url' => 'https://site.test/',
        'uptime_check_interval_in_minutes' => 1,
    ]);

    expect($monitor->effectiveIntervalMinutes())->toBe(5);
});

it('leaves a slower interval alone', function () {
    $site = MonitoredSite::create(['name' => 'Site', 'owner_id' => 1]);
    $monitor = $site->monitors()->create([
        'url' => 'https://site.test/',
        'uptime_check_interval_in_minutes' => 15,
    ]);

    expect($monitor->effectiveIntervalMinutes())->toBe(15);
});

/**
 * Every check on a hibernating site is a cold start, so the floor is much
 * further back and heartbeats are what cover the gap between checks.
 */
it('holds a hibernating site much further back', function () {
    $site = MonitoredSite::create(['name' => 'Extranet', 'owner_id' => 1]);
    $site->monitoringSettings()->create(['hibernates' => true, 'ingest_token' => Str::random(48)]);

    $monitor = $site->monitors()->create([
        'url' => 'https://extranet.test/',
        'uptime_check_interval_in_minutes' => 5,
    ]);

    expect($monitor->effectiveIntervalMinutes())->toBe(60);
});

it('is due when it has never been checked', function () {
    $site = MonitoredSite::create(['name' => 'Site', 'owner_id' => 1]);
    $monitor = $site->monitors()->create(['url' => 'https://site.test/']);

    expect($monitor->shouldCheckUptime())->toBeTrue();
});

it('is always due while down, whatever its interval', function () {
    $site = MonitoredSite::create(['name' => 'Site', 'owner_id' => 1]);
    $monitor = $site->monitors()->create([
        'url' => 'https://site.test/',
        'uptime_check_interval_in_minutes' => 60,
        'uptime_status' => UptimeStatus::DOWN->value,
        'uptime_last_check_date' => now(),
    ]);

    expect($monitor->shouldCheckUptime())->toBeTrue();
});

it('is not due again until its effective interval has passed', function () {
    $site = MonitoredSite::create(['name' => 'Site', 'owner_id' => 1]);
    $monitor = $site->monitors()->create([
        'url' => 'https://site.test/',
        'uptime_check_interval_in_minutes' => 1,
        'uptime_status' => UptimeStatus::UP->value,
        'uptime_last_check_date' => now()->subMinutes(2),
    ]);

    // Asked for one minute, clamped to five: two minutes is not yet due.
    expect($monitor->shouldCheckUptime())->toBeFalse();

    $monitor->uptime_last_check_date = now()->subMinutes(6);

    expect($monitor->shouldCheckUptime())->toBeTrue();
});

it('is never due when checking is disabled', function () {
    $site = MonitoredSite::create(['name' => 'Site', 'owner_id' => 1]);
    $monitor = $site->monitors()->create([
        'url' => 'https://site.test/',
        'uptime_check_enabled' => false,
        'uptime_status' => UptimeStatus::DOWN->value,
    ]);

    expect($monitor->shouldCheckUptime())->toBeFalse();
});
