<?php

use Abigah\BotCopTrafficDivision\Enums\HeartbeatStatus;
use Abigah\BotCopTrafficDivision\Facades\Monitoring;
use Abigah\BotCopTrafficDivision\Livewire\ActiveIncidentBanner;
use Abigah\BotCopTrafficDivision\Livewire\Dashboard;
use Abigah\BotCopTrafficDivision\Livewire\Incidents;
use Abigah\BotCopTrafficDivision\Livewire\MonitorHistory;
use Abigah\BotCopTrafficDivision\Models\MonitoredSite;
use Abigah\BotCopTrafficDivision\Support\CheckResult;
use Abigah\BotCopTrafficDivision\Tests\Fixtures\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    config()->set('monitoring.uptime.fire_failed_event_after_consecutive_failures', 1);

    $this->owner = User::create(['name' => 'Owner', 'email' => 'owner@test.dev']);

    Monitoring::resolveCurrentOwnerUsing(fn () => $this->owner);
    Monitoring::resolveSitesUsing(fn () => MonitoredSite::where('owner_id', $this->owner->id)->get());
    Monitoring::resolveOwnerForSiteUsing(fn () => $this->owner);
    Monitoring::resolveRecipientsUsing(fn () => []);
    Monitoring::resolveChannelsUsing(fn () => []);

    $this->site = MonitoredSite::create(['name' => 'acme.test', 'owner_id' => $this->owner->id]);
    $this->monitor = $this->site->monitors()->create([
        'url' => 'https://acme.test/',
        'owner_id' => $this->owner->id,
        'critical' => true,
    ]);
});

/**
 * The dashboard answers three questions and stops: is anything down, has
 * anything stopped running, is anything throwing errors it did not throw
 * yesterday.
 */
it('answers its three questions', function () {
    $this->monitor->recordUptimeResult(CheckResult::down('Connection refused'));

    $this->site->heartbeats()->create([
        'name' => 'Nightly digest',
        'token' => Str::random(48),
        'interval_minutes' => 60,
        'status' => HeartbeatStatus::MISSING->value,
    ]);

    $this->site->siteExceptions()->create([
        'fingerprint' => str_repeat('a', 64),
        'exception_class' => 'RuntimeException',
        'message' => 'Payment gateway failed',
        'first_seen_at' => now(),
        'last_seen_at' => now(),
        'occurrences' => 4,
    ]);

    Livewire::test(Dashboard::class)
        ->assertOk()
        ->assertSee('https://acme.test/')
        ->assertSee('Nightly digest')
        ->assertSee('RuntimeException');
});

it('says so plainly when everything is fine', function () {
    Livewire::test(Dashboard::class)
        ->assertSee('Everything is responding')
        ->assertSee('Every declared job is running')
        ->assertSee('Nothing new in the last day');
});

it('counts sites down, not monitors down', function () {
    $blog = $this->site->monitors()->create([
        'url' => 'https://acme.test/blog',
        'owner_id' => $this->owner->id,
        'critical' => false,
    ]);

    $blog->recordUptimeResult(CheckResult::down('Down'));

    // One monitor down, no critical monitor down, so no sites down.
    Livewire::test(Dashboard::class)->assertSeeInOrder(['Sites down', '0']);
});

it('renders nothing in the banner when everything is up', function () {
    Livewire::test(ActiveIncidentBanner::class)
        ->assertOk()
        ->assertDontSee('down');
});

it('shows the banner during an outage', function () {
    $this->monitor->recordUptimeResult(CheckResult::down('Connection refused'));

    Livewire::test(ActiveIncidentBanner::class)
        ->assertSee('1 monitor down')
        ->assertSee('https://acme.test/');
});

it('lists incidents and filters them', function () {
    $this->monitor->recordUptimeResult(CheckResult::down('Connection refused'));

    Livewire::test(Incidents::class)
        ->assertSee('https://acme.test/')
        ->assertSee('Connection refused')
        ->set('filter', 'resolved')
        ->assertDontSee('Connection refused');
});

it('dismisses an incident with a reason', function () {
    $this->monitor->recordUptimeResult(CheckResult::down('Connection refused'));
    $incident = $this->monitor->incidents()->ongoing()->first();

    Livewire::test(Incidents::class)
        ->call('openDismissModal', $incident->id)
        ->set('dismissalReason', 'deployment')
        ->set('dismissalNote', 'Restarting the queue')
        ->call('dismissIncident')
        ->assertOk();

    $incident->refresh();

    expect($incident->dismissal_reason)->toBe('deployment')
        ->and($incident->archived_at)->not->toBeNull();
});

it('shows a monitor history with its checks and their origin', function () {
    $this->monitor->recordUptimeResult(CheckResult::fromResultsPayload(
        [
            'check_id' => (string) Str::ulid(),
            'monitor_id' => (string) $this->monitor->id,
            'checked_at' => now()->toIso8601ZuluString(),
            'up' => true,
            'response_time_ms' => 214,
            'status_code' => 200,
            'failure_reason' => null,
            'served_from_cache' => false,
        ],
        ['id' => 'cf-prober-1', 'location' => 'cloudflare'],
    ));

    Livewire::test(MonitorHistory::class, ['monitor' => $this->monitor->id])
        ->assertOk()
        ->assertSee('https://acme.test/')
        ->assertSee('214ms')
        ->assertSee('cloudflare');
});

it('warns on the history when checks never arrived', function () {
    $this->monitor->checkGaps()->create([
        'prober_id' => 'cf-prober-1',
        'dropped_count' => 3,
        'oldest_at' => now()->subDay(),
        'newest_at' => now()->subHours(20),
        'reported_at' => now(),
    ]);

    Livewire::test(MonitorHistory::class, ['monitor' => $this->monitor->id])
        ->assertSee('Some checks never arrived');
});

it('cannot open another owner\'s monitor history', function () {
    $stranger = User::create(['name' => 'Stranger', 'email' => 'stranger@test.dev']);
    $theirSite = MonitoredSite::create(['name' => 'not-yours.test', 'owner_id' => $stranger->id]);
    $theirs = $theirSite->monitors()->create(['url' => 'https://not-yours.test/', 'owner_id' => $stranger->id]);

    Livewire::test(MonitorHistory::class, ['monitor' => $theirs->id]);
})->throws(ModelNotFoundException::class);
