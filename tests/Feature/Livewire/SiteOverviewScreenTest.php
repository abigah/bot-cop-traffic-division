<?php

use Abigah\BotCopTrafficDivision\Enums\HeartbeatStatus;
use Abigah\BotCopTrafficDivision\Enums\UptimeStatus;
use Abigah\BotCopTrafficDivision\Facades\Monitoring;
use Abigah\BotCopTrafficDivision\Livewire\SiteOverview;
use Abigah\BotCopTrafficDivision\Models\MonitoredSite;
use Abigah\BotCopTrafficDivision\Models\MonitorSiteException;
use Abigah\BotCopTrafficDivision\Tests\Fixtures\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = User::create(['name' => 'Owner', 'email' => 'owner@test.dev']);

    Monitoring::resolveCurrentOwnerUsing(fn () => $this->owner);
    Monitoring::resolveSitesUsing(fn () => MonitoredSite::where('owner_id', $this->owner->id)->get());

    $this->site = MonitoredSite::create(['name' => 'acme.test', 'owner_id' => $this->owner->id]);
    $this->monitor = $this->site->monitors()->create([
        'url' => 'https://acme.test/',
        'owner_id' => $this->owner->id,
        'critical' => true,
        'uptime_status' => UptimeStatus::UP->value,
    ]);
});

/**
 * The three signals on one page is the point of this screen. Read apart, the
 * middle one goes unnoticed for a week.
 */
it('shows monitors, jobs and errors together', function () {
    $this->site->heartbeats()->create([
        'name' => 'Nightly digest',
        'token' => Str::random(48),
        'interval_minutes' => 60,
        'status' => HeartbeatStatus::MISSING->value,
    ]);

    $this->site->siteExceptions()->create([
        'fingerprint' => str_repeat('a', 64),
        'exception_class' => 'Illuminate\\Database\\QueryException',
        'message' => 'SQLSTATE[HY000] Connection refused',
        'file' => 'app/Foo.php',
        'line' => 12,
        'first_seen_at' => now()->subHour(),
        'last_seen_at' => now(),
        'occurrences' => 37,
    ]);

    Livewire::test(SiteOverview::class, ['site' => $this->site->id])
        ->assertOk()
        ->assertSee('https://acme.test/')
        ->assertSee('Nightly digest')
        ->assertSee('Missing')
        ->assertSee('QueryException')
        ->assertSee('37 times');
});

it('warns while a deployment is suppressing alerting', function () {
    $this->site->startDeployment();

    Livewire::test(SiteOverview::class, ['site' => $this->site->id])
        ->assertSee('Deployment underway')
        ->assertSee('Alerting is suppressed');
});

/**
 * The prober is authoritative about its own tiers, so a clamp is shown rather
 * than silently applied — otherwise a request for one-minute checking looks
 * granted when it was not.
 */
it('shows when a monitor is checked less often than it asked for', function () {
    $this->monitor->forceFill([
        'uptime_check_interval_in_minutes' => 1,
        'clamped_interval_minutes' => 5,
        'clamped_reason' => 'tenant-minimum',
    ])->save();

    Livewire::test(SiteOverview::class, ['site' => $this->site->id])
        ->assertSee('checked every 5m');
});

it('flags a monitor whose response came from a cache', function () {
    $this->monitor->forceFill(['served_from_cache_at' => now()])->save();

    Livewire::test(SiteOverview::class, ['site' => $this->site->id])
        ->assertSee('Served from cache');
});

it('marks heartbeats that an outage swallowed', function () {
    $this->site->heartbeats()->create([
        'name' => 'Nightly digest',
        'token' => Str::random(48),
        'interval_minutes' => 60,
        'suppressed_since' => now()->subHour(),
    ]);

    Livewire::test(SiteOverview::class, ['site' => $this->site->id])
        ->assertSee('During an outage')
        ->assertSee('1 swallowed by an outage');
});

it('resolves an error so a recurrence counts as new again', function () {
    $error = $this->site->siteExceptions()->create([
        'fingerprint' => str_repeat('b', 64),
        'exception_class' => 'RuntimeException',
        'message' => 'Boom',
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);

    Livewire::test(SiteOverview::class, ['site' => $this->site->id])
        ->call('resolveException', $error->id)
        ->assertOk();

    expect($error->fresh()->isResolved())->toBeTrue();
});

/**
 * An id in a URL is untrusted input. Another owner's site is not merely hidden;
 * it cannot be addressed at all.
 */
it('cannot be opened for another owner\'s site', function () {
    $stranger = User::create(['name' => 'Stranger', 'email' => 'stranger@test.dev']);
    $theirs = MonitoredSite::create(['name' => 'not-yours.test', 'owner_id' => $stranger->id]);

    Livewire::test(SiteOverview::class, ['site' => $theirs->id]);
})->throws(ModelNotFoundException::class);

it('cannot resolve an error on another owner\'s site', function () {
    $stranger = User::create(['name' => 'Stranger', 'email' => 'stranger@test.dev']);
    $theirs = MonitoredSite::create(['name' => 'not-yours.test', 'owner_id' => $stranger->id]);

    $error = $theirs->siteExceptions()->create([
        'fingerprint' => str_repeat('c', 64),
        'exception_class' => 'RuntimeException',
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);

    Livewire::test(SiteOverview::class, ['site' => $this->site->id])
        ->call('resolveException', $error->id)
        ->assertOk();

    expect(MonitorSiteException::find($error->id)->isResolved())->toBeFalse();
});
