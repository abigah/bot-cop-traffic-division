<?php

use Abigah\BotCopTrafficDivision\Enums\HeartbeatStatus;
use Abigah\BotCopTrafficDivision\Enums\UptimeStatus;
use Abigah\BotCopTrafficDivision\Events\HeartbeatMissed;
use Abigah\BotCopTrafficDivision\Events\HeartbeatRecovered;
use Abigah\BotCopTrafficDivision\Events\SiteExceptionReported;
use Abigah\BotCopTrafficDivision\Models\MonitoredSite;
use Abigah\BotCopTrafficDivision\Models\MonitorSiteException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->site = MonitoredSite::create(['name' => 'Site', 'owner_id' => 1]);
    $this->token = Str::random(48);

    $this->heartbeat = $this->site->heartbeats()->create([
        'name' => 'Nightly digest',
        'token' => $this->token,
        'interval_minutes' => 60,
        'grace_minutes' => 10,
    ]);
});

it('takes a ping and marks the heartbeat seen', function () {
    $this->get("monitoring/ping/{$this->token}")->assertStatus(202);

    expect($this->heartbeat->fresh()->last_ping_at)->not->toBeNull()
        ->and($this->heartbeat->fresh()->status)->toBe(HeartbeatStatus::OK);
});

it('takes a ping by POST with a message', function () {
    $this->postJson("monitoring/ping/{$this->token}", [
        'message' => 'Nightly digest sent to 412 recipients',
    ])->assertStatus(202);

    expect($this->heartbeat->fresh()->last_message)->toContain('412 recipients');
});

it('recovers a heartbeat that had been missing', function () {
    Event::fake([HeartbeatRecovered::class]);

    $this->heartbeat->update([
        'status' => HeartbeatStatus::MISSING->value,
        'suppressed_since' => now()->subHour(),
    ]);

    $this->get("monitoring/ping/{$this->token}")->assertStatus(202);

    Event::assertDispatched(HeartbeatRecovered::class, 1);

    expect($this->heartbeat->fresh()->status)->toBe(HeartbeatStatus::OK)
        ->and($this->heartbeat->fresh()->suppressed_since)->toBeNull();
});

it('says nothing when a healthy heartbeat pings again', function () {
    Event::fake([HeartbeatRecovered::class]);

    $this->get("monitoring/ping/{$this->token}")->assertStatus(202);

    Event::assertNotDispatched(HeartbeatRecovered::class);
});

it('tracks an event from start to finish', function () {
    $token = Str::random(48);
    $deploy = $this->site->heartbeats()->create([
        'name' => 'Deployment',
        'kind' => 'event',
        'token' => $token,
        'timeout_minutes' => 20,
    ]);

    $this->get("monitoring/ping/{$token}/start")->assertStatus(202);

    expect($deploy->fresh()->status)->toBe(HeartbeatStatus::RUNNING)
        ->and($deploy->fresh()->last_start_at)->not->toBeNull();

    $this->get("monitoring/ping/{$token}")->assertStatus(202);

    expect($deploy->fresh()->status)->toBe(HeartbeatStatus::OK)
        ->and($deploy->fresh()->last_finish_at)->not->toBeNull();
});

it('takes an explicit failure and says so once', function () {
    Event::fake([HeartbeatMissed::class]);

    $this->postJson("monitoring/ping/{$this->token}/fail", ['message' => 'Mailer refused the batch'])
        ->assertStatus(202);

    Event::assertDispatched(HeartbeatMissed::class, 1);

    expect($this->heartbeat->fresh()->status)->toBe(HeartbeatStatus::FAILED)
        ->and($this->heartbeat->fresh()->last_message)->toBe('Mailer refused the batch');

    $this->postJson("monitoring/ping/{$this->token}/fail")->assertStatus(202);

    Event::assertDispatched(HeartbeatMissed::class, 1);
});

it('ignores an unknown or disabled token without complaining loudly', function () {
    $this->get('monitoring/ping/'.Str::random(48))->assertNotFound();

    $this->heartbeat->update(['enabled' => false]);
    $this->get("monitoring/ping/{$this->token}")->assertNotFound();
});

it('takes an exception report against the site ingest token', function () {
    Event::fake([SiteExceptionReported::class]);

    $ingest = $this->site->ingestToken();

    $this->postJson("monitoring/report/{$ingest}", [
        'schema' => 1,
        'fingerprints' => [[
            'fingerprint' => str_repeat('b', 64),
            'class' => 'RuntimeException',
            'message' => 'Payment gateway returned an unexpected response',
            'file' => 'app/Services/Billing/Gateway.php',
            'line' => 213,
            'first_seen' => now()->toIso8601ZuluString(),
            'last_seen' => now()->toIso8601ZuluString(),
            'count' => 1,
            'trace' => null,
        ]],
    ])->assertStatus(202);

    Event::assertDispatched(SiteExceptionReported::class, 1);

    expect(MonitorSiteException::first()->exception_class)->toBe('RuntimeException');
});

/**
 * A 500 storm while the site is down is the outage. The fingerprints are worth
 * keeping — they are what the incident page shows as context — but nobody is
 * paged for them.
 */
it('records but does not page for errors thrown while the site is down', function () {
    Event::fake([SiteExceptionReported::class]);

    $this->site->monitors()->create([
        'url' => 'https://site.test/',
        'critical' => true,
        'uptime_status' => UptimeStatus::DOWN->value,
        'uptime_last_check_date' => now(),
    ]);

    $this->postJson('monitoring/report/'.$this->site->ingestToken(), [
        'schema' => 1,
        'fingerprints' => [[
            'fingerprint' => str_repeat('c', 64),
            'class' => 'Illuminate\\Database\\QueryException',
            'message' => 'Connection refused',
            'file' => 'app/Foo.php',
            'line' => 12,
            'first_seen' => now()->toIso8601ZuluString(),
            'last_seen' => now()->toIso8601ZuluString(),
            'count' => 40,
            'trace' => null,
        ]],
    ])->assertStatus(202);

    Event::assertNotDispatched(SiteExceptionReported::class);

    expect(MonitorSiteException::count())->toBe(1)
        ->and(MonitorSiteException::first()->notified_at)->toBeNull();
});

it('adds repeat counts rather than replacing them', function () {
    $ingest = $this->site->ingestToken();

    $report = fn (int $count) => $this->postJson("monitoring/report/{$ingest}", [
        'schema' => 1,
        'fingerprints' => [[
            'fingerprint' => str_repeat('d', 64),
            'class' => 'RuntimeException',
            'message' => 'Boom',
            'file' => 'app/Foo.php',
            'line' => 1,
            'first_seen' => now()->toIso8601ZuluString(),
            'last_seen' => now()->toIso8601ZuluString(),
            'count' => $count,
            'trace' => null,
        ]],
    ])->assertStatus(202);

    $report(1);
    $report(12);
    $report(5);

    expect(MonitorSiteException::first()->occurrences)->toBe(18);
});

it('ignores a report against an unknown token', function () {
    $this->postJson('monitoring/report/'.Str::random(48), ['schema' => 1, 'fingerprints' => []])
        ->assertNotFound();
});
