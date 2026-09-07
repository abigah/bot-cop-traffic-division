<?php

use Abigah\BotCopTrafficDivision\Facades\Monitoring;
use Abigah\BotCopTrafficDivision\Livewire\Sites;
use Abigah\BotCopTrafficDivision\Models\Monitor;
use Abigah\BotCopTrafficDivision\Models\MonitoredSite;
use Abigah\BotCopTrafficDivision\Tests\Fixtures\User;
use Livewire\Livewire;

/**
 * The shape this exists for: an import lands 31 sites with one monitor each,
 * and adding a health check to all of them one at a time is 31 visits to the
 * same form.
 */
beforeEach(function () {
    config()->set('monitoring.uptime.minimum_interval_minutes', 5);

    $this->owner = User::create(['name' => 'Owner', 'email' => 'owner@test.dev']);

    Monitoring::resolveCurrentOwnerUsing(fn () => $this->owner);
    Monitoring::resolveSitesUsing(fn () => MonitoredSite::where('owner_id', $this->owner->id)->get());

    $this->imported = function (string $domain): MonitoredSite {
        $site = MonitoredSite::create(['name' => $domain, 'owner_id' => $this->owner->id]);

        $site->monitors()->create([
            'url' => "https://{$domain}/",
            'owner_id' => $this->owner->id,
            'critical' => true,
        ]);

        return $site;
    };
});

it('adds the same path to every selected site, on that site\'s own host', function () {
    $first = ($this->imported)('acme.test');
    $second = ($this->imported)('issuesiface.com');
    $untouched = ($this->imported)('dsdispatch.com');

    Livewire::test(Sites::class)
        ->set('selected', [(string) $first->id, (string) $second->id])
        ->call('openBulkForm')
        ->set('bulkForm.path', '/up')
        ->set('bulkForm.critical', false)
        ->call('bulkAddMonitor')
        ->assertHasNoErrors()
        ->assertSet('selected', []);

    expect(Monitor::pluck('url')->all())->toContain('https://acme.test/up', 'https://issuesiface.com/up')
        ->and($untouched->monitors()->count())->toBe(1)
        ->and(Monitor::where('url', 'https://acme.test/up')->first()->critical)->toBeFalse();
});

it('will not add a path below the tenant minimum', function () {
    $site = ($this->imported)('acme.test');

    Livewire::test(Sites::class)
        ->set('selected', [(string) $site->id])
        ->call('openBulkForm')
        ->set('bulkForm.interval', 1)
        ->call('bulkAddMonitor')
        ->assertHasErrors(['bulkForm.interval']);

    expect(Monitor::count())->toBe(1);
});

it('insists the path is a path', function () {
    $site = ($this->imported)('acme.test');

    Livewire::test(Sites::class)
        ->set('selected', [(string) $site->id])
        ->call('openBulkForm')
        ->set('bulkForm.path', 'up')
        ->call('bulkAddMonitor')
        ->assertHasErrors(['bulkForm.path']);
});

it('can be run twice without doubling up', function () {
    $site = ($this->imported)('acme.test');

    $run = fn () => Livewire::test(Sites::class)
        ->set('selected', [(string) $site->id])
        ->call('openBulkForm')
        ->call('bulkAddMonitor')
        ->assertHasNoErrors();

    $run();
    $run();

    expect(Monitor::where('url', 'https://acme.test/up')->count())->toBe(1);
});

/**
 * A site with no monitor has not told this application what it is, so there is
 * no host to append a path to. Skipped rather than guessed at from its name.
 */
it('skips a site that has no url yet', function () {
    $empty = MonitoredSite::create(['name' => 'nothing-yet.test', 'owner_id' => $this->owner->id]);

    Livewire::test(Sites::class)
        ->set('selected', [(string) $empty->id])
        ->call('openBulkForm')
        ->call('bulkAddMonitor')
        ->assertHasNoErrors();

    expect(Monitor::count())->toBe(0);
});

it('marks every monitor on the selected sites critical, or stops it being', function () {
    $site = ($this->imported)('acme.test');
    $site->monitors()->create(['url' => 'https://acme.test/blog', 'owner_id' => $this->owner->id, 'critical' => true]);

    Livewire::test(Sites::class)
        ->set('selected', [(string) $site->id])
        ->call('bulkSetCritical', false);

    expect(Monitor::where('critical', true)->count())->toBe(0);

    Livewire::test(Sites::class)
        ->set('selected', [(string) $site->id])
        ->call('bulkSetCritical', true);

    expect(Monitor::where('critical', false)->count())->toBe(0);
});

/**
 * The ids came from checkboxes in a browser, so the selection is narrowed to
 * what this owner actually holds before anything is written.
 */
it('ignores a selected site belonging to someone else', function () {
    $stranger = User::create(['name' => 'Stranger', 'email' => 'stranger@test.dev']);
    $theirs = MonitoredSite::create(['name' => 'not-yours.test', 'owner_id' => $stranger->id]);
    $theirs->monitors()->create(['url' => 'https://not-yours.test/', 'owner_id' => $stranger->id, 'critical' => true]);

    Livewire::test(Sites::class)
        ->set('selected', [(string) $theirs->id])
        ->call('openBulkForm')
        ->call('bulkAddMonitor')
        ->assertHasNoErrors();

    Livewire::test(Sites::class)
        ->set('selected', [(string) $theirs->id])
        ->call('bulkSetCritical', false);

    expect($theirs->monitors()->count())->toBe(1)
        ->and($theirs->monitors()->first()->critical)->toBeTrue();
});
