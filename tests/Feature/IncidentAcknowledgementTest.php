<?php

use Abigah\BotCopTrafficDivision\Facades\Monitoring;
use Abigah\BotCopTrafficDivision\Models\MonitoredSite;
use Abigah\BotCopTrafficDivision\Models\MonitorIncident;
use Abigah\BotCopTrafficDivision\Support\CheckResult;
use Abigah\BotCopTrafficDivision\Tests\Fixtures\User;
use Carbon\Carbon;

beforeEach(function () {
    config()->set('monitoring.uptime.fire_failed_event_after_consecutive_failures', 1);

    $this->recipient = User::create(['name' => 'On call', 'email' => 'oncall@test.dev']);
    $this->site = MonitoredSite::create(['name' => 'Site', 'owner_id' => 1]);
    $this->monitor = $this->site->monitors()->create(['url' => 'https://site.test/']);

    Monitoring::resolveOwnerForSiteUsing(fn () => $this->recipient);
    Monitoring::resolveRecipientsUsing(fn () => [$this->recipient]);
    Monitoring::resolveChannelsUsing(fn () => ['mail']);

    $this->monitor->recordUptimeResult(CheckResult::down('Connection refused'));
    $this->incident = $this->monitor->incidents()->ongoing()->first();
});

it('starts with nobody having acknowledged the outage', function () {
    $this->assertDatabaseHas('monitor_incidents', [
        'id' => $this->incident->getKey(),
        'acknowledged_at' => null,
        'acknowledged_by' => null,
    ]);

    expect($this->incident->fresh()->acknowledged_at)->toBeNull()
        ->and($this->incident->fresh()->acknowledged_by)->toBeNull();
});

it('records the first acknowledgement with the time it was given', function () {
    $at = Carbon::parse('2026-09-15 03:14:00');

    expect($this->incident->acknowledge($this->recipient, $at))->toBeTrue()
        ->and($this->incident->acknowledged_by)->toBe($this->recipient->getKey())
        ->and($this->incident->acknowledged_at->toDateTimeString())->toBe('2026-09-15 03:14:00');
});

/**
 * Two people answering the same page at once must not take turns overwriting
 * each other. The second request is loaded before the first is written, so it
 * cannot see the acknowledgement in memory and has to lose at the database.
 */
it('keeps the first acknowledgement when someone else acknowledges later', function () {
    $colleague = User::create(['name' => 'Colleague', 'email' => 'colleague@test.dev']);
    $stale = MonitorIncident::find($this->incident->getKey());

    $this->incident->acknowledge($this->recipient, Carbon::parse('2026-09-15 03:14:00'));

    expect($stale->acknowledge($colleague, Carbon::parse('2026-09-15 03:20:00')))->toBeFalse()
        ->and($stale->acknowledged_by)->toBe($this->recipient->getKey())
        ->and($stale->acknowledged_at->toDateTimeString())->toBe('2026-09-15 03:14:00');

    $this->assertDatabaseHas('monitor_incidents', [
        'id' => $this->incident->getKey(),
        'acknowledged_by' => $this->recipient->getKey(),
    ]);
});

it('resolves who acknowledged through the host notifiable model', function () {
    $this->incident->acknowledge($this->recipient, Carbon::parse('2026-09-15 03:14:00'));

    $acknowledger = $this->incident->fresh()->acknowledgedByUser;

    expect($acknowledger)->toBeInstanceOf(config('monitoring.notifiable_model'))
        ->and($acknowledger->is($this->recipient))->toBeTrue();
});

/**
 * An acknowledgement sent from a phone that was offline can arrive after the
 * site has recovered. It is still worth keeping: someone did answer.
 */
it('still records an acknowledgement after the outage has resolved', function () {
    $this->monitor->recordUptimeResult(CheckResult::up(100));
    $this->incident->refresh();

    expect($this->incident->isOngoing())->toBeFalse()
        ->and($this->incident->acknowledge($this->recipient, Carbon::parse('2026-09-15 03:14:00')))->toBeTrue()
        ->and($this->incident->acknowledged_by)->toBe($this->recipient->getKey());
});
