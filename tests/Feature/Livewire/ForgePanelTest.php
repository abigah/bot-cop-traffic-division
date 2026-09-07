<?php

use Abigah\BotCopTrafficDivision\Exceptions\ForgeAccessException;
use Abigah\BotCopTrafficDivision\Facades\Monitoring;
use Abigah\BotCopTrafficDivision\Livewire\MonitorForgeSites;
use Abigah\BotCopTrafficDivision\Livewire\MonitorHistory;
use Abigah\BotCopTrafficDivision\Models\Monitor;
use Abigah\BotCopTrafficDivision\Models\MonitoredSite;
use Abigah\BotCopTrafficDivision\Models\MonitorForgeSite;
use Abigah\BotCopTrafficDivision\Tests\Fixtures\FakeForgeSiteProvider;
use Abigah\BotCopTrafficDivision\Tests\Fixtures\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = User::create(['name' => 'Owner', 'email' => 'owner@test.dev']);

    Monitoring::resolveCurrentOwnerUsing(fn () => $this->owner);
    Monitoring::resolveSitesUsing(fn () => MonitoredSite::where('owner_id', $this->owner->id)->get());

    $this->site = MonitoredSite::create(['name' => 'example.test', 'owner_id' => $this->owner->id]);
    $this->monitor = $this->site->monitors()->create([
        'url' => 'https://example.test/',
        'owner_id' => $this->owner->id,
        'critical' => true,
    ]);

    $this->forge = new FakeForgeSiteProvider;

    $this->useForge = function () {
        app()->instance(FakeForgeSiteProvider::class, $this->forge);
        config()->set('monitoring.forge_provider', FakeForgeSiteProvider::class);
    };
});

/**
 * An application that does not use Forge should not know this exists.
 */
it('renders nothing at all with no integration configured', function () {
    config()->set('monitoring.forge_provider', null);

    Livewire::test(MonitorForgeSites::class, ['monitorId' => $this->monitor->id])
        ->assertOk()
        ->assertDontSee('Forge');
});

it('shows the panel on the monitor history screen when one is configured', function () {
    ($this->useForge)();

    Livewire::test(MonitorHistory::class, ['monitor' => $this->monitor->id])
        ->assertOk()
        ->assertSee('Forge');
});

it('says so when the integration is configured but this owner has no token', function () {
    ($this->useForge)();
    $this->forge->configured = false;

    Livewire::test(MonitorForgeSites::class, ['monitorId' => $this->monitor->id])
        ->assertSee('No Forge credentials for this owner')
        ->assertDontSee('Link a site');
});

it('links a Forge site to the monitor', function () {
    ($this->useForge)();

    Livewire::test(MonitorForgeSites::class, ['monitorId' => $this->monitor->id])
        ->call('openLinkModal')
        ->set('selectedSite', '7:42')
        ->call('linkSite')
        ->assertSet('showLinkModal', false);

    $linked = MonitorForgeSite::first();

    expect($linked->monitor_id)->toBe($this->monitor->id)
        ->and($linked->forge_server_id)->toBe(7)
        ->and($linked->site_name)->toBe('example.test')
        ->and($linked->server_name)->toBe('web-1');
});

it('unlinks it again', function () {
    ($this->useForge)();

    $linked = $this->monitor->forgeSites()->create([
        'forge_server_id' => 7,
        'forge_site_id' => 42,
        'server_name' => 'web-1',
        'site_name' => 'example.test',
    ]);

    Livewire::test(MonitorForgeSites::class, ['monitorId' => $this->monitor->id])
        ->call('unlinkSite', $linked->id);

    expect(MonitorForgeSite::count())->toBe(0);
});

/**
 * The gap this panel exists for: a Forge site answers on more than one domain,
 * and the aliases nobody remembered to add are the ones that go down unnoticed.
 */
it('lists the domains and which of them are already watched', function () {
    ($this->useForge)();

    $linked = $this->monitor->forgeSites()->create([
        'forge_server_id' => 7,
        'forge_site_id' => 42,
        'server_name' => 'web-1',
        'site_name' => 'example.test',
    ]);

    Livewire::test(MonitorForgeSites::class, ['monitorId' => $this->monitor->id])
        ->call('openDomainsModal', $linked->id)
        ->assertSet('domainsPrimary', 'example.test')
        ->assertSee('www.example.test')
        ->assertSee('Watch it');
});

