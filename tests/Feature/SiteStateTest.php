<?php

use Abigah\BotCopTrafficDivision\Enums\UptimeStatus;
use Abigah\BotCopTrafficDivision\Models\MonitoredSite;

/**
 * "The site is down" is the question the heartbeat site rule asks before it
 * judges a missed job, so what counts as down has to be exactly right: a
 * critical monitor, enabled, currently failing.
 */
it('is down only when a critical monitor is down', function () {
    $site = MonitoredSite::create(['name' => 'Site', 'owner_id' => 1]);

    $site->monitors()->create([
        'url' => 'https://site.test/blog',
        'critical' => false,
        'uptime_status' => UptimeStatus::DOWN->value,
    ]);

    expect($site->isDown())->toBeFalse();

    $site->monitors()->create([
        'url' => 'https://site.test/',
        'critical' => true,
        'uptime_status' => UptimeStatus::DOWN->value,
    ]);

    expect($site->isDown())->toBeTrue();
});

it('ignores a disabled critical monitor', function () {
    $site = MonitoredSite::create(['name' => 'Site', 'owner_id' => 1]);

    $site->monitors()->create([
        'url' => 'https://site.test/',
        'critical' => true,
        'uptime_check_enabled' => false,
        'uptime_status' => UptimeStatus::DOWN->value,
    ]);

    expect($site->isDown())->toBeFalse();
});

it('finds the earliest ongoing incident among critical monitors', function () {
    $site = MonitoredSite::create(['name' => 'Site', 'owner_id' => 1]);

    $homepage = $site->monitors()->create(['url' => 'https://site.test/', 'critical' => true]);
    $health = $site->monitors()->create(['url' => 'https://site.test/up', 'critical' => true]);
    $blog = $site->monitors()->create(['url' => 'https://site.test/blog', 'critical' => false]);

    $blog->incidents()->create(['started_at' => now()->subHours(3), 'site_id' => $site->id]);
    $later = $health->incidents()->create(['started_at' => now()->subMinutes(5), 'site_id' => $site->id]);
    $earlier = $homepage->incidents()->create(['started_at' => now()->subMinutes(20), 'site_id' => $site->id]);
    $homepage->incidents()->create(['started_at' => now()->subDay(), 'resolved_at' => now()->subHours(20), 'site_id' => $site->id]);

    expect($site->openIncident()->is($earlier))->toBeTrue()
        ->and($site->openIncident()->is($later))->toBeFalse();
});

it('has no open incident when nothing is ongoing', function () {
    $site = MonitoredSite::create(['name' => 'Site', 'owner_id' => 1]);
    $monitor = $site->monitors()->create(['url' => 'https://site.test/', 'critical' => true]);

    $monitor->incidents()->create([
        'site_id' => $site->id,
        'started_at' => now()->subHour(),
        'resolved_at' => now()->subMinutes(30),
    ]);

    expect($site->openIncident())->toBeNull();
});

it('opens a deployment window over every monitor on the site', function () {
    $site = MonitoredSite::create(['name' => 'Site', 'owner_id' => 1]);
    $homepage = $site->monitors()->create(['url' => 'https://site.test/']);
    $health = $site->monitors()->create(['url' => 'https://site.test/up']);

    expect($site->isDeploying())->toBeFalse()
        ->and($homepage->isDeploying())->toBeFalse();

    $site->startDeployment();

    expect($site->isDeploying())->toBeTrue()
        ->and($homepage->fresh()->isDeploying())->toBeTrue()
        ->and($health->fresh()->isDeploying())->toBeTrue();

    $site->finishDeployment();

    expect($site->fresh()->isDeploying())->toBeFalse()
        ->and($homepage->fresh()->isDeploying())->toBeFalse();
});

it('stops suppressing when a deployment never reports finish', function () {
    config()->set('monitoring.deployment.max_window_minutes', 30);

    $site = MonitoredSite::create(['name' => 'Site', 'owner_id' => 1]);
    $monitor = $site->monitors()->create(['url' => 'https://site.test/']);

    $site->deployments()->create(['started_at' => now()->subMinutes(31)]);

    expect($site->isDeploying())->toBeFalse()
        ->and($monitor->isDeploying())->toBeFalse();
});

it('honours a per-monitor deployment window during the transition', function () {
    $site = MonitoredSite::create(['name' => 'Site', 'owner_id' => 1]);
    $monitor = $site->monitors()->create(['url' => 'https://site.test/']);
    $other = $site->monitors()->create(['url' => 'https://site.test/up']);

    $monitor->deployments()->create(['started_at' => now()]);

    expect($monitor->isDeploying())->toBeTrue()
        ->and($other->isDeploying())->toBeFalse();
});
