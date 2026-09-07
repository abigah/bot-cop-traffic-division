<?php

use Abigah\BotCopTrafficDivision\Facades\Monitoring;
use Abigah\BotCopTrafficDivision\Livewire\Probers;
use Abigah\BotCopTrafficDivision\Models\MonitoredSite;
use Abigah\BotCopTrafficDivision\Models\MonitorProberStatus;
use Abigah\BotCopTrafficDivision\Models\MonitorProberUsage;
use Abigah\BotCopTrafficDivision\Tests\Fixtures\User;
use Abigah\BotCopTrafficDivision\Tests\Support\SignsRequests;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(SignsRequests::class);

beforeEach(function () {
    config()->set('monitoring.tenant', 'acme');
    config()->set('monitoring.checker', 'remote');
    config()->set('monitoring.probers', [
        'cf-prober-1' => ['base_url' => 'https://one.test', 'secrets' => ['test-secret']],
        'ic-prober-2' => ['base_url' => 'https://two.test', 'secrets' => ['second-secret']],
    ]);

    $this->owner = User::create(['name' => 'Owner', 'email' => 'owner@test.dev']);

    Monitoring::resolveCurrentOwnerUsing(fn () => $this->owner);
    Monitoring::resolveSitesUsing(fn () => MonitoredSite::where('owner_id', $this->owner->id)->get());

    $this->site = MonitoredSite::create(['name' => 'acme.test', 'owner_id' => $this->owner->id]);
    $this->monitor = $this->site->monitors()->create(['url' => 'https://acme.test/', 'owner_id' => $this->owner->id]);
});

/**
 * The failure this screen exists for. One prober going quiet while the other
 * keeps delivering looks exactly like everything being fine: the checks still
 * arrive and the dashboard stays green.
 */
it('shows a configured prober that has gone quiet', function () {
    MonitorProberStatus::create([
        'prober_id' => 'cf-prober-1',
        'location' => 'cloudflare',
        'last_seen_at' => now()->subMinutes(3),
    ]);

    MonitorProberStatus::create([
        'prober_id' => 'ic-prober-2',
        'location' => 'iconium',
        'last_seen_at' => now()->subHours(6),
    ]);

    Livewire::test(Probers::class)
        ->assertOk()
        ->assertSee('cf-prober-1')
        ->assertSee('ic-prober-2')
        ->assertSee('Delivering')
        ->assertSee('Silent');
});

it('separates a prober never heard from at all', function () {
    Livewire::test(Probers::class)
        ->assertSee('Never heard from')
        ->assertDontSee('Delivering');
});

/**
 * A verified request is the only thing that makes a prober look alive, and a
 * manifest pull counts: a prober with nothing due still reconciles, and that is
 * what tells a silent prober from a quiet one.
 */
it('records contact from any authenticated request, manifest included', function () {
    $this->signedGet('monitoring/manifest')->assertOk();

    $status = MonitorProberStatus::where('prober_id', 'cf-prober-1')->first();

    expect($status)->not->toBeNull()
        ->and($status->last_seen_at)->not->toBeNull()
        ->and($status->last_manifest_at)->not->toBeNull()
        ->and($status->last_results_at)->toBeNull();
});

it('records what a delivery was for, and where it came from', function () {
    $this->signedPost('monitoring/results', [
        'schema' => 1,
        'prober' => ['id' => 'cf-prober-1', 'location' => 'cloudflare'],
        'results' => [[
            'check_id' => (string) Str::ulid(),
            'monitor_id' => (string) $this->monitor->id,
            'checked_at' => now()->toIso8601ZuluString(),
            'up' => true,
            'response_time_ms' => 120,
            'status_code' => 200,
            'failure_reason' => null,
            'served_from_cache' => false,
        ]],
    ])->assertOk();

    $status = MonitorProberStatus::where('prober_id', 'cf-prober-1')->first();

    expect($status->last_results_at)->not->toBeNull()
        ->and($status->location)->toBe('cloudflare');
});

/**
 * A refused request must never make a prober look alive, or a broken signature
 * would read as health.
 */
