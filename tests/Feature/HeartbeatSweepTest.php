<?php

use Abigah\BotCopTrafficDivision\Enums\HeartbeatStatus;
use Abigah\BotCopTrafficDivision\Enums\UptimeStatus;
use Abigah\BotCopTrafficDivision\Events\HeartbeatMissed;
use Abigah\BotCopTrafficDivision\Facades\Monitoring;
use Abigah\BotCopTrafficDivision\Models\Monitor;
use Abigah\BotCopTrafficDivision\Models\MonitoredSite;
use GrahamCampbell\GuzzleFactory\GuzzleFactory;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/**
 * The same rule the kit pins, driven through real models this time: the
 * fixture proves the decision, this proves the sweep asks the right question
 * and acts on the answer.
 */
beforeEach(function () {
    $this->site = MonitoredSite::create(['name' => 'Site', 'owner_id' => 1]);

    $this->criticalMonitor = fn (string $status, $checkedAt) => $this->site->monitors()->create([
        'url' => 'https://site.test/'.Str::random(6),
        'critical' => true,
        'uptime_status' => $status,
        'uptime_last_check_date' => $checkedAt,
        'uptime_status_last_change_date' => $checkedAt,
    ]);

    $this->overdue = fn () => $this->site->heartbeats()->create([
        'name' => 'Nightly digest',
        'token' => Str::random(48),
        'interval_minutes' => 60,
        'grace_minutes' => 10,
        'last_ping_at' => now()->subMinutes(90),
    ]);
});

function respondWith(int $status): void
{
    config()->set('monitoring.uptime.guzzle_options', [
        'handler' => GuzzleFactory::innerHandler(new MockHandler(array_fill(0, 5, new Response($status, [], 'OK')))),
    ]);
}

it('judges an overdue heartbeat when the site is freshly known up', function () {
    Event::fake([HeartbeatMissed::class]);

    ($this->criticalMonitor)(UptimeStatus::UP->value, now()->subSeconds(30));
    $heartbeat = ($this->overdue)();

    $this->artisan('monitoring:heartbeats:sweep')->assertSuccessful();

    Event::assertDispatched(HeartbeatMissed::class, 1);
    expect($heartbeat->fresh()->status)->toBe(HeartbeatStatus::MISSING);
});

/**
 * A missed job during an outage is the outage. Paging separately turns one
 * incident into a dozen alarms.
 */
it('suppresses an overdue heartbeat while the site is down', function () {
    Event::fake([HeartbeatMissed::class]);

    ($this->criticalMonitor)(UptimeStatus::DOWN->value, now()->subSeconds(30));
    $heartbeat = ($this->overdue)();

    $this->artisan('monitoring:heartbeats:sweep')->assertSuccessful();

    Event::assertNotDispatched(HeartbeatMissed::class);

    expect($heartbeat->fresh()->suppressed_since)->not->toBeNull()
        ->and($heartbeat->fresh()->status)->toBe(HeartbeatStatus::PENDING);
});

/**
 * The likeliest reason a job stopped pinging is that the whole site went down a
 * moment ago, so a stale "up" is confirmed with one live request before anyone
 * is woken — and only here, on a heartbeat that is already overdue.
 */
it('probes a stale site before judging, and believes what it finds', function () {
    Event::fake([HeartbeatMissed::class]);

    ($this->criticalMonitor)(UptimeStatus::UP->value, now()->subMinutes(30));
    $heartbeat = ($this->overdue)();

    respondWith(200);

    $this->artisan('monitoring:heartbeats:sweep')->assertSuccessful();

    // The probe answered, so the web tier is up and the scheduler is not.
    Event::assertDispatched(HeartbeatMissed::class, 1);
    expect($heartbeat->fresh()->status)->toBe(HeartbeatStatus::MISSING)
        ->and(Monitor::first()->checks()->count())->toBe(1);
});

it('suppresses instead when the probe finds the site down', function () {
    Event::fake([HeartbeatMissed::class]);

    ($this->criticalMonitor)(UptimeStatus::UP->value, now()->subMinutes(30));
    $heartbeat = ($this->overdue)();

    respondWith(503);

    $this->artisan('monitoring:heartbeats:sweep')->assertSuccessful();

    Event::assertNotDispatched(HeartbeatMissed::class);
    expect($heartbeat->fresh()->suppressed_since)->not->toBeNull();
});

