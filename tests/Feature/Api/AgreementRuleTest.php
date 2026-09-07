<?php

use Abigah\BotCopTrafficDivision\Enums\UptimeStatus;
use Abigah\BotCopTrafficDivision\Models\MonitorCheck;
use Abigah\BotCopTrafficDivision\Models\MonitoredSite;
use Abigah\BotCopTrafficDivision\Models\MonitorIncident;
use Abigah\BotCopTrafficDivision\Tests\Support\SignsRequests;
use Illuminate\Support\Str;

uses(SignsRequests::class);

/**
 * A window is down only when every prober that reported in it says down.
 *
 * There is one prober today, so this rule does nothing — which is exactly why
 * it is worth testing now. Retrofitting it later means touching every extranet,
 * and by then the second prober is the thing proving it, in production.
 */
beforeEach(function () {
    config()->set('monitoring.tenant', 'acme');
    config()->set('monitoring.uptime.fire_failed_event_after_consecutive_failures', 1);
    config()->set('monitoring.probers', [
        'cf-prober-1' => ['base_url' => 'https://one.test', 'secrets' => ['test-secret']],
        'eu-prober-2' => ['base_url' => 'https://two.test', 'secrets' => ['second-secret']],
    ]);

    $this->site = MonitoredSite::create(['name' => 'Site', 'owner_id' => 1]);
    $this->monitor = $this->site->monitors()->create(['url' => 'https://site.test/']);
});

function deliverAs(string $proberId, string $secret, array $rows): void
{
    test()->signedPost('monitoring/results', [
        'schema' => 1,
        'prober' => ['id' => $proberId, 'location' => $proberId],
        'results' => $rows,
    ], ['prober' => $proberId, 'secret' => $secret])->assertOk();
}

function row(int $monitorId, bool $up, string $checkedAt): array
{
    return [
        'check_id' => (string) Str::ulid(),
        'monitor_id' => (string) $monitorId,
        'checked_at' => $checkedAt,
        'up' => $up,
        'response_time_ms' => $up ? 100 : null,
        'status_code' => $up ? 200 : null,
        'failure_reason' => $up ? null : 'Connection refused',
        'served_from_cache' => false,
    ];
}

it('believes a failure when it is the only prober reporting', function () {
    $at = now()->subMinute()->toIso8601ZuluString();

    deliverAs('cf-prober-1', 'test-secret', [row($this->monitor->id, false, $at)]);

    expect($this->monitor->fresh()->uptime_status)->toBe(UptimeStatus::DOWN->value)
        ->and(MonitorIncident::count())->toBe(1)
        ->and(MonitorCheck::first()->disagreed)->toBeFalse();
});

/**
 * One location losing its route to an origin is not the site being down. The
 * check is kept, tagged with where it came from, and shown as degraded — but it
 * does not advance the state machine and does not wake anyone.
 */
it('records but does not believe a failure another prober contradicts', function () {
    $at = now()->subMinute();

    deliverAs('eu-prober-2', 'second-secret', [row($this->monitor->id, true, $at->toIso8601ZuluString())]);
    deliverAs('cf-prober-1', 'test-secret', [row($this->monitor->id, false, $at->copy()->addSeconds(20)->toIso8601ZuluString())]);

    expect($this->monitor->fresh()->uptime_status)->toBe(UptimeStatus::UP->value)
        ->and(MonitorIncident::count())->toBe(0)
        ->and(MonitorCheck::count())->toBe(2);

    $disputed = MonitorCheck::where('status', 'down')->first();

    expect($disputed->disagreed)->toBeTrue()
        ->and($disputed->location)->toBe('cf-prober-1');
});

it('believes a failure both probers saw', function () {
    $at = now()->subMinute();

    deliverAs('eu-prober-2', 'second-secret', [row($this->monitor->id, false, $at->toIso8601ZuluString())]);
    deliverAs('cf-prober-1', 'test-secret', [row($this->monitor->id, false, $at->copy()->addSeconds(20)->toIso8601ZuluString())]);

    expect($this->monitor->fresh()->uptime_status)->toBe(UptimeStatus::DOWN->value)
        ->and(MonitorIncident::count())->toBe(1);
});

/**
 * Agreement is about one moment. A success an hour ago is not evidence about a
 * failure now, or an outage could never be believed at all.
 */
it('does not let a stale success excuse a current failure', function () {
    deliverAs('eu-prober-2', 'second-secret', [row($this->monitor->id, true, now()->subHours(2)->toIso8601ZuluString())]);
    deliverAs('cf-prober-1', 'test-secret', [row($this->monitor->id, false, now()->toIso8601ZuluString())]);

    expect($this->monitor->fresh()->uptime_status)->toBe(UptimeStatus::DOWN->value)
        ->and(MonitorIncident::count())->toBe(1);
});
