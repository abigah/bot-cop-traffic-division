<?php

use Abigah\BotCopTrafficDivision\Enums\UptimeStatus;
use Abigah\BotCopTrafficDivision\Events\UptimeCheckFailed;
use Abigah\BotCopTrafficDivision\Events\UptimeCheckRecovered;
use Abigah\BotCopTrafficDivision\Models\MonitoredSite;
use Abigah\BotCopTrafficDivision\Models\MonitorIncident;
use Abigah\BotCopTrafficDivision\Support\CheckResult;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    config()->set('monitoring.uptime.fire_failed_event_after_consecutive_failures', 2);
    config()->set('monitoring.uptime.resend_failed_notification_every_minutes', 60);

    $this->site = MonitoredSite::create(['name' => 'Site', 'owner_id' => 1]);
    $this->monitor = $this->site->monitors()->create(['url' => 'https://site.test/']);
});

it('does not fire until the consecutive failure threshold is met', function () {
    Event::fake([UptimeCheckFailed::class]);

    $this->monitor->recordUptimeResult(CheckResult::down('Connection refused'));

    Event::assertNotDispatched(UptimeCheckFailed::class);
    expect($this->monitor->uptime_check_times_failed_in_a_row)->toBe(1);

    $this->monitor->recordUptimeResult(CheckResult::down('Connection refused'));

    Event::assertDispatched(UptimeCheckFailed::class, 1);
    expect($this->monitor->uptime_status)->toBe(UptimeStatus::DOWN->value);
});

it('does not fire again on every subsequent failure', function () {
    Event::fake([UptimeCheckFailed::class]);

    foreach (range(1, 5) as $ignored) {
        $this->monitor->recordUptimeResult(CheckResult::down('Connection refused'));
    }

    Event::assertDispatched(UptimeCheckFailed::class, 1);
});

it('fires again once the resend interval has passed', function () {
    Event::fake([UptimeCheckFailed::class]);

    $start = now();

    $this->monitor->recordUptimeResult(CheckResult::down('Down', checkedAt: $start));
    $this->monitor->recordUptimeResult(CheckResult::down('Down', checkedAt: $start->copy()->addMinutes(5)));

    Event::assertDispatched(UptimeCheckFailed::class, 1);

    $this->monitor->recordUptimeResult(CheckResult::down('Down', checkedAt: $start->copy()->addMinutes(30)));

    Event::assertDispatched(UptimeCheckFailed::class, 1);

    $this->monitor->recordUptimeResult(CheckResult::down('Down', checkedAt: $start->copy()->addMinutes(70)));

    Event::assertDispatched(UptimeCheckFailed::class, 2);
});

it('recovers only when it had been alerting', function () {
    Event::fake([UptimeCheckRecovered::class]);

    $this->monitor->recordUptimeResult(CheckResult::down('Down'));
    $this->monitor->recordUptimeResult(CheckResult::up(120));

    // One failure never reached the threshold, so nobody was ever told.
    Event::assertNotDispatched(UptimeCheckRecovered::class);

    $this->monitor->recordUptimeResult(CheckResult::down('Down'));
    $this->monitor->recordUptimeResult(CheckResult::down('Down'));
    $this->monitor->recordUptimeResult(CheckResult::up(120));

    Event::assertDispatched(UptimeCheckRecovered::class, 1);
    expect($this->monitor->uptime_check_times_failed_in_a_row)->toBe(0)
        ->and($this->monitor->uptime_status)->toBe(UptimeStatus::UP->value);
});

it('opens one incident for an outage and resolves it on recovery', function () {
    $start = now();

    $this->monitor->recordUptimeResult(CheckResult::down('Down', checkedAt: $start));
    $this->monitor->recordUptimeResult(CheckResult::down('Down', checkedAt: $start->copy()->addMinutes(5)));

    expect(MonitorIncident::count())->toBe(1);

    $incident = MonitorIncident::first();
    expect($incident->isOngoing())->toBeTrue()
        ->and($incident->site_id)->toBe($this->site->id);

    $this->monitor->recordUptimeResult(CheckResult::up(90, checkedAt: $start->copy()->addMinutes(10)));

    $incident->refresh();
    expect($incident->isOngoing())->toBeFalse()
        ->and($incident->duration_seconds)->toBe(600);
});
