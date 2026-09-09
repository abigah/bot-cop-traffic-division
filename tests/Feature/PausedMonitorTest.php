<?php

use Abigah\BotCopTrafficDivision\Enums\UptimeStatus;
use Abigah\BotCopTrafficDivision\Events\UptimeCheckFailed;
use Abigah\BotCopTrafficDivision\Events\UptimeCheckRecovered;
use Abigah\BotCopTrafficDivision\Facades\Monitoring;
use Abigah\BotCopTrafficDivision\Models\MonitorCheck;
use Abigah\BotCopTrafficDivision\Models\MonitoredSite;
use Abigah\BotCopTrafficDivision\Models\MonitorIncident;
use Abigah\BotCopTrafficDivision\Notifications\UptimeCheckFailedNotification;
use Abigah\BotCopTrafficDivision\Support\CheckResult;
use Abigah\BotCopTrafficDivision\Tests\Fixtures\User;
use Abigah\BotCopTrafficDivision\Tests\Support\SignsRequests;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

uses(SignsRequests::class);

/**
 * Pausing a monitor has to stop the alerting now, not at the prober's next
 * reconcile.
 *
 * A prober runs off a cached plan, so it keeps delivering results for a monitor
 * disabled after that plan was built. Found on a live install: 31 monitors were
 * imported, paused immediately, and their owner was paged for the next half
 * hour.
 */
beforeEach(function () {
    config()->set('monitoring.tenant', 'acme');
    config()->set('monitoring.uptime.fire_failed_event_after_consecutive_failures', 1);

    $this->owner = User::create(['name' => 'Owner', 'email' => 'owner@test.dev']);

    Monitoring::resolveOwnerForSiteUsing(fn () => $this->owner);
    Monitoring::resolveRecipientsUsing(fn () => [$this->owner]);
    Monitoring::resolveChannelsUsing(fn () => ['mail']);

    $this->site = MonitoredSite::create(['name' => 'acme.test', 'owner_id' => $this->owner->id]);

    $this->paused = $this->site->monitors()->create([
        'url' => 'https://acme.test/',
        'owner_id' => $this->owner->id,
        'critical' => true,
        'uptime_check_enabled' => false,
        'uptime_status' => UptimeStatus::UP->value,
    ]);
});

it('opens no incident and wakes nobody for a paused monitor', function () {
    Event::fake([UptimeCheckFailed::class]);
    Notification::fake();

    $this->paused->recordUptimeResult(CheckResult::down('Connection refused'));
    $this->paused->recordUptimeResult(CheckResult::down('Connection refused'));

    Event::assertNotDispatched(UptimeCheckFailed::class);
    Notification::assertNothingSent();

    expect(MonitorIncident::count())->toBe(0);
});

/** The result is real and worth keeping; only the conclusion is refused. */
it('keeps the check a prober went to the trouble of gathering', function () {
    $this->paused->recordUptimeResult(CheckResult::down('Connection refused'));

    expect(MonitorCheck::count())->toBe(1)
        ->and(MonitorCheck::first()->status)->toBe('down');
});

it('leaves the monitor\'s status exactly where pausing left it', function () {
    $this->paused->recordUptimeResult(CheckResult::down('Connection refused'));

    $fresh = $this->paused->fresh();

    expect($fresh->uptime_status)->toBe(UptimeStatus::UP->value)
        ->and($fresh->uptime_check_times_failed_in_a_row)->toBe(0)
        ->and($fresh->uptime_check_failed_event_fired_on_date)->toBeNull();
});

/**
 * The path that actually paged someone: a prober delivering against a plan
 * built before the monitor was paused.
 */
it('ignores a delivered result for a monitor paused since the manifest', function () {
    Notification::fake();

    $this->signedPost('monitoring/results', [
        'schema' => 1,
        'prober' => ['id' => 'cf-prober-1', 'location' => 'cloudflare'],
        'results' => [[
            'check_id' => (string) Str::ulid(),
            'monitor_id' => (string) $this->paused->id,
            'checked_at' => now()->toIso8601ZuluString(),
            'up' => false,
            'response_time_ms' => null,
            'status_code' => null,
            'failure_reason' => 'Connection timed out',
            'served_from_cache' => false,
        ]],
    ])->assertOk();

    Notification::assertNothingSent();

    expect(MonitorIncident::count())->toBe(0)
        ->and(MonitorCheck::count())->toBe(1)
        ->and($this->paused->fresh()->uptime_status)->toBe(UptimeStatus::UP->value);
});

/**
 * A monitor paused mid-outage should not then be told it recovered — nothing
 * about it is being judged any more.
 */
it('does not announce a recovery for a paused monitor', function () {
    $watched = $this->site->monitors()->create([
        'url' => 'https://acme.test/up',
        'owner_id' => $this->owner->id,
        'critical' => true,
    ]);

    $watched->recordUptimeResult(CheckResult::down('Down'));
    expect(MonitorIncident::count())->toBe(1);

    $watched->forceFill(['uptime_check_enabled' => false])->save();

    Event::fake([UptimeCheckRecovered::class]);

    $watched->recordUptimeResult(CheckResult::up(120));

    Event::assertNotDispatched(UptimeCheckRecovered::class);

    // The incident it was already in stays open and honest rather than being
    // resolved by a result nobody is acting on.
    expect(MonitorIncident::ongoing()->count())->toBe(1);
});

it('resumes judging the moment it is unpaused', function () {
    Event::fake([UptimeCheckFailed::class]);

    $this->paused->recordUptimeResult(CheckResult::down('Down'));
    Event::assertNotDispatched(UptimeCheckFailed::class);

    $this->paused->forceFill(['uptime_check_enabled' => true])->save();
    $this->paused->recordUptimeResult(CheckResult::down('Down'));

    Event::assertDispatched(UptimeCheckFailed::class, 1);
    expect(MonitorIncident::count())->toBe(1);
});

it('still records a paused check as not disagreed', function () {
    $this->paused->recordUptimeResult(CheckResult::down('Down'));

    // `disagreed` means one prober contradicted another. Pausing is not that.
    expect(MonitorCheck::first()->disagreed)->toBeFalse();
});

it('sends nothing for a paused monitor even through the notification path', function () {
    Notification::fake();

    $this->paused->recordUptimeResult(CheckResult::down('Down'));
    $this->paused->recordUptimeResult(CheckResult::down('Down'));

    Notification::assertNotSentTo($this->owner, UptimeCheckFailedNotification::class);
});
