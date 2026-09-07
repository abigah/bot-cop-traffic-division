<?php

use Abigah\BotCopTrafficDivision\Enums\HeartbeatStatus;
use Abigah\BotCopTrafficDivision\Jobs\PingHeartbeat;
use Abigah\BotCopTrafficDivision\Models\MonitoredSite;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    config()->set('monitoring.tenant', 'acme');
    config()->set('monitoring.probers', [
        'cf-prober-1' => ['base_url' => 'https://one.test', 'secrets' => ['test-secret']],
        'ic-prober-2' => ['base_url' => 'https://two.test/', 'secrets' => ['second-secret']],
    ]);

    $this->site = MonitoredSite::create(['name' => 'acme.test', 'owner_id' => 1]);

    $this->deployEvent = fn () => $this->site->heartbeats()->create([
        'name' => 'Deployment',
        'kind' => 'event',
        'token' => Str::random(48),
        'timeout_minutes' => 20,
        'tracks_deployments' => true,
    ]);
});

/**
 * The deploy script only ever talks to this application — that is what the
 * signed URLs are for — but in remote mode the thing that notices a deploy that
 * never finished lives on the prober, so the signal has to travel.
 */
it('tells every prober a deploy started, and that it finished', function () {
    config()->set('monitoring.checker', 'remote');

    $heartbeat = ($this->deployEvent)();

    Http::fake();

    $this->site->startDeployment();
    (new PingHeartbeat($heartbeat->token, 'start'))->handle();

    Http::assertSent(fn ($request) => $request->url() === "https://one.test/ping/{$heartbeat->token}/start");
    Http::assertSent(fn ($request) => $request->url() === "https://two.test/ping/{$heartbeat->token}/start");

    Http::fake();

    (new PingHeartbeat($heartbeat->token))->handle();

    Http::assertSent(fn ($request) => $request->url() === "https://one.test/ping/{$heartbeat->token}");
});

it('queues the signal rather than blocking the deploy', function () {
    config()->set('monitoring.checker', 'remote');

    ($this->deployEvent)();

    Queue::fake();

    $this->site->startDeployment();

    Queue::assertPushed(PingHeartbeat::class, fn ($job) => $job->action === 'start');
});

/**
 * In local mode this application is doing the judging, so there is nobody to
 * tell and it applies the signal directly.
 */
it('applies the signal itself in local mode', function () {
    config()->set('monitoring.checker', 'local');

    $heartbeat = ($this->deployEvent)();

    Http::fake();

    $this->site->startDeployment();

    expect($heartbeat->fresh()->status)->toBe(HeartbeatStatus::RUNNING)
        ->and($heartbeat->fresh()->last_start_at)->not->toBeNull();

    $this->site->finishDeployment();

    expect($heartbeat->fresh()->status)->toBe(HeartbeatStatus::OK)
        ->and($heartbeat->fresh()->last_finish_at)->not->toBeNull();

    Http::assertNothingSent();
});

/**
 * A deploy that opens a suppression window and never closes it is exactly what
 * this is for: the window lapses quietly, and without the event nothing says
 * the deploy hung.
 */
it('leaves a started deploy able to time out', function () {
    config()->set('monitoring.checker', 'local');

    $heartbeat = ($this->deployEvent)();

    $this->site->startDeployment();

    $heartbeat->refresh()->forceFill(['last_start_at' => now()->subMinutes(25)])->save();

    expect($heartbeat->fresh()->isOverdue())->toBeTrue();
});

it('does nothing for a site that has not declared a deployment event', function () {
    config()->set('monitoring.checker', 'remote');

    Queue::fake();

    $this->site->startDeployment();

    Queue::assertNothingPushed();
});

it('ignores a heartbeat that is not an event or is disabled', function () {
    config()->set('monitoring.checker', 'local');

    $this->site->heartbeats()->create([
        'name' => 'Not the deploy',
        'token' => Str::random(48),
        'interval_minutes' => 60,
        'tracks_deployments' => true,
    ]);

    $disabled = $this->site->heartbeats()->create([
        'name' => 'Old deploy event',
        'kind' => 'event',
        'token' => Str::random(48),
        'timeout_minutes' => 20,
        'tracks_deployments' => true,
        'enabled' => false,
    ]);

    $this->site->startDeployment();

    expect($this->site->deploymentHeartbeat())->toBeNull()
        ->and($disabled->fresh()->status)->toBe(HeartbeatStatus::PENDING);
});

it('skips a prober with no secret', function () {
    config()->set('monitoring.probers.ic-prober-2.secrets', []);

    Http::fake();

    (new PingHeartbeat('a-token', 'start'))->handle();

    Http::assertSentCount(1);
});
