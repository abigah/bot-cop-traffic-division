<?php

use Abigah\BotCopTrafficDivision\Facades\Monitoring;
use Abigah\BotCopTrafficDivision\Livewire\NotificationPreferences;
use Abigah\BotCopTrafficDivision\Models\MonitoredSite;
use Abigah\BotCopTrafficDivision\Models\MonitorNotificationPreference;
use Abigah\BotCopTrafficDivision\Tests\Fixtures\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = User::create(['name' => 'Owner', 'email' => 'owner@test.dev', 'phone' => '+15550000']);

    Monitoring::resolveCurrentOwnerUsing(fn () => $this->owner);
    Monitoring::resolveSitesUsing(fn () => MonitoredSite::where('owner_id', $this->owner->id)->get());

    $this->site = MonitoredSite::create(['name' => 'acme.test', 'owner_id' => $this->owner->id]);
    $this->monitor = $this->site->monitors()->create(['url' => 'https://acme.test/', 'owner_id' => $this->owner->id]);

    $this->actingAs($this->owner);
});

/**
 * A system whose job is telling people things should tell them by default. The
 * absence of a preference row is a decision nobody has made yet, not a decision
 * to stay silent.
 */
it('sends mail and an in-app notification when nobody has set anything', function () {
    expect(Monitoring::preferenceChannelsFor($this->owner, $this->monitor, 'uptime_failed'))
        ->toBe(['mail', 'database']);
});

it('sets preferences on a site from the screen', function () {
    Livewire::test(NotificationPreferences::class)
        ->call('toggleSite', $this->site->id)
        ->call('toggle', $this->site->id, null, 'email_enabled')
        ->assertOk();

    $preference = MonitorNotificationPreference::first();

    expect($preference->site_id)->toBe($this->site->id)
        ->and($preference->email_enabled)->toBeFalse()
        ->and(Monitoring::preferenceChannelsFor($this->owner, $this->monitor, 'uptime_failed'))
        ->toBe(['database']);
});

it('turns off one kind of news without turning off the rest', function () {
    Livewire::test(NotificationPreferences::class)
        ->call('toggle', $this->site->id, null, 'uptime_recovered');

    expect(Monitoring::preferenceChannelsFor($this->owner, $this->monitor, 'uptime_recovered'))->toBe([])
        ->and(Monitoring::preferenceChannelsFor($this->owner, $this->monitor, 'uptime_failed'))
        ->toBe(['mail', 'database']);
});

/**
 * A preference on a monitor is someone saying "this one is different". Merging
 * it with the site's would take that back.
 */
it('lets a monitor override its site outright', function () {
    Livewire::test(NotificationPreferences::class)
        ->call('toggle', $this->site->id, null, 'email_enabled')
        ->call('toggle', $this->site->id, $this->monitor->id, 'sms_enabled');

    expect(Monitoring::preferenceChannelsFor($this->owner, $this->monitor, 'uptime_failed'))
        ->toBe(['mail', 'database', 'vonage']);
});

it('drops a monitor back to following its site', function () {
    Livewire::test(NotificationPreferences::class)
        ->call('toggle', $this->site->id, null, 'email_enabled')
        ->call('toggle', $this->site->id, $this->monitor->id, 'sms_enabled')
        ->call('followSite', $this->monitor->id);

    expect(MonitorNotificationPreference::whereNotNull('monitor_id')->count())->toBe(0)
        ->and(Monitoring::preferenceChannelsFor($this->owner, $this->monitor, 'uptime_failed'))
        ->toBe(['database']);
});

it('drops SMS for a recipient with no phone number', function () {
    $noPhone = User::create(['name' => 'No phone', 'email' => 'nophone@test.dev']);

    MonitorNotificationPreference::create([
        'notifiable_id' => $noPhone->id,
        'site_id' => $this->site->id,
        'sms_enabled' => true,
    ]);

    expect(Monitoring::preferenceChannelsFor($noPhone, $this->monitor, 'uptime_failed'))
        ->toBe(['mail', 'database']);
});

