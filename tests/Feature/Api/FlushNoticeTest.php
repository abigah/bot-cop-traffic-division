<?php

use Abigah\BotCopTrafficDivision\Facades\Monitoring;
use Abigah\BotCopTrafficDivision\Jobs\RequestProberFlush;
use Abigah\BotCopTrafficDivision\Livewire\Dashboard;
use Abigah\BotCopTrafficDivision\Models\MonitoredSite;
use Abigah\BotCopTrafficDivision\Models\MonitorProberStatus;
use Abigah\BotCopTrafficDivision\Support\Signature;
use Abigah\BotCopTrafficDivision\Tests\Fixtures\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    config()->set('monitoring.tenant', 'acme');
    config()->set('monitoring.checker', 'remote');
    config()->set('monitoring.flush_when_stale_after_minutes', 5);
});

it('asks for the buffer when the last delivery is old enough to show', function () {
    Queue::fake();

    MonitorProberStatus::create([
        'prober_id' => 'cf-prober-1',
        'location' => 'cloudflare',
        'last_results_at' => now()->subMinutes(20),
    ]);

    expect(Monitoring::requestFlushIfStale())->toBeTrue();

    Queue::assertPushed(RequestProberFlush::class);
});

it('says nothing when a batch has just arrived', function () {
    Queue::fake();

    MonitorProberStatus::create([
        'prober_id' => 'cf-prober-1',
        'location' => 'cloudflare',
        'last_results_at' => now()->subMinute(),
    ]);

    expect(Monitoring::requestFlushIfStale())->toBeFalse();

    Queue::assertNothingPushed();
});

/**
 * A prober holding results since before this application ever heard from it is
 * the case most worth asking about, not the one to skip.
 */
it('treats never having been delivered to as stale', function () {
    Queue::fake();

    expect(Monitoring::requestFlushIfStale())->toBeTrue();

    Queue::assertPushed(RequestProberFlush::class);
});

it('says nothing in local mode, where there is no prober to ask', function () {
    config()->set('monitoring.checker', 'local');

    Queue::fake();

    expect(Monitoring::requestFlushIfStale())->toBeFalse();

    Queue::assertNothingPushed();
});

it('can be turned off', function () {
    config()->set('monitoring.flush_when_stale_after_minutes', 0);

    Queue::fake();

    expect(Monitoring::requestFlushIfStale())->toBeFalse();

    Queue::assertNothingPushed();
});

it('sends a signed, empty-bodied notice to every prober', function () {
    Http::fake();

    config()->set('monitoring.probers', [
        'cf-prober-1' => ['base_url' => 'https://one.test', 'secrets' => ['test-secret']],
        'eu-prober-2' => ['base_url' => 'https://two.test/', 'secrets' => ['second-secret']],
    ]);

    (new RequestProberFlush)->handle();

    Http::assertSentCount(2);

    Http::assertSent(function ($request) {
        if ($request->url() !== 'https://one.test/tenants/acme/flush') {
            return false;
        }

        $timestamp = $request->header('X-Monitoring-Timestamp')[0];

        // Signed, unlike a change notice: this one makes the prober deliver,
        // and a delivery wakes this application.
        expect($request->body())->toBe('')
            ->and($request->header('X-Monitoring-Tenant')[0])->toBe('acme')
            ->and($request->header('X-Monitoring-Signature')[0])
            ->toBe(Signature::header('test-secret', $timestamp, ''));

        return true;
    });

    Http::assertSent(fn ($request) => $request->url() === 'https://two.test/tenants/acme/flush');
});

it('skips a prober with no secret configured', function () {
    Http::fake();

    config()->set('monitoring.probers', [
        'cf-prober-1' => ['base_url' => 'https://one.test', 'secrets' => []],
    ]);

    (new RequestProberFlush)->handle();

    Http::assertNothingSent();
});

it('asks when somebody opens the dashboard', function () {
    Queue::fake();

    $owner = User::create(['name' => 'Owner', 'email' => 'owner@test.dev']);

    Monitoring::resolveCurrentOwnerUsing(fn () => $owner);
    Monitoring::resolveSitesUsing(fn () => MonitoredSite::where('owner_id', $owner->id)->get());

    Livewire::test(Dashboard::class);

    Queue::assertPushed(RequestProberFlush::class);
});
