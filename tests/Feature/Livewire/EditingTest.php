<?php

use Abigah\BotCopTrafficDivision\Enums\HeartbeatKind;
use Abigah\BotCopTrafficDivision\Facades\Monitoring;
use Abigah\BotCopTrafficDivision\Jobs\NotifyProbersOfChange;
use Abigah\BotCopTrafficDivision\Livewire\SiteOverview;
use Abigah\BotCopTrafficDivision\Models\Monitor;
use Abigah\BotCopTrafficDivision\Models\MonitoredSite;
use Abigah\BotCopTrafficDivision\Models\MonitorHeartbeat;
use Abigah\BotCopTrafficDivision\Tests\Fixtures\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    config()->set('monitoring.uptime.minimum_interval_minutes', 5);

    $this->owner = User::create(['name' => 'Owner', 'email' => 'owner@test.dev']);

    Monitoring::resolveCurrentOwnerUsing(fn () => $this->owner);
    Monitoring::resolveSitesUsing(fn () => MonitoredSite::where('owner_id', $this->owner->id)->get());

    $this->site = MonitoredSite::create(['name' => 'acme.test', 'owner_id' => $this->owner->id]);

    $this->page = fn () => Livewire::test(SiteOverview::class, ['site' => $this->site->id]);
});

it('adds a monitor to the site', function () {
    ($this->page)()
        ->call('newMonitor')
        ->set('monitorForm.url', 'https://acme.test/up')
        ->set('monitorForm.name', 'Health')
        ->set('monitorForm.interval', 15)
        ->call('saveMonitor')
        ->assertHasNoErrors()
        ->assertSet('showMonitorForm', false);

    $monitor = Monitor::first();

    expect($monitor->url)->toBe('https://acme.test/up')
        ->and($monitor->site_id)->toBe($this->site->id)
        ->and($monitor->owner_id)->toBe($this->owner->id)
        ->and($monitor->critical)->toBeTrue()
        ->and($monitor->uptime_check_interval_in_minutes)->toBe(15);
});

/**
 * The prober is authoritative about its own tiers and clamps anyway. Mirroring
 * the minimum here is only so the form does not offer something that will be
 * quietly overruled.
 */
it('will not offer an interval the prober would clamp', function () {
    ($this->page)()
        ->call('newMonitor')
        ->set('monitorForm.url', 'https://acme.test/')
        ->set('monitorForm.interval', 1)
        ->call('saveMonitor')
        ->assertHasErrors(['monitorForm.interval']);

    expect(Monitor::count())->toBe(0);
});

it('insists on a real url', function () {
    ($this->page)()
        ->call('newMonitor')
        ->set('monitorForm.url', 'acme.test')
        ->call('saveMonitor')
        ->assertHasErrors(['monitorForm.url']);
});

it('edits an existing monitor', function () {
    $monitor = $this->site->monitors()->create([
        'url' => 'https://acme.test/',
        'owner_id' => $this->owner->id,
        'critical' => true,
    ]);

    ($this->page)()
        ->call('editMonitor', $monitor->id)
        ->assertSet('monitorForm.url', 'https://acme.test/')
        ->set('monitorForm.critical', false)
        ->set('monitorForm.look_for_string', 'Acme Corporation')
        ->call('saveMonitor')
        ->assertHasNoErrors();

    expect($monitor->fresh()->critical)->toBeFalse()
        ->and($monitor->fresh()->look_for_string)->toBe('Acme Corporation');
});

it('pauses and resumes a monitor', function () {
    $monitor = $this->site->monitors()->create(['url' => 'https://acme.test/', 'owner_id' => $this->owner->id]);

    ($this->page)()->call('toggleMonitorPaused', $monitor->id);
    expect($monitor->fresh()->uptime_check_enabled)->toBeFalse();

    ($this->page)()->call('toggleMonitorPaused', $monitor->id);
    expect($monitor->fresh()->uptime_check_enabled)->toBeTrue();
});

it('deletes a monitor', function () {
    $monitor = $this->site->monitors()->create(['url' => 'https://acme.test/', 'owner_id' => $this->owner->id]);

    ($this->page)()->call('deleteMonitor', $monitor->id);

    expect(Monitor::count())->toBe(0);
});

/**
 * A prober is told what to check by pulling a manifest, so a new monitor is
 * only real once it has been told to pull again.
 */
it('tells the probers when the declaration changes', function () {
    config()->set('monitoring.checker', 'remote');

    Queue::fake();

    ($this->page)()
        ->call('newMonitor')
        ->set('monitorForm.url', 'https://acme.test/up')
        ->call('saveMonitor')
        ->assertHasNoErrors();

    Queue::assertPushed(NotifyProbersOfChange::class);
});

it('declares a heartbeat and shows the url to ping', function () {
    $component = ($this->page)()
        ->call('newHeartbeat')
        ->set('heartbeatForm.name', 'Nightly digest')
        ->set('heartbeatForm.interval_minutes', 60)
        ->set('heartbeatForm.grace_minutes', 10)
        ->call('saveHeartbeat')
        ->assertHasNoErrors();

    $heartbeat = MonitorHeartbeat::first();

    expect($heartbeat->name)->toBe('Nightly digest')
        ->and($heartbeat->kind)->toBe(HeartbeatKind::HEARTBEAT)
        ->and($heartbeat->interval_minutes)->toBe(60)
        ->and(strlen($heartbeat->token))->toBe(48);

    $component->assertSet('revealedToken', $heartbeat->token);
});

