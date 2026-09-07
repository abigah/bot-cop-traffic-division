<?php

use Abigah\BotCopTrafficDivision\Enums\UptimeStatus;
use Abigah\BotCopTrafficDivision\Events\UptimeCheckFailed;
use Abigah\BotCopTrafficDivision\Models\MonitorCheck;
use Abigah\BotCopTrafficDivision\Models\MonitorCheckGap;
use Abigah\BotCopTrafficDivision\Models\MonitoredSite;
use Abigah\BotCopTrafficDivision\Models\MonitorIncident;
use Abigah\BotCopTrafficDivision\Models\MonitorProberUsage;
use Abigah\BotCopTrafficDivision\Tests\Support\Kit;
use Abigah\BotCopTrafficDivision\Tests\Support\SignsRequests;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

uses(SignsRequests::class);

beforeEach(function () {
    config()->set('monitoring.tenant', 'acme');
    config()->set('monitoring.uptime.fire_failed_event_after_consecutive_failures', 2);

    $this->site = MonitoredSite::create(['name' => 'Site', 'owner_id' => 1]);
    $this->monitor = $this->site->monitors()->create(['url' => 'https://site.test/']);
});

function delivery(array $results, array $extra = []): array
{
    return [
        'schema' => 1,
        'prober' => ['id' => 'cf-prober-1', 'location' => 'cloudflare'],
        'results' => $results,
        ...$extra,
    ];
}

function result(int|string $monitorId, bool $up, string $checkedAt, array $extra = []): array
{
    return [
        'check_id' => (string) Str::ulid(),
        'monitor_id' => (string) $monitorId,
        'checked_at' => $checkedAt,
        'up' => $up,
        'response_time_ms' => $up ? 120 : null,
        'status_code' => $up ? 200 : null,
        'failure_reason' => $up ? null : 'Connection timed out',
        'served_from_cache' => false,
        ...$extra,
    ];
}

it('replays a batch and reports the cursor back', function () {
    $response = $this->signedPost('monitoring/results', delivery([
        result($this->monitor->id, true, now()->subMinutes(10)->toIso8601ZuluString()),
        result($this->monitor->id, true, now()->subMinutes(5)->toIso8601ZuluString()),
    ]))->assertOk();

    expect($response->json('accepted'))->toBe(2)
        ->and($response->json('accepted_through'))->toBe(MonitorCheck::orderByDesc('check_id')->first()->check_id)
        ->and(Kit::validate('results-response', $response->getContent()))->toBe([]);
});

/**
 * A batch is history and arrives however the prober happened to serialise it.
 * Replayed out of order, a recovery could land before the failure it recovered
 * from and the threshold would count the outage backwards.
 */
it('replays in checked_at order, not the order it was sent', function () {
    Event::fake([UptimeCheckFailed::class]);

    $start = now()->subHour();

    $this->signedPost('monitoring/results', delivery([
        result($this->monitor->id, false, $start->copy()->addMinutes(10)->toIso8601ZuluString()),
        result($this->monitor->id, true, $start->copy()->toIso8601ZuluString()),
        result($this->monitor->id, false, $start->copy()->addMinutes(5)->toIso8601ZuluString()),
    ]))->assertOk();

    Event::assertDispatched(UptimeCheckFailed::class, 1);

    expect($this->monitor->fresh()->uptime_status)->toBe(UptimeStatus::DOWN->value)
        ->and($this->monitor->fresh()->uptime_check_times_failed_in_a_row)->toBe(2)
        ->and(MonitorIncident::count())->toBe(1);
});

it('accepts the same delivery twice without recording it twice', function () {
    $payload = delivery([
        result($this->monitor->id, true, now()->subMinutes(5)->toIso8601ZuluString()),
    ]);

    $this->signedPost('monitoring/results', $payload)->assertOk();
    $second = $this->signedPost('monitoring/results', $payload)->assertOk();

    expect(MonitorCheck::count())->toBe(1)
        ->and($second->json('accepted'))->toBe(1);
});

it('skips a monitor that no longer exists', function () {
    $response = $this->signedPost('monitoring/results', delivery([
        result($this->monitor->id, true, now()->toIso8601ZuluString()),
        result(9999, true, now()->toIso8601ZuluString()),
    ]))->assertOk();

    expect($response->json('accepted'))->toBe(1)
        ->and(MonitorCheck::count())->toBe(1);
});

it('records a reported gap rather than mistaking it for uptime', function () {
    $this->signedPost('monitoring/results', delivery(
        [result($this->monitor->id, true, now()->toIso8601ZuluString())],
        ['dropped' => [[
            'monitor_id' => (string) $this->monitor->id,
            'count' => 3,
            'oldest' => now()->subDay()->toIso8601ZuluString(),
            'newest' => now()->subHours(20)->toIso8601ZuluString(),
        ]]],
    ))->assertOk();

    $gap = MonitorCheckGap::first();

    expect($gap->dropped_count)->toBe(3)
        ->and($gap->prober_id)->toBe('cf-prober-1');
});

it('records a clamp so a request for faster checking stays visible', function () {
    $this->signedPost('monitoring/results', delivery(
        [result($this->monitor->id, true, now()->toIso8601ZuluString())],
        ['clamped' => [[
            'monitor_id' => (string) $this->monitor->id,
            'requested_interval_minutes' => 1,
            'effective_interval_minutes' => 5,
            'reason' => 'tenant-minimum',
        ]]],
    ))->assertOk();

    expect($this->monitor->fresh()->clamped_interval_minutes)->toBe(5)
        ->and($this->monitor->fresh()->clamped_reason)->toBe('tenant-minimum');
});

it('keeps a durable copy of the month-to-date usage', function () {
    $this->signedPost('monitoring/results', delivery(
        [result($this->monitor->id, true, now()->toIso8601ZuluString())],
        ['usage' => ['month' => '2026-09', 'checks' => 14320, 'pings' => 486]],
    ))->assertOk();

    $usage = MonitorProberUsage::first();

    expect($usage->checks)->toBe(14320)
        ->and($usage->pings)->toBe(486)
        ->and($usage->month)->toBe('2026-09');

    // Delivered again, higher. The figure is a running total, not a delta.
    $this->signedPost('monitoring/results', delivery(
        [result($this->monitor->id, true, now()->addMinute()->toIso8601ZuluString())],
        ['usage' => ['month' => '2026-09', 'checks' => 14400, 'pings' => 490]],
    ))->assertOk();

    expect(MonitorProberUsage::count())->toBe(1)
        ->and(MonitorProberUsage::first()->checks)->toBe(14400);
});

it('accepts the kit example verbatim', function () {
    // The example names monitors 20 and 21; give them somewhere to land.
    $site = MonitoredSite::create(['name' => 'Kit site', 'owner_id' => 1]);

    foreach ([20, 21, 11, 10] as $id) {
        $monitor = $site->monitors()->create(['url' => "https://kit.test/{$id}"]);
        $monitor->forceFill(['id' => $id])->save();
    }

    $response = $this->signedPost('monitoring/results', Kit::example('results'))->assertOk();

    expect($response->json('accepted'))->toBe(3);
});
