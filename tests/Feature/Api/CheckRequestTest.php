<?php

use Abigah\BotCopTrafficDivision\Jobs\RequestProberCheck;
use Abigah\BotCopTrafficDivision\Support\Signature;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    config()->set('monitoring.tenant', 'acme');
    config()->set('monitoring.probers', [
        'cf-prober-1' => ['base_url' => 'https://one.test', 'secrets' => ['test-secret']],
        'eu-prober-2' => ['base_url' => 'https://two.test/', 'secrets' => ['second-secret']],
    ]);
});

it('sends a signed, empty-bodied request for one monitor to every prober', function () {
    Http::fake();

    (new RequestProberCheck(42))->handle();

    Http::assertSentCount(2);

    Http::assertSent(function ($request) {
        if ($request->url() !== 'https://one.test/tenants/acme/monitors/42/check') {
            return false;
        }

        $timestamp = $request->header('X-Monitoring-Timestamp')[0];

        // Signed: this makes the prober hit a site and then wake this application.
        expect($request->body())->toBe('')
            ->and($request->header('X-Monitoring-Tenant')[0])->toBe('acme')
            ->and($request->header('X-Monitoring-Signature')[0])
            ->toBe(Signature::header('test-secret', $timestamp, ''));

        return true;
    });

    Http::assertSent(fn ($request) => $request->url() === 'https://two.test/tenants/acme/monitors/42/check');
});

/**
 * A prober whose last manifest predates the monitor answers 404. The monitor
 * still comes round on its interval; the log is how anyone finds out why the
 * button did nothing.
 */
it('logs a prober that does not hold the monitor', function () {
    Http::fake(['*' => Http::response('Not found', 404)]);
    Log::spy();

    (new RequestProberCheck(42))->handle();

    Log::shouldHaveReceived('warning')->with('Monitoring check request refused', Mockery::on(
        fn (array $context) => $context['status'] === 404,
    ))->twice();
});

it('is one request a minute per monitor however often it is pressed', function () {
    expect((new RequestProberCheck(42))->uniqueId())
        ->not->toBe((new RequestProberCheck(43))->uniqueId())
        ->and((new RequestProberCheck(42))->uniqueFor)->toBe(60);
});
