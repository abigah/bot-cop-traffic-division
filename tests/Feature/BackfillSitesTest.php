<?php

use Abigah\BotCopTrafficDivision\Facades\Monitoring;
use Abigah\BotCopTrafficDivision\Models\Monitor;
use Abigah\BotCopTrafficDivision\Models\MonitoredSite;
use Abigah\BotCopTrafficDivision\Tests\Fixtures\HostSite;

it('groups monitors with no site by host', function () {
    Monitor::create(['url' => 'https://acme.test/', 'owner_id' => 3]);
    Monitor::create(['url' => 'https://acme.test/up', 'owner_id' => 3]);
    Monitor::create(['url' => 'https://issuesiface.com/', 'owner_id' => 3]);

    $this->artisan('monitoring:sites:backfill')->assertSuccessful();

    expect(MonitoredSite::count())->toBe(2)
        ->and(MonitoredSite::where('name', 'acme.test')->first()->monitors)->toHaveCount(2)
        ->and(Monitor::whereNull('site_id')->count())->toBe(0);
});

it('leaves monitors that already have a site alone', function () {
    $site = MonitoredSite::create(['name' => 'Existing', 'owner_id' => 1]);
    $monitor = $site->monitors()->create(['url' => 'https://acme.test/']);

    $this->artisan('monitoring:sites:backfill')->assertSuccessful();

    expect(MonitoredSite::count())->toBe(1)
        ->and($monitor->fresh()->site_id)->toBe($site->id);
});

it('writes nothing on a dry run', function () {
    Monitor::create(['url' => 'https://acme.test/', 'owner_id' => 3]);

    $this->artisan('monitoring:sites:backfill', ['--dry-run' => true])->assertSuccessful();

    expect(MonitoredSite::count())->toBe(0)
        ->and(Monitor::whereNull('site_id')->count())->toBe(1);
});

it('carries the owner across to the site it creates', function () {
    Monitor::create(['url' => 'https://acme.test/', 'owner_id' => 42]);

    $this->artisan('monitoring:sites:backfill')->assertSuccessful();

    expect(MonitoredSite::first()->owner_id)->toBe(42);
});

it('lets a host place its own monitors through the resolver', function () {
    $clientSite = MonitoredSite::create(['name' => 'Everything', 'owner_id' => 1]);

    Monitoring::resolveSiteForMonitorUsing(fn () => $clientSite);

    Monitor::create(['url' => 'https://acme.test/', 'owner_id' => 3]);
    Monitor::create(['url' => 'https://issuesiface.com/', 'owner_id' => 3]);

    $this->artisan('monitoring:sites:backfill')->assertSuccessful();

    expect(MonitoredSite::count())->toBe(1)
        ->and($clientSite->monitors()->count())->toBe(2);
});

/**
 * A monitor the resolver declined is reported rather than guessed about. The
 * alternative — inventing a site on a table the package does not own — is how
 * an import quietly creates rows nobody asked for.
 */
it('reports the monitors a resolver would not place', function () {
    Monitoring::resolveSiteForMonitorUsing(fn (Monitor $monitor) => str_contains($monitor->url, 'acme.test')
        ? MonitoredSite::firstOrCreate(['name' => 'P2C'], ['owner_id' => 3])
        : null);

    Monitor::create(['url' => 'https://acme.test/', 'owner_id' => 3]);
    Monitor::create(['url' => 'https://elsewhere.test/', 'owner_id' => 3]);

    $this->artisan('monitoring:sites:backfill')
        ->expectsOutputToContain('https://elsewhere.test/')
        ->assertSuccessful();

    expect(Monitor::whereNull('site_id')->count())->toBe(1);
});

/**
 * An install that names its own site model and wires no resolver is half
 * wired. Finding that out from an empty manifest weeks later is worse than
 * finding out here.
 */
it('refuses to run when a host supplies a site model and no resolver', function () {
    config()->set('monitoring.site_model', HostSite::class);

    expect(fn () => $this->artisan('monitoring:sites:backfill')->run())
        ->toThrow(RuntimeException::class, 'resolveSiteForMonitorUsing');
});