/**
 * The queue has not had time to catch up. Judging here pages for an outage that
 * is already over.
 */
it('holds off for one cycle after the site recovers', function () {
    Event::fake([HeartbeatMissed::class]);

    $monitor = ($this->criticalMonitor)(UptimeStatus::UP->value, now()->subSeconds(30));
    $monitor->incidents()->create([
        'site_id' => $this->site->id,
        'started_at' => now()->subHours(2),
        'resolved_at' => now()->subMinutes(5),
    ]);

    $heartbeat = ($this->overdue)();

    $this->artisan('monitoring:heartbeats:sweep')->assertSuccessful();

    Event::assertNotDispatched(HeartbeatMissed::class);
    expect($heartbeat->fresh()->status)->toBe(HeartbeatStatus::PENDING)
        ->and($heartbeat->fresh()->suppressed_since)->toBeNull();
});

it('judges once the recovery grace has passed', function () {
    Event::fake([HeartbeatMissed::class]);

    $monitor = ($this->criticalMonitor)(UptimeStatus::UP->value, now()->subSeconds(30));
    $monitor->incidents()->create([
        'site_id' => $this->site->id,
        'started_at' => now()->subHours(4),
        'resolved_at' => now()->subMinutes(80),
    ]);

    ($this->overdue)();

    $this->artisan('monitoring:heartbeats:sweep')->assertSuccessful();

    Event::assertDispatched(HeartbeatMissed::class, 1);
});

it('leaves a heartbeat that is not late alone', function () {
    Event::fake([HeartbeatMissed::class]);

    ($this->criticalMonitor)(UptimeStatus::UP->value, now()->subSeconds(30));

    $this->site->heartbeats()->create([
        'name' => 'Nightly digest',
        'token' => Str::random(48),
        'interval_minutes' => 60,
        'grace_minutes' => 10,
        'last_ping_at' => now()->subMinutes(20),
    ]);

    $this->artisan('monitoring:heartbeats:sweep')->assertSuccessful();

    Event::assertNotDispatched(HeartbeatMissed::class);
});

it('times out an event whose start never reported a finish', function () {
    Event::fake([HeartbeatMissed::class]);

    ($this->criticalMonitor)(UptimeStatus::UP->value, now()->subSeconds(30));

    $deploy = $this->site->heartbeats()->create([
        'name' => 'Deployment',
        'kind' => 'event',
        'token' => Str::random(48),
        'timeout_minutes' => 20,
        'status' => HeartbeatStatus::RUNNING->value,
        'last_start_at' => now()->subMinutes(25),
    ]);

    $this->artisan('monitoring:heartbeats:sweep')->assertSuccessful();

    Event::assertDispatched(HeartbeatMissed::class, 1);
    expect($deploy->fresh()->status)->toBe(HeartbeatStatus::TIMED_OUT);
});

it('says a heartbeat is missing once, not on every sweep', function () {
    Event::fake([HeartbeatMissed::class]);

    ($this->criticalMonitor)(UptimeStatus::UP->value, now()->subSeconds(30));
    ($this->overdue)();

    $this->artisan('monitoring:heartbeats:sweep')->assertSuccessful();
    $this->artisan('monitoring:heartbeats:sweep')->assertSuccessful();
    $this->artisan('monitoring:heartbeats:sweep')->assertSuccessful();

    Event::assertDispatched(HeartbeatMissed::class, 1);
});

it('is scheduled in local mode and left to the prober in remote mode', function () {
    // A fresh Schedule each time: the container's is a singleton, and a reused
    // one would still be holding the previous mode's events.
    $scheduled = function (): array {
        Monitoring::schedule($schedule = new Schedule);

        return collect($schedule->events())->map->getSummaryForDisplay()->all();
    };

    config()->set('monitoring.checker', 'local');
    expect(collect($scheduled())->contains(fn ($s) => str_contains($s, 'heartbeats:sweep')))->toBeTrue();

    config()->set('monitoring.checker', 'remote');
    expect(collect($scheduled())->contains(fn ($s) => str_contains($s, 'heartbeats:sweep')))->toBeFalse();
});
