<?php

use Abigah\BotCopTrafficDivision\Jobs\NotifyProbersOfChange;
use Abigah\BotCopTrafficDivision\Models\MonitoredSite;
use Abigah\BotCopTrafficDivision\Support\Signature;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    config()->set('monitoring.tenant', 'acme');
    config()->set('monitoring.checker', 'remote');

    $this->site = MonitoredSite::create(['name' => 'Site', 'owner_id' => 1]);
});

it('pokes the probers when a monitor is declared', function () {
    Queue::fake();

    $this->site->monitors()->create(['url' => 'https://site.test/']);

    Queue::assertPushed(NotifyProbersOfChange::class);
});

/**
 * A monitor's status, last check date and failure count change constantly and
 * mean nothing to a prober, which is told what to check rather than what was
 * found. Poking on every check would wake it once a minute to learn nothing.
 */
it('says nothing when only the results of checking changed', function () {
    $monitor = $this->site->monitors()->create(['url' => 'https://site.test/']);

    Queue::fake();

    $monitor->update([
        'uptime_status' => 'down',
        'uptime_last_check_date' => now(),
        'uptime_check_times_failed_in_a_row' => 3,
    ]);

    Queue::assertNothingPushed();
});

it('pokes them when what to check changes', function () {
    $monitor = $this->site->monitors()->create(['url' => 'https://site.test/']);

    Queue::fake();
    $monitor->update(['uptime_check_interval_in_minutes' => 15]);
    Queue::assertPushed(NotifyProbersOfChange::class);

    Queue::fake();
    $monitor->update(['critical' => false]);
    Queue::assertPushed(NotifyProbersOfChange::class);

    Queue::fake();
    $monitor->update(['uptime_check_enabled' => false]);
    Queue::assertPushed(NotifyProbersOfChange::class);
});

it('says nothing at all in local mode', function () {
    config()->set('monitoring.checker', 'local');

    Queue::fake();

    $this->site->monitors()->create(['url' => 'https://site.test/']);

    Queue::assertNothingPushed();
});

it('sends a signed, empty-bodied notice to every prober', function () {
    Http::fake();

    config()->set('monitoring.probers', [
        'cf-prober-1' => ['base_url' => 'https://one.test', 'secrets' => ['test-secret']],
        'eu-prober-2' => ['base_url' => 'https://two.test/', 'secrets' => ['second-secret']],
    ]);

    (new NotifyProbersOfChange)->handle();

    Http::assertSentCount(2);

    Http::assertSent(function ($request) {
        if ($request->url() !== 'https://one.test/tenants/acme/changed') {
            return false;
        }

        $timestamp = $request->header('X-Monitoring-Timestamp')[0];

        // An empty body signs as the timestamp, a dot, and nothing.
        expect($request->body())->toBe('')
            ->and($request->header('X-Monitoring-Tenant')[0])->toBe('acme')
            ->and($request->header('X-Monitoring-Signature')[0])
            ->toBe(Signature::header('test-secret', $timestamp, ''));

        return true;
    });

    Http::assertSent(fn ($request) => $request->url() === 'https://two.test/tenants/acme/changed');
});

it('skips a prober with no secret configured', function () {
    Http::fake();

    config()->set('monitoring.probers', [
        'cf-prober-1' => ['base_url' => 'https://one.test', 'secrets' => []],
    ]);

    (new NotifyProbersOfChange)->handle();

    Http::assertNothingSent();
});

it('pokes them when a heartbeat is declared or its token rotates', function () {
    Queue::fake();

    $heartbeat = $this->site->heartbeats()->create([
        'name' => 'Nightly digest',
        'token' => Str::random(48),
        'interval_minutes' => 60,
    ]);

    Queue::assertPushed(NotifyProbersOfChange::class);

    Queue::fake();
    $heartbeat->update(['token' => Str::random(48)]);
    Queue::assertPushed(NotifyProbersOfChange::class);
});
