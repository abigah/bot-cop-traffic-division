<?php

use Abigah\BotCopTrafficDivision\Enums\UptimeStatus;
use Abigah\BotCopTrafficDivision\Events\UptimeCheckFailed;
use Abigah\BotCopTrafficDivision\Models\MonitorCheck;
use Abigah\BotCopTrafficDivision\Models\MonitoredSite;
use Abigah\BotCopTrafficDivision\Support\CheckResult;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/**
 * A prober buffers results while a monitor is up and delivers them in a batch,
 * so what arrives here is history, not news. These are the properties that have
 * to hold for a replayed batch to reach the same conclusions a live check would
 * have.
 */
beforeEach(function () {
    config()->set('monitoring.uptime.fire_failed_event_after_consecutive_failures', 2);

    $this->site = MonitoredSite::create(['name' => 'Site', 'owner_id' => 1]);
    $this->monitor = $this->site->monitors()->create(['url' => 'https://site.test/']);
});

it('records a check at the moment it was taken, not the moment it arrived', function () {
    $takenAt = now()->subHours(3);

    $this->monitor->recordUptimeResult(CheckResult::up(140, checkedAt: $takenAt));

    expect(MonitorCheck::first()->checked_at->timestamp)->toBe($takenAt->timestamp)
        ->and($this->monitor->uptime_last_check_date->timestamp)->toBe($takenAt->timestamp);
});

it('replays a buffered batch to the same conclusion a live check would reach', function () {
    Event::fake([UptimeCheckFailed::class]);

    $start = now()->subHour();

    // An hour of results, delivered at once the moment the site went down.
    foreach ([true, true, true, false, false] as $index => $up) {
        $this->monitor->recordUptimeResult($up
            ? CheckResult::up(100, checkedAt: $start->copy()->addMinutes($index * 5))
            : CheckResult::down('Connection refused', checkedAt: $start->copy()->addMinutes($index * 5)));
    }

    Event::assertDispatched(UptimeCheckFailed::class, 1);
    expect($this->monitor->uptime_status)->toBe(UptimeStatus::DOWN->value)
        ->and($this->monitor->uptime_check_times_failed_in_a_row)->toBe(2)
        ->and(MonitorCheck::count())->toBe(5);
});

it('is idempotent on check_id, so a retried delivery replays as nothing', function () {
    $result = CheckResult::up(120);

    $first = $this->monitor->recordUptimeResult($result);
    $second = $this->monitor->recordUptimeResult($result);

    expect(MonitorCheck::count())->toBe(1)
        ->and($second->is($first))->toBeTrue();
});

/**
 * A delivery can arrive late — the prober was buffering, or the hub was
 * unreachable for a while. The row is still history worth keeping, but the last
 * check date has to mean the most recent check, or the monitor would look
 * overdue every time a stale batch landed.
 */
it('keeps a late result as history without winding the clock backwards', function () {
    $recent = now()->subMinutes(2);

    $this->monitor->recordUptimeResult(CheckResult::up(100, checkedAt: $recent));
    $this->monitor->recordUptimeResult(CheckResult::up(100, checkedAt: now()->subHours(2)));

    expect(MonitorCheck::count())->toBe(2)
        ->and($this->monitor->uptime_last_check_date->timestamp)->toBe($recent->timestamp);
});

it('stores which prober saw what', function () {
    $this->monitor->recordUptimeResult(CheckResult::fromResultsPayload(
        [
            'check_id' => (string) Str::ulid(),
            'monitor_id' => (string) $this->monitor->id,
            'checked_at' => now()->toIso8601ZuluString(),
            'up' => true,
            'response_time_ms' => 214,
            'status_code' => 200,
            'failure_reason' => null,
            'served_from_cache' => false,
        ],
        ['id' => 'cf-prober-1', 'location' => 'cloudflare'],
    ));

    $check = MonitorCheck::first();

    expect($check->prober_id)->toBe('cf-prober-1')
        ->and($check->location)->toBe('cloudflare')
        ->and($check->status_code)->toBe(200)
        ->and($check->isRemote())->toBeTrue();
});

/**
 * A cached 200 is a check that never reached the origin. It is still recorded
 * as up — it is a response — but the misconfiguration is flagged rather than
 * believed.
 */
it('flags a result that was served from cache without calling it an outage', function () {
    $this->monitor->recordUptimeResult(CheckResult::up(12, servedFromCache: true));

    expect($this->monitor->uptime_status)->toBe(UptimeStatus::UP->value)
        ->and($this->monitor->fresh()->served_from_cache_at)->not->toBeNull()
        ->and(MonitorCheck::first()->served_from_cache)->toBeTrue();
});
