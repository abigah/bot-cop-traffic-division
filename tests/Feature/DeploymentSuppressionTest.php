<?php

use Abigah\BotCopTrafficDivision\Enums\UptimeStatus;
use Abigah\BotCopTrafficDivision\Events\UptimeCheckFailed;
use Abigah\BotCopTrafficDivision\Models\MonitoredSite;
use Abigah\BotCopTrafficDivision\Models\MonitorIncident;
use Abigah\BotCopTrafficDivision\Support\CheckResult;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    config()->set('monitoring.uptime.fire_failed_event_after_consecutive_failures', 2);

    $this->site = MonitoredSite::create(['name' => 'Site', 'owner_id' => 1]);
    $this->monitor = $this->site->monitors()->create(['url' => 'https://site.test/']);
});

it('records failures during a deployment without alerting or opening an incident', function () {
    Event::fake([UptimeCheckFailed::class]);

    $this->site->startDeployment();

    $this->monitor->recordUptimeResult(CheckResult::down('Connection refused'));
    $this->monitor->recordUptimeResult(CheckResult::down('Connection refused'));
    $this->monitor->recordUptimeResult(CheckResult::down('Connection refused'));

    Event::assertNotDispatched(UptimeCheckFailed::class);

    expect(MonitorIncident::count())->toBe(0)
        ->and($this->monitor->checks()->count())->toBe(3)
        ->and($this->monitor->uptime_status)->toBe(UptimeStatus::DOWN->value)
        ->and($this->monitor->uptime_check_times_failed_in_a_row)->toBe(0);
});

/**
 * The counter is held at zero during the window rather than merely gated, so a
 * site that is genuinely broken after a deploy needs the full threshold again.
 * A deploy is not evidence about the next check.
 */
it('starts alerting fresh once the window closes', function () {
    Event::fake([UptimeCheckFailed::class]);

    $this->site->startDeployment();
    $this->monitor->recordUptimeResult(CheckResult::down('Down'));
    $this->site->finishDeployment();

    $this->monitor->recordUptimeResult(CheckResult::down('Down'));

    Event::assertNotDispatched(UptimeCheckFailed::class);

    $this->monitor->recordUptimeResult(CheckResult::down('Down'));

    Event::assertDispatched(UptimeCheckFailed::class, 1);
    expect(MonitorIncident::count())->toBe(1);
});

it('suppresses every monitor on the site, not just the one being deployed', function () {
    Event::fake([UptimeCheckFailed::class]);

    $health = $this->site->monitors()->create(['url' => 'https://site.test/up']);

    $this->site->startDeployment();

    foreach ([$this->monitor, $health] as $monitor) {
        $monitor->recordUptimeResult(CheckResult::down('Down'));
        $monitor->recordUptimeResult(CheckResult::down('Down'));
    }

    Event::assertNotDispatched(UptimeCheckFailed::class);
    expect(MonitorIncident::count())->toBe(0);
});