it('starts watching an alias, on the same site and not critical', function () {
    ($this->useForge)();

    Http::fake(['*' => Http::response('<html><head><title>Example Application</title></head></html>')]);

    $linked = $this->monitor->forgeSites()->create([
        'forge_server_id' => 7,
        'forge_site_id' => 42,
        'server_name' => 'web-1',
        'site_name' => 'example.test',
    ]);

    Livewire::test(MonitorForgeSites::class, ['monitorId' => $this->monitor->id])
        ->call('openDomainsModal', $linked->id)
        ->call('createMonitorForDomain', 'www.example.test');

    $created = Monitor::where('url', 'https://www.example.test')->first();

    expect($created)->not->toBeNull()
        // A Forge site's aliases are the same deployed application.
        ->and($created->site_id)->toBe($this->site->id)
        ->and($created->owner_id)->toBe($this->owner->id)
        // An alias with no history should not, on its first check, declare the
        // whole site down.
        ->and($created->critical)->toBeFalse()
        // A status code says a server answered; a string from the page says the
        // application did.
        ->and($created->look_for_string)->toBe('Exampl')
        ->and($created->forgeSites()->count())->toBe(1);
});

it('leaves the look-for string empty when the page offers nothing usable', function () {
    ($this->useForge)();

    Http::fake(['*' => Http::response('<html><head><title>Hi</title></head></html>')]);

    $linked = $this->monitor->forgeSites()->create([
        'forge_server_id' => 7, 'forge_site_id' => 42, 'server_name' => 'web-1', 'site_name' => 'example.test',
    ]);

    Livewire::test(MonitorForgeSites::class, ['monitorId' => $this->monitor->id])
        ->call('openDomainsModal', $linked->id)
        ->call('createMonitorForDomain', 'www.example.test');

    expect(Monitor::where('url', 'https://www.example.test')->first()->look_for_string)->toBe('');
});

it('does not add the same domain twice', function () {
    ($this->useForge)();
    Http::fake();

    $linked = $this->monitor->forgeSites()->create([
        'forge_server_id' => 7, 'forge_site_id' => 42, 'server_name' => 'web-1', 'site_name' => 'example.test',
    ]);

    $component = Livewire::test(MonitorForgeSites::class, ['monitorId' => $this->monitor->id])
        ->call('openDomainsModal', $linked->id);

    $component->call('createMonitorForDomain', 'www.example.test');
    $component->call('createMonitorForDomain', 'www.example.test');

    expect(Monitor::where('url', 'https://www.example.test')->count())->toBe(1);
});

/**
 * The message is written by the host as guidance — a token missing the scopes
 * an endpoint needs — so it is shown as it is rather than as a failure.
 */
it('shows a Forge access problem in the host\'s own words', function () {
    ($this->useForge)();
    $this->forge->failure = new ForgeAccessException('That token cannot read servers. Add the servers:read scope.');

    Livewire::test(MonitorForgeSites::class, ['monitorId' => $this->monitor->id])
        ->call('openLinkModal')
        ->assertSee('Add the servers:read scope');
});

it('reports any other Forge failure without pretending to understand it', function () {
    ($this->useForge)();
    $this->forge->failure = new RuntimeException('Connection timed out');

    Livewire::test(MonitorForgeSites::class, ['monitorId' => $this->monitor->id])
        ->call('openLinkModal')
        ->assertSee('Could not reach Forge')
        ->assertSee('Connection timed out');
});

it('cannot be opened for another owner\'s monitor', function () {
    ($this->useForge)();

    $stranger = User::create(['name' => 'Stranger', 'email' => 'stranger@test.dev']);
    $theirSite = MonitoredSite::create(['name' => 'not-yours.test', 'owner_id' => $stranger->id]);
    $theirs = $theirSite->monitors()->create(['url' => 'https://not-yours.test/', 'owner_id' => $stranger->id]);

    Livewire::test(MonitorForgeSites::class, ['monitorId' => $theirs->id]);
})->throws(ModelNotFoundException::class);

it('cannot open the domains of a Forge site linked to another monitor', function () {
    ($this->useForge)();

    $other = $this->site->monitors()->create(['url' => 'https://other.test/', 'owner_id' => $this->owner->id]);

    $linked = $other->forgeSites()->create([
        'forge_server_id' => 7, 'forge_site_id' => 42, 'server_name' => 'web-1', 'site_name' => 'example.test',
    ]);

    Livewire::test(MonitorForgeSites::class, ['monitorId' => $this->monitor->id])
        ->call('openDomainsModal', $linked->id);
})->throws(ModelNotFoundException::class);
