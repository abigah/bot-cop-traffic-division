<?php

use Abigah\BotCopTrafficDivision\Enums\HeartbeatStatus;
use Abigah\BotCopTrafficDivision\Events\HeartbeatMissed;
use Abigah\BotCopTrafficDivision\Facades\Monitoring;
use Abigah\BotCopTrafficDivision\Livewire\SiteOverview;
use Abigah\BotCopTrafficDivision\Models\MonitoredSite;
use Abigah\BotCopTrafficDivision\Tests\Fixtures\User;
use Abigah\BotCopTrafficDivision\Tests\Support\SignsRequests;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(SignsRequests::class);

/**
 * Rotating a token breaks every ping the job is still making with the old one,
 * and the job has done nothing wrong — it is waiting for a deploy that carries
 * the new token. Paging about that is this application alerting on a thing it
 * did itself.
 */
beforeEach(function () {
    config()->set('monitoring.tenant', 'acme');

    $this->owner = User::create(['name' => 'Owner', 'email' => 'owner@test.dev']);

    Monitoring::resolveCurrentOwnerUsing(fn () => $this->owner);
    Monitoring::resolveSitesUsing(fn () => MonitoredSite::where('owner_id', $this->owner->id)->get());

    // Any path here can raise an event, and an event with no way to find
    // recipients throws rather than staying quiet.
    Monitoring::resolveOwnerForSiteUsing(fn () => $this->owner);
    Monitoring::resolveRecipientsUsing(fn () => []);
    Monitoring::resolveChannelsUsing(fn () => []);

    $this->site = MonitoredSite::create(['name' => 'acme.test', 'owner_id' => $this->owner->id]);
    $this->site->monitors()->create([
        'url' => 'https://acme.test/',
        'owner_id' => $this->owner->id,
        'critical' => true,
        'uptime_status' => 'up',
        'uptime_last_check_date' => now(),
    ]);

    $this->heartbeat = $this->site->heartbeats()->create([
        'name' => 'Nightly digest',
        'token' => Str::random(48),
        'interval_minutes' => 60,
        'grace_minutes' => 10,
        'last_ping_at' => now()->subMinutes(90),
    ]);
});

it('marks a heartbeat as waiting once its token is rotated', function () {
    expect($this->heartbeat->awaitingFirstPingSinceRotation())->toBeFalse();

    Livewire::test(SiteOverview::class, ['site' => $this->site->id])
        ->call('rotateHeartbeatToken', $this->heartbeat->id);

    expect($this->heartbeat->fresh()->awaitingFirstPingSinceRotation())->toBeTrue();
});

/**
 * Still recorded — a heartbeat that is genuinely broken should stay visible —
 * but nobody is woken for it.
 */
it('records the verdict and tells nobody, in local mode', function () {
    Event::fake([HeartbeatMissed::class]);

    $this->heartbeat->forceFill(['token_rotated_at' => now()])->save();

    $this->artisan('monitoring:heartbeats:sweep')->assertSuccessful();

    Event::assertNotDispatched(HeartbeatMissed::class);

    expect($this->heartbeat->fresh()->status)->toBe(HeartbeatStatus::MISSING);
});

/**
 * A prober cannot know a token was rotated; this side can, because it is the
 * side that did it. So the verdict arrives as normal and is quietened here.
 */
it('records the verdict and tells nobody, on a delivered verdict', function () {
    Event::fake([HeartbeatMissed::class]);

    $this->heartbeat->forceFill(['token_rotated_at' => now()])->save();

    $this->signedPost('monitoring/heartbeats', [
        'schema' => 1,
        'prober' => ['id' => 'cf-prober-1', 'location' => 'cloudflare'],
        'verdicts' => [[
            'heartbeat_id' => (string) $this->heartbeat->id,
            'site_id' => (string) $this->site->id,
            'status' => 'missing',
            'observed_at' => now()->toIso8601ZuluString(),
            'last_ping_at' => now()->subMinutes(90)->toIso8601ZuluString(),
            'message' => null,
        ]],
        'suppressed' => [],
    ])->assertOk();

    Event::assertNotDispatched(HeartbeatMissed::class);

    expect($this->heartbeat->fresh()->status)->toBe(HeartbeatStatus::MISSING);
});

it('resumes alerting once a ping arrives with the new token', function () {
    $this->heartbeat->forceFill(['token_rotated_at' => now()])->save();

    $this->get("monitoring/ping/{$this->heartbeat->token}")->assertStatus(202);

    expect($this->heartbeat->fresh()->awaitingFirstPingSinceRotation())->toBeFalse()
        ->and($this->heartbeat->fresh()->token_rotated_at)->toBeNull();

    Event::fake([HeartbeatMissed::class]);

    $this->heartbeat->fresh()->forceFill(['last_ping_at' => now()->subMinutes(90)])->save();

    $this->artisan('monitoring:heartbeats:sweep')->assertSuccessful();

    Event::assertDispatched(HeartbeatMissed::class, 1);
});

/**
 * An explicit failure proves the token works as well as a success does:
 * something reached here carrying it.
 */
it('treats a failure ping as proof the token arrived', function () {
    $this->heartbeat->forceFill(['token_rotated_at' => now()])->save();

    $this->postJson("monitoring/ping/{$this->heartbeat->token}/fail", ['message' => 'Mailer refused'])
        ->assertStatus(202);

    expect($this->heartbeat->fresh()->token_rotated_at)->toBeNull();
});

it('treats a start ping the same way', function () {
    $event = $this->site->heartbeats()->create([
        'name' => 'Deployment',
        'kind' => 'event',
        'token' => Str::random(48),
        'timeout_minutes' => 20,
        'token_rotated_at' => now(),
    ]);

    $this->get("monitoring/ping/{$event->token}/start")->assertStatus(202);

    expect($event->fresh()->token_rotated_at)->toBeNull();
});

it('says on the site page that it is waiting', function () {
    $this->heartbeat->forceFill(['token_rotated_at' => now()])->save();

    Livewire::test(SiteOverview::class, ['site' => $this->site->id])
        ->assertSee('Waiting for a ping with the new token');
});

/**
 * A heartbeat whose token was rotated and which had already pinged since is
 * back to normal, so a later failure alerts as it always did.
 */
it('does not stay quiet forever', function () {
    Event::fake([HeartbeatMissed::class]);

    $this->heartbeat->forceFill([
        'token_rotated_at' => now()->subDays(2),
        'last_ping_at' => now()->subDay(),
    ])->save();

    expect($this->heartbeat->awaitingFirstPingSinceRotation())->toBeFalse();

    $this->artisan('monitoring:heartbeats:sweep')->assertSuccessful();

    Event::assertDispatched(HeartbeatMissed::class, 1);
});
