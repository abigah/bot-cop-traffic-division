<?php

use Abigah\BotCopTrafficDivision\Enums\HeartbeatStatus;
use Abigah\BotCopTrafficDivision\Enums\UptimeStatus;
use Abigah\BotCopTrafficDivision\Facades\Monitoring;
use Abigah\BotCopTrafficDivision\Livewire\Sites;
use Abigah\BotCopTrafficDivision\Models\MonitoredSite;
use Abigah\BotCopTrafficDivision\Tests\Fixtures\HostSite;
use Abigah\BotCopTrafficDivision\Tests\Fixtures\User;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = User::create(['name' => 'Owner', 'email' => 'owner@test.dev']);

    Monitoring::resolveCurrentOwnerUsing(fn () => $this->owner);
    Monitoring::resolveSitesUsing(fn () => MonitoredSite::where('owner_id', $this->owner->id)->get());

    $this->site = fn (string $name) => MonitoredSite::create(['name' => $name, 'owner_id' => $this->owner->id]);
});

it('lists a site and its rolled-up state', function () {
    $site = ($this->site)('acme.test');
    $site->monitors()->create(['url' => 'https://acme.test/', 'owner_id' => $this->owner->id, 'critical' => true, 'uptime_status' => UptimeStatus::UP->value]);
    $site->monitors()->create(['url' => 'https://acme.test/up', 'owner_id' => $this->owner->id, 'critical' => true, 'uptime_status' => UptimeStatus::UP->value]);

    Livewire::test(Sites::class)
        ->assertOk()
        ->assertSee('acme.test')
        ->assertSee('All monitors responding');
});

/**
 * "The site is down" means a critical monitor is down. A blog subdomain failing
 * is degraded, not down — the distinction the critical flag exists to draw.
 */
it('separates a site being down from a non-critical monitor being down', function () {
    $down = ($this->site)('down.test');
    $down->monitors()->create(['url' => 'https://down.test/', 'owner_id' => $this->owner->id, 'critical' => true, 'uptime_status' => UptimeStatus::DOWN->value]);

    $degraded = ($this->site)('degraded.test');
    $degraded->monitors()->create(['url' => 'https://degraded.test/', 'owner_id' => $this->owner->id, 'critical' => true, 'uptime_status' => UptimeStatus::UP->value]);
    $degraded->monitors()->create(['url' => 'https://degraded.test/blog', 'owner_id' => $this->owner->id, 'critical' => false, 'uptime_status' => UptimeStatus::DOWN->value]);

    Livewire::test(Sites::class)
        ->assertOk()
        ->assertSee('1 site down')
        ->assertSee('A critical monitor is down')
        ->assertSee('A non-critical monitor is down');
});

it('shows a site whose jobs have stopped even though it is answering', function () {
    $site = ($this->site)('quiet.test');
    $site->monitors()->create(['url' => 'https://quiet.test/', 'owner_id' => $this->owner->id, 'critical' => true, 'uptime_status' => UptimeStatus::UP->value]);
    $site->heartbeats()->create([
        'name' => 'Nightly digest',
        'token' => Str::random(48),
        'interval_minutes' => 60,
        'status' => HeartbeatStatus::MISSING->value,
    ]);

    Livewire::test(Sites::class)
        ->assertOk()
        ->assertSee('1 of 1 not running');
});

it('puts what is wrong at the top', function () {
    $healthy = ($this->site)('aaa-healthy.test');
    $healthy->monitors()->create(['url' => 'https://aaa-healthy.test/', 'owner_id' => $this->owner->id, 'uptime_status' => UptimeStatus::UP->value]);

    $broken = ($this->site)('zzz-broken.test');
    $broken->monitors()->create(['url' => 'https://zzz-broken.test/', 'owner_id' => $this->owner->id, 'critical' => true, 'uptime_status' => UptimeStatus::DOWN->value]);

    Livewire::test(Sites::class)
        ->assertOk()
        ->assertSeeInOrder(['zzz-broken.test', 'aaa-healthy.test']);
});

it('filters to what is down', function () {
    $up = ($this->site)('up.test');
    $up->monitors()->create(['url' => 'https://up.test/', 'owner_id' => $this->owner->id, 'uptime_status' => UptimeStatus::UP->value]);

    $down = ($this->site)('down.test');
    $down->monitors()->create(['url' => 'https://down.test/', 'owner_id' => $this->owner->id, 'critical' => true, 'uptime_status' => UptimeStatus::DOWN->value]);

    Livewire::test(Sites::class)
        ->set('filter', 'down')
        ->assertSee('down.test')
        ->assertDontSee('up.test');
});

it('searches by site name and by monitor url', function () {
    $site = ($this->site)('Marketing');
    $site->monitors()->create(['url' => 'https://marketing.test/', 'owner_id' => $this->owner->id]);

    $other = ($this->site)('Something else');
    $other->monitors()->create(['url' => 'https://other.test/', 'owner_id' => $this->owner->id]);

    Livewire::test(Sites::class)
        ->set('search', 'marketing.test')
        ->assertSee('Marketing')
        ->assertDontSee('Something else');
});

/**
 * The scope is the owner's, and a site belonging to someone else is not merely
 * hidden from the list — it is not reachable at all.
 */
it('shows only this owner\'s sites', function () {
    $stranger = User::create(['name' => 'Stranger', 'email' => 'stranger@test.dev']);
    MonitoredSite::create(['name' => 'not-yours.test', 'owner_id' => $stranger->id]);

    ($this->site)('yours.test');

    Livewire::test(Sites::class)
        ->assertSee('yours.test')
        ->assertDontSee('not-yours.test');
});

it('says so when there is nothing yet', function () {
    Livewire::test(Sites::class)->assertSee('No sites yet');
});

it('adds a site when the package owns the site model', function () {
    Livewire::test(Sites::class)
        ->set('newSiteName', 'newsite.test')
        ->call('createSite')
        ->assertHasNoErrors()
        ->assertSet('showSiteForm', false)
        ->assertSee('newsite.test');

    expect(MonitoredSite::where('name', 'newsite.test')->first()->owner_id)->toBe($this->owner->id);
});

it('insists on a name', function () {
    Livewire::test(Sites::class)
        ->set('newSiteName', '')
        ->call('createSite')
        ->assertHasErrors(['newSiteName']);
});

/**
 * A host that supplies its own site model creates sites wherever it already
 * creates them. The package has no business inventing rows in a table it does
 * not own, or guessing at columns it has never seen.
 */
it('will not create sites when the host supplies the model', function () {
    config()->set('monitoring.site_model', HostSite::class);
    Monitoring::resolveSitesUsing(fn () => collect());

    $component = Livewire::test(Sites::class);

    expect($component->instance()->canCreateSites())->toBeFalse();

    $component
        ->set('newSiteName', 'newsite.test')
        ->call('createSite')
        ->assertSee('supplies its own site model');

    expect(HostSite::count())->toBe(0);
});
