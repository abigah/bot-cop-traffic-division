<?php

use Abigah\BotCopTrafficDivision\Enums\UptimeStatus;
use Abigah\BotCopTrafficDivision\Models\Monitor;
use Abigah\BotCopTrafficDivision\Models\MonitoredSite;
use Abigah\BotCopTrafficDivision\Services\MonitorChecker;
use GrahamCampbell\GuzzleFactory\GuzzleFactory;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;

function monitorReturning(int $status, string $body = '', array $headers = [], array $attributes = []): Monitor
{
    $site = MonitoredSite::create(['name' => 'Site', 'owner_id' => 1]);

    $monitor = $site->monitors()->create([
        'url' => 'https://site.test/',
        'uptime_status' => UptimeStatus::UP->value,
        'uptime_status_last_change_date' => now(),
        ...$attributes,
    ]);

    config()->set('monitoring.uptime.guzzle_options', [
        'handler' => GuzzleFactory::innerHandler(new MockHandler([new Response($status, $headers, $body)])),
    ]);

    return $monitor;
}

it('records a 200 as up, with its status code', function () {
    $monitor = monitorReturning(200, 'OK');

    app(MonitorChecker::class)->check([$monitor]);

    expect($monitor->fresh()->uptime_status)->toBe(UptimeStatus::UP->value)
        ->and($monitor->checks()->first()->status_code)->toBe(200);
});

it('records a 404 as down', function () {
    $monitor = monitorReturning(404, 'Not Found');

    app(MonitorChecker::class)->check([$monitor]);

    expect($monitor->fresh()->uptime_status)->toBe(UptimeStatus::DOWN->value);
});

it('is down when the string it looks for is missing', function () {
    $monitor = monitorReturning(200, 'Something else', attributes: ['look_for_string' => 'Welcome']);

    app(MonitorChecker::class)->check([$monitor]);

    expect($monitor->fresh()->uptime_status)->toBe(UptimeStatus::DOWN->value)
        ->and($monitor->fresh()->uptime_check_failure_reason)->toContain('Welcome');
});

it('is down when the string it fails for is present', function () {
    $monitor = monitorReturning(200, 'Service Unavailable', attributes: ['fail_for_string' => 'Service Unavailable']);

    app(MonitorChecker::class)->check([$monitor]);

    expect($monitor->fresh()->uptime_status)->toBe(UptimeStatus::DOWN->value);
});

/**
 * A cached 200 never reached the origin, so it proves nothing about whether the
 * app is running. It is still a response — the check is up — but the
 * misconfiguration is recorded rather than believed.
 */
it('flags a Cloudflare cache hit', function () {
    $monitor = monitorReturning(200, 'OK', ['cf-cache-status' => 'HIT']);

    app(MonitorChecker::class)->check([$monitor]);

    expect($monitor->fresh()->uptime_status)->toBe(UptimeStatus::UP->value)
        ->and($monitor->checks()->first()->served_from_cache)->toBeTrue()
        ->and($monitor->fresh()->served_from_cache_at)->not->toBeNull();
});

it('flags any response that has been sitting in a cache', function () {
    $monitor = monitorReturning(200, 'OK', ['Age' => '312']);

    app(MonitorChecker::class)->check([$monitor]);

    expect($monitor->checks()->first()->served_from_cache)->toBeTrue();
});

it('does not flag a fresh response', function () {
    $monitor = monitorReturning(200, 'OK', ['Age' => '0', 'cf-cache-status' => 'MISS']);

    app(MonitorChecker::class)->check([$monitor]);

    expect($monitor->checks()->first()->served_from_cache)->toBeFalse();
});

it('gives every local check a ulid of its own', function () {
    $monitor = monitorReturning(200, 'OK');

    app(MonitorChecker::class)->check([$monitor]);

    expect($monitor->checks()->first()->check_id)->toHaveLength(26);
});
