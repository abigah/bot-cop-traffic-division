<?php

use Abigah\BotCopTrafficDivision\Models\MonitoredSite;
use Abigah\BotCopTrafficDivision\Tests\Support\Kit;
use Abigah\BotCopTrafficDivision\Tests\Support\SignsRequests;
use Illuminate\Support\Str;

uses(SignsRequests::class);

beforeEach(function () {
    config()->set('monitoring.tenant', 'acme');
});

function siteWithEverything(): MonitoredSite
{
    $site = MonitoredSite::create(['name' => 'Marketing site', 'owner_id' => 1]);

    $site->monitoringSettings()->create(['hibernates' => false, 'ingest_token' => Str::random(48)]);

    $site->monitors()->create([
        'url' => 'https://marketing.test/',
        'look_for_string' => 'Welcome',
        'uptime_check_interval_in_minutes' => 5,
        'critical' => true,
    ]);

    $site->monitors()->create([
        'url' => 'https://marketing.test/blog',
        'uptime_check_method' => 'head',
        'critical' => false,
        'uptime_check_interval_in_minutes' => 15,
    ]);

    $site->heartbeats()->create([
        'name' => 'Nightly digest',
        'token' => Str::random(48),
        'interval_minutes' => 60,
        'grace_minutes' => 10,
    ]);

    $site->heartbeats()->create([
        'name' => 'Deployment',
        'kind' => 'event',
        'token' => Str::random(48),
        'timeout_minutes' => 20,
    ]);

    return $site;
}

/**
 * The schema is the specification, so this asserts against the kit rather than
 * against a hand-written expectation of what the kit says.
 */
it('emits a manifest the contract accepts', function () {
    siteWithEverything();

    $response = $this->signedGet('monitoring/manifest')->assertOk();

    expect(Kit::validate('manifest', $response->getContent()))->toBe([]);
});

it('is still valid with no sites at all', function () {
    $response = $this->signedGet('monitoring/manifest')->assertOk();

    expect(Kit::validate('manifest', $response->getContent()))->toBe([]);
});

/**
 * PHP cannot tell an empty map from an empty list, and the schema refuses the
 * second. A monitor with no custom headers is the ordinary case, so this is the
 * shape most likely to be wrong and least likely to be noticed.
 */
it('serialises an empty header map as an object', function () {
    siteWithEverything();

    $body = $this->signedGet('monitoring/manifest')->getContent();

    expect($body)->toContain('"headers":{}')
        ->and(Kit::validate('manifest', $body))->toBe([]);
});

it('sends ids as strings, since the prober only ever echoes them back', function () {
    $site = siteWithEverything();

    $manifest = $this->signedGet('monitoring/manifest')->json();

    expect($manifest['sites'][0]['id'])->toBe((string) $site->id)
        ->and($manifest['sites'][0]['monitors'][0]['id'])->toBeString()
        ->and($manifest['sites'][0]['heartbeats'][0]['id'])->toBeString();
});

it('uppercases the method and drops empty strings to null', function () {
    siteWithEverything();

    $monitors = $this->signedGet('monitoring/manifest')->json('sites.0.monitors');

    expect($monitors[0]['method'])->toBe('GET')
        ->and($monitors[1]['method'])->toBe('HEAD')
        ->and($monitors[0]['look_for_string'])->toBe('Welcome')
        ->and($monitors[0]['fail_for_string'])->toBeNull()
        ->and($monitors[0]['payload'])->toBeNull();
});

/**
 * The manifest carries what was asked for, not what will happen. The prober
 * owns its own tiers and clamps to them, and reports the clamp back — which is
 * how a request for faster checking than the tenant pays for stays visible.
 */
it('sends the requested interval, not the clamped one', function () {
    $site = MonitoredSite::create(['name' => 'Site', 'owner_id' => 1]);
    $site->monitoringSettings()->create(['hibernates' => true, 'ingest_token' => Str::random(48)]);
    $monitor = $site->monitors()->create([
        'url' => 'https://site.test/',
        'uptime_check_interval_in_minutes' => 5,
    ]);

    expect($monitor->effectiveIntervalMinutes())->toBe(60);

    $manifest = $this->signedGet('monitoring/manifest')->json();

    expect($manifest['sites'][0]['monitors'][0]['interval_minutes'])->toBe(5)
        ->and($manifest['sites'][0]['hibernates'])->toBeTrue();
});

it('leaves a disabled heartbeat out entirely', function () {
    $site = siteWithEverything();
    $site->heartbeats()->first()->update(['enabled' => false]);

    $heartbeats = $this->signedGet('monitoring/manifest')->json('sites.0.heartbeats');

    expect($heartbeats)->toHaveCount(1)
        ->and($heartbeats[0]['kind'])->toBe('event');
});

it('names the tenant the prober knows it by', function () {
    expect($this->signedGet('monitoring/manifest')->json('tenant'))->toBe('acme');
});
