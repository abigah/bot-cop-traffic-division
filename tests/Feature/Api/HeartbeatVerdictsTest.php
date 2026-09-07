<?php

use Abigah\BotCopTrafficDivision\Enums\HeartbeatStatus;
use Abigah\BotCopTrafficDivision\Events\HeartbeatMissed;
use Abigah\BotCopTrafficDivision\Events\HeartbeatRecovered;
use Abigah\BotCopTrafficDivision\Models\MonitoredSite;
use Abigah\BotCopTrafficDivision\Tests\Support\Kit;
use Abigah\BotCopTrafficDivision\Tests\Support\SignsRequests;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

uses(SignsRequests::class);

beforeEach(function () {
    config()->set('monitoring.tenant', 'acme');

    $this->site = MonitoredSite::create(['name' => 'Site', 'owner_id' => 1]);
    $this->heartbeat = $this->site->heartbeats()->create([
        'name' => 'Nightly digest',
        'token' => Str::random(48),
        'interval_minutes' => 60,
        'grace_minutes' => 10,
    ]);
});

function verdicts(array $verdicts, array $suppressed = []): array
{
    return [
        'schema' => 1,
        'prober' => ['id' => 'cf-prober-1', 'location' => 'cloudflare'],
        'verdicts' => $verdicts,
        'suppressed' => $suppressed,
    ];
}

it('applies a missing verdict and tells someone once', function () {
    Event::fake([HeartbeatMissed::class]);

    $payload = verdicts([[
        'heartbeat_id' => (string) $this->heartbeat->id,
        'site_id' => (string) $this->site->id,
        'status' => 'missing',
        'observed_at' => now()->toIso8601ZuluString(),
        'last_ping_at' => now()->subHours(2)->toIso8601ZuluString(),
        'message' => null,
    ]]);

    expect(Kit::validate('heartbeat-verdicts', json_encode($payload)))->toBe([]);

    $this->signedPost('monitoring/heartbeats', $payload)->assertOk();

    expect($this->heartbeat->fresh()->status)->toBe(HeartbeatStatus::MISSING);
    Event::assertDispatched(HeartbeatMissed::class, 1);

    // The prober repeats a verdict every cycle while the job stays missing.
    // Only the transition into that state is news.
    $this->signedPost('monitoring/heartbeats', $payload)->assertOk();

    Event::assertDispatched(HeartbeatMissed::class, 1);
});

it('applies a timed-out verdict for an event', function () {
    Event::fake([HeartbeatMissed::class]);

    $deploy = $this->site->heartbeats()->create([
        'name' => 'Deployment',
        'kind' => 'event',
        'token' => Str::random(48),
        'timeout_minutes' => 20,
        'status' => HeartbeatStatus::RUNNING->value,
    ]);

    $this->signedPost('monitoring/heartbeats', verdicts([[
        'heartbeat_id' => (string) $deploy->id,
        'site_id' => (string) $this->site->id,
        'status' => 'timed_out',
        'observed_at' => now()->toIso8601ZuluString(),
        'last_ping_at' => null,
        'message' => 'Deployment started at 14:48 never reported finish',
    ]]))->assertOk();

    expect($deploy->fresh()->status)->toBe(HeartbeatStatus::TIMED_OUT)
        ->and($deploy->fresh()->last_message)->toContain('never reported finish');

    Event::assertDispatched(HeartbeatMissed::class, 1);
});

it('recovers only what had been alerting', function () {
    Event::fake([HeartbeatRecovered::class]);

    $recovery = fn () => $this->signedPost('monitoring/heartbeats', verdicts([[
        'heartbeat_id' => (string) $this->heartbeat->id,
        'site_id' => (string) $this->site->id,
        'status' => 'recovered',
        'observed_at' => now()->toIso8601ZuluString(),
        'last_ping_at' => now()->toIso8601ZuluString(),
        'message' => null,
    ]]))->assertOk();

    $recovery();
    Event::assertNotDispatched(HeartbeatRecovered::class);

    $this->heartbeat->update(['status' => HeartbeatStatus::MISSING->value]);
    $recovery();

    Event::assertDispatched(HeartbeatRecovered::class, 1);
    expect($this->heartbeat->fresh()->status)->toBe(HeartbeatStatus::OK);
});

/**
 * A job that stopped because the whole site was down is part of that outage.
 * The prober does not raise a verdict for it; it says which heartbeats were
 * swallowed and when, so the incident can show them as context.
 */
it('records a suppressed heartbeat without alerting about it', function () {
    Event::fake([HeartbeatMissed::class]);

    $since = now()->subMinutes(30);

    $this->signedPost('monitoring/heartbeats', verdicts([], [[
        'heartbeat_id' => (string) $this->heartbeat->id,
        'site_id' => (string) $this->site->id,
        'since' => $since->toIso8601ZuluString(),
    ]]))->assertOk();

    Event::assertNotDispatched(HeartbeatMissed::class);

    expect($this->heartbeat->fresh()->suppressed_since->timestamp)->toBe($since->timestamp)
        ->and($this->heartbeat->fresh()->status)->toBe(HeartbeatStatus::PENDING);
});

it('clears the suppression when the heartbeat recovers', function () {
    $this->heartbeat->update([
        'status' => HeartbeatStatus::MISSING->value,
        'suppressed_since' => now()->subHour(),
    ]);

    $this->signedPost('monitoring/heartbeats', verdicts([[
        'heartbeat_id' => (string) $this->heartbeat->id,
        'site_id' => (string) $this->site->id,
        'status' => 'recovered',
        'observed_at' => now()->toIso8601ZuluString(),
        'last_ping_at' => now()->toIso8601ZuluString(),
        'message' => null,
    ]]))->assertOk();

    expect($this->heartbeat->fresh()->suppressed_since)->toBeNull();
});

it('ignores a verdict for a heartbeat that no longer exists', function () {
    $this->signedPost('monitoring/heartbeats', verdicts([[
        'heartbeat_id' => '9999',
        'site_id' => (string) $this->site->id,
        'status' => 'missing',
        'observed_at' => now()->toIso8601ZuluString(),
        'last_ping_at' => null,
        'message' => null,
    ]]))->assertOk()->assertJson(['applied' => 0]);
});