/**
 * Each kind carries only its own fields. The manifest schema refuses a payload
 * that mixes them, so a form that let both through would produce a manifest the
 * prober rejects.
 */
it('keeps the two kinds of work from mixing their fields', function () {
    ($this->page)()
        ->call('newHeartbeat')
        ->set('heartbeatForm.name', 'Deployment')
        ->set('heartbeatForm.kind', 'event')
        ->set('heartbeatForm.timeout_minutes', 20)
        ->call('saveHeartbeat')
        ->assertHasNoErrors();

    $event = MonitorHeartbeat::first();

    expect($event->kind)->toBe(HeartbeatKind::EVENT)
        ->and($event->timeout_minutes)->toBe(20)
        ->and($event->interval_minutes)->toBeNull()
        ->and($event->grace_minutes)->toBeNull();
});

it('needs a timeout for an event and an interval for a heartbeat', function () {
    ($this->page)()
        ->call('newHeartbeat')
        ->set('heartbeatForm.name', 'Deployment')
        ->set('heartbeatForm.kind', 'event')
        ->set('heartbeatForm.timeout_minutes', null)
        ->call('saveHeartbeat')
        ->assertHasErrors(['heartbeatForm.timeout_minutes']);

    ($this->page)()
        ->call('newHeartbeat')
        ->set('heartbeatForm.name', 'Digest')
        ->set('heartbeatForm.interval_minutes', null)
        ->call('saveHeartbeat')
        ->assertHasErrors(['heartbeatForm.interval_minutes']);
});

it('rotates a heartbeat token', function () {
    $heartbeat = $this->site->heartbeats()->create([
        'name' => 'Nightly digest',
        'token' => Str::random(48),
        'interval_minutes' => 60,
    ]);

    $original = $heartbeat->token;

    ($this->page)()
        ->call('rotateHeartbeatToken', $heartbeat->id)
        ->assertSet('revealedToken', fn ($token) => $token !== $original);

    expect($heartbeat->fresh()->token)->not->toBe($original);
});

it('marks a site as hibernating and floors its monitors', function () {
    $monitor = $this->site->monitors()->create([
        'url' => 'https://acme.test/',
        'owner_id' => $this->owner->id,
        'uptime_check_interval_in_minutes' => 5,
    ]);

    expect($monitor->effectiveIntervalMinutes())->toBe(5);

    ($this->page)()->call('toggleHibernates');

    expect($this->site->fresh()->hibernates)->toBeTrue()
        ->and($monitor->fresh()->effectiveIntervalMinutes())->toBe(60);
});

it('shows and rotates the site ingest token', function () {
    $component = ($this->page)()->call('revealIngestToken');

    $original = $component->get('revealedIngestToken');

    expect($original)->toHaveLength(48);

    $component->call('rotateIngestToken');

    expect($component->get('revealedIngestToken'))->not->toBe($original)
        ->and($this->site->fresh()->ingestToken())->toBe($component->get('revealedIngestToken'));
});

/**
 * Ids arrive from the browser. Editing goes back through the scoped query for
 * the same reason reading does.
 */
it('cannot edit a monitor belonging to another owner', function () {
    $stranger = User::create(['name' => 'Stranger', 'email' => 'stranger@test.dev']);
    $theirSite = MonitoredSite::create(['name' => 'not-yours.test', 'owner_id' => $stranger->id]);
    $theirs = $theirSite->monitors()->create(['url' => 'https://not-yours.test/', 'owner_id' => $stranger->id]);

    ($this->page)()->call('deleteMonitor', $theirs->id);
    ($this->page)()->call('toggleMonitorPaused', $theirs->id);

    expect(Monitor::find($theirs->id))->not->toBeNull()
        ->and($theirs->fresh()->uptime_check_enabled)->toBeTrue();
});

it('cannot edit a monitor on another site of its own owner', function () {
    $other = MonitoredSite::create(['name' => 'other.test', 'owner_id' => $this->owner->id]);
    $monitor = $other->monitors()->create(['url' => 'https://other.test/', 'owner_id' => $this->owner->id]);

    ($this->page)()->call('deleteMonitor', $monitor->id);

    expect(Monitor::find($monitor->id))->not->toBeNull();
});

it('cannot rotate a token on another owner\'s heartbeat', function () {
    $stranger = User::create(['name' => 'Stranger', 'email' => 'stranger@test.dev']);
    $theirSite = MonitoredSite::create(['name' => 'not-yours.test', 'owner_id' => $stranger->id]);
    $theirs = $theirSite->heartbeats()->create([
        'name' => 'Theirs',
        'token' => Str::random(48),
        'interval_minutes' => 60,
    ]);

    $original = $theirs->token;

    ($this->page)()->call('rotateHeartbeatToken', $theirs->id);
    ($this->page)()->call('deleteHeartbeat', $theirs->id);

    expect($theirs->fresh()->token)->toBe($original);
});