it('records nothing for a request that failed verification', function () {
    $this->signedGet('monitoring/manifest', ['secret' => 'wrong'])->assertUnauthorized();

    expect(MonitorProberStatus::count())->toBe(0);
});

it('warns about a prober with no secret configured', function () {
    config()->set('monitoring.probers.ic-prober-2.secrets', []);

    Livewire::test(Probers::class)->assertSee('No secret configured');
});

it('shows the month-to-date figures the prober reported', function () {
    MonitorProberUsage::create([
        'prober_id' => 'cf-prober-1',
        'month' => now()->format('Y-m'),
        'checks' => 14320,
        'pings' => 486,
    ]);

    Livewire::test(Probers::class)
        ->assertSee('14,320')
        ->assertSee('486');
});

/**
 * With one prober this is always zero. With two it is the number that says how
 * much they are disagreeing, which is the thing worth watching once a second
 * one is deployed.
 */
it('counts the failures only one prober saw, where two are watching', function () {
    // Both probers on the same monitor: this is the overlapping shape, and the
    // only one where a disagreement can exist at all.
    $this->monitor->checks()->create([
        'check_id' => (string) Str::ulid(),
        'status' => 'up',
        'checked_at' => now()->subHour(),
        'prober_id' => 'cf-prober-1',
        'location' => 'cloudflare',
    ]);

    foreach (range(1, 3) as $i) {
        $this->monitor->checks()->create([
            'check_id' => (string) Str::ulid(),
            'status' => 'down',
            'checked_at' => now()->subHours($i),
            'prober_id' => 'ic-prober-2',
            'location' => 'iconium',
            'disagreed' => true,
        ]);
    }

    // The count and the word wrap onto separate lines in the rendered HTML, so
    // assert them apart rather than depending on the whitespace between them.
    Livewire::test(Probers::class)
        ->assertSee('Uncorroborated')
        ->assertSee('only this prober saw');
});

/**
 * Probers may be partitioned — one per organisation, each with its own sites —
 * or overlapping. The hub is told neither and works it out from what has
 * arrived, because it decides what a silent prober means.
 */
it('says what silence costs when nothing else is checking', function () {
    $this->monitor->checks()->create([
        'check_id' => (string) Str::ulid(),
        'status' => 'up',
        'checked_at' => now()->subHour(),
        'prober_id' => 'cf-prober-1',
        'location' => 'cloudflare',
    ]);

    MonitorProberStatus::create(['prober_id' => 'cf-prober-1', 'last_seen_at' => now()->subHours(6)]);

    Livewire::test(Probers::class)
        ->assertSee('Silent')
        ->assertSee('Nothing else is checking')
        ->assertSee('One prober covers each of these sites')
        // A permanent zero is noise where no two probers ever watch the same URL.
        ->assertDontSee('Uncorroborated');
});

it('drops the coverage warning once two probers watch the same monitor', function () {
    foreach (['cf-prober-1', 'ic-prober-2'] as $prober) {
        $this->monitor->checks()->create([
            'check_id' => (string) Str::ulid(),
            'status' => 'up',
            'checked_at' => now()->subHour(),
            'prober_id' => $prober,
            'location' => $prober,
        ]);
    }

    MonitorProberStatus::create(['prober_id' => 'cf-prober-1', 'last_seen_at' => now()->subHours(6)]);

    Livewire::test(Probers::class)
        ->assertSee('Silent')
        ->assertDontSee('Nothing else is checking')
        ->assertDontSee('One prober covers each of these sites');
});

it('says plainly that nothing is doing any work in local mode', function () {
    config()->set('monitoring.checker', 'local');

    Livewire::test(Probers::class)
        ->assertSee('This application checks its own monitors');
});

/**
 * History delivered by a prober that has since been removed from the config is
 * still here, and nothing it sends now is accepted. Both are worth saying.
 */
it('notices a prober that has delivered but is no longer configured', function () {
    MonitorProberStatus::create(['prober_id' => 'retired-prober', 'last_seen_at' => now()->subMonth()]);

    Livewire::test(Probers::class)
        ->assertSee('no longer configured')
        ->assertSee('retired-prober');
});