/**
 * Heartbeats and reported errors belong to a site and have no monitor to hang
 * from, which is why the subject can be either.
 */
it('covers heartbeats and errors through the site row', function () {
    $heartbeat = $this->site->heartbeats()->create([
        'name' => 'Nightly digest',
        'token' => str_repeat('a', 48),
        'interval_minutes' => 60,
    ]);

    Livewire::test(NotificationPreferences::class)
        ->call('toggle', $this->site->id, null, 'heartbeat_missing');

    expect(Monitoring::preferenceChannelsFor($this->owner, $heartbeat, 'heartbeat_missing'))->toBe([])
        ->and(Monitoring::preferenceChannelsFor($this->owner, $heartbeat, 'exception_reported'))
        ->toBe(['mail', 'database']);
});

it('falls back to whatever the host offers as a recipient default', function () {
    Monitoring::resolveDefaultPreferencesUsing(fn () => [
        'email_enabled' => false,
        'database_enabled' => true,
        'sms_enabled' => false,
        'uptime_failed' => true,
    ]);

    expect(Monitoring::preferenceChannelsFor($this->owner, $this->monitor, 'uptime_failed'))
        ->toBe(['database']);
});

/**
 * Field names arrive from the browser, so only the known ones are ever written.
 */
it('refuses to write a column nobody asked it to', function () {
    Livewire::test(NotificationPreferences::class)
        ->call('toggle', $this->site->id, null, 'site_id')
        ->assertOk();

    expect(MonitorNotificationPreference::count())->toBe(0);
});

it('cannot set preferences against another owner\'s site', function () {
    $stranger = User::create(['name' => 'Stranger', 'email' => 'stranger@test.dev']);
    $theirs = MonitoredSite::create(['name' => 'not-yours.test', 'owner_id' => $stranger->id]);

    Livewire::test(NotificationPreferences::class)
        ->call('toggle', $theirs->id, null, 'email_enabled')
        ->assertOk();

    expect(MonitorNotificationPreference::count())->toBe(0);
});

/**
 * Push is delivered by a channel the host supplies, so the switch exists only
 * where the host has named one. It starts off: nobody is pushed to until they
 * say so.
 */
it('loads and saves push on a site when the host has a push channel', function () {
    config()->set('monitoring.notification_channels.push', 'Host\\Notifications\\PushChannel');

    MonitorNotificationPreference::create([
        'notifiable_id' => $this->owner->id,
        'site_id' => $this->site->id,
        'push_enabled' => true,
    ]);

    $toggle = "wire:click=\"toggle('{$this->site->id}', null, 'push_enabled')\"";

    $component = Livewire::test(NotificationPreferences::class)
        ->call('toggleSite', $this->site->id)
        ->assertSeeHtml('label="Push" checked="checked" '.$toggle);

    // The monitor has no row of its own, so it follows the site.
    expect(Monitoring::preferenceChannelsFor($this->owner, $this->monitor, 'uptime_failed'))
        ->toBe(['mail', 'database', 'Host\\Notifications\\PushChannel']);

    $component->call('toggle', $this->site->id, null, 'push_enabled')
        ->assertSeeHtml('label="Push" '.$toggle);

    expect(MonitorNotificationPreference::first()->push_enabled)->toBeFalse()
        ->and(Monitoring::preferenceChannelsFor($this->owner, $this->monitor, 'uptime_failed'))
        ->toBe(['mail', 'database']);
});

it('loads and saves push on one monitor when the host has a push channel', function () {
    config()->set('monitoring.notification_channels.push', 'Host\\Notifications\\PushChannel');

    $toggle = "wire:click=\"toggle('{$this->site->id}', {$this->monitor->id}, 'push_enabled')\"";

    $component = Livewire::test(NotificationPreferences::class)
        ->call('toggleSite', $this->site->id)
        ->call('toggle', $this->site->id, $this->monitor->id, 'email_enabled')
        ->assertSeeHtml('label="Push" '.$toggle)
        ->call('toggle', $this->site->id, $this->monitor->id, 'push_enabled')
        ->assertSeeHtml('label="Push" checked="checked" '.$toggle);

    $row = MonitorNotificationPreference::whereNotNull('monitor_id')->first();

    expect($row->push_enabled)->toBeTrue()
        ->and(MonitorNotificationPreference::whereNotNull('site_id')->exists())->toBeFalse()
        ->and(Monitoring::preferenceChannelsFor($this->owner, $this->monitor, 'uptime_failed'))
        ->toBe(['database', 'Host\\Notifications\\PushChannel']);

    // A fresh screen reads the saved row rather than remembering the toggle.
    Livewire::test(NotificationPreferences::class)
        ->call('toggleSite', $this->site->id)
        ->assertSeeHtml('label="Push" checked="checked" '.$toggle);
});

it('offers no push switch when the host has not named a channel', function (?string $configured) {
    config()->set('monitoring.notification_channels.push', $configured);

    Livewire::test(NotificationPreferences::class)
        ->call('toggleSite', $this->site->id)
        ->call('toggle', $this->site->id, $this->monitor->id, 'email_enabled')
        ->assertSeeHtml('label="Email"')
        ->assertDontSeeHtml('push_enabled')
        ->assertDontSeeHtml('label="Push"')
        ->call('toggle', $this->site->id, null, 'push_enabled')
        ->assertOk();

    // Nothing to deliver it, so nothing to write either.
    expect(MonitorNotificationPreference::whereNotNull('site_id')->exists())->toBeFalse();
})->with([
    'null' => [null],
    'empty string' => [''],
]);

it('calls the database channel Desktop without renaming its column', function () {
    Livewire::test(NotificationPreferences::class)
        ->call('toggleSite', $this->site->id)
        ->assertSeeHtml("label=\"Desktop\" checked=\"checked\" wire:click=\"toggle('{$this->site->id}', null, 'database_enabled')\"")
        ->assertDontSeeHtml('label="In the app"')
        ->call('toggle', $this->site->id, null, 'database_enabled');

    expect(MonitorNotificationPreference::first()->database_enabled)->toBeFalse()
        ->and(Monitoring::preferenceChannelsFor($this->owner, $this->monitor, 'uptime_failed'))
        ->toBe(['mail']);
});

/**
 * The closed site says how someone will be reached in the words on its
 * switches. Channel keys and the host's push class are plumbing, not answers.
 */
it('summarises a closed site by the labels on its switches', function (?string $push, array $row, string $summary) {
    config()->set('monitoring.notification_channels.push', $push);

    MonitorNotificationPreference::create([
        'notifiable_id' => $this->owner->id,
        'site_id' => $this->site->id,
        ...$row,
    ]);

    $html = Livewire::test(NotificationPreferences::class)
        ->assertDontSee('Host\\Notifications\\PushChannel')
        ->assertDontSee('database')
        ->assertDontSee('vonage')
        ->html();

    // The summary is the line under the site's name on its closed card.
    preg_match("/wire:click=\"toggleSite\('{$this->site->id}'\)\".*?data-flux-text\s*>(.*?)<\/p>/s", $html, $match);

    expect(trim(strip_tags($match[1] ?? '')))->toBe($summary);
})->with([
    'push configured and on' => [
        'Host\\Notifications\\PushChannel',
        ['email_enabled' => false, 'push_enabled' => true],
        'Desktop, Push',
    ],
    'push not configured, SMS on' => [
        null,
        ['sms_enabled' => true, 'push_enabled' => true],
        'Email, Desktop, SMS',
    ],
    'the event switched off' => [
        'Host\\Notifications\\PushChannel',
        ['push_enabled' => true, 'uptime_failed' => false],
        'Nothing',
    ],
]);

it('shows the sites it can set preferences for', function () {
    Livewire::test(NotificationPreferences::class)
        ->assertOk()
        ->assertSee('acme.test')
        ->assertSee('Following the defaults')
        ->assertSee('email and a Desktop notification')
        ->assertDontSee('a notification in the app');
});
