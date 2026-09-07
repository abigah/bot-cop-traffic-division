<?php

use Abigah\BotCopTrafficDivision\Models\MonitoredSite;
use Abigah\BotCopTrafficDivision\Support\Signature;
use Abigah\BotCopTrafficDivision\Tests\Support\SignsRequests;
use Illuminate\Support\Str;

uses(SignsRequests::class);

/**
 * Every endpoint is signed in both directions.
 *
 * A prober refuses an unverified manifest rather than shrugging, and it is
 * right to: a manifest decides what it will spend the next hour requesting, so
 * an unsigned one is a way to point it at any URL it can reach.
 *
 * These assert on what the endpoints actually return. Testing the signer alone
 * would not have caught this — the signer was correct the whole time and agreed
 * with the kit's vectors; nothing asserted that an endpoint ever called it.
 */
beforeEach(function () {
    config()->set('monitoring.tenant', 'acme');

    $this->site = MonitoredSite::create(['name' => 'acme.test', 'owner_id' => 1]);
    $this->monitor = $this->site->monitors()->create(['url' => 'https://acme.test/', 'owner_id' => 1]);
});

/** @return array{schema: ?string, timestamp: ?string, signature: ?string} */
function signatureHeaders($response): array
{
    return [
        'schema' => $response->headers->get('X-Monitoring-Schema'),
        'timestamp' => $response->headers->get('X-Monitoring-Timestamp'),
        'signature' => $response->headers->get('X-Monitoring-Signature'),
    ];
}

it('signs the manifest response', function () {
    $response = $this->signedGet('monitoring/manifest')->assertOk();

    $headers = signatureHeaders($response);

    expect($headers['schema'])->toBe('1')
        ->and($headers['timestamp'])->not->toBeNull()
        ->and($headers['signature'])->toStartWith('v1=');
});

/**
 * Over the bytes as returned. Signing a re-encoded copy produces a signature
 * for a body the prober never sees, and that failure reads as a wrong secret
 * rather than a wrong body.
 */
it('signs the exact bytes of the manifest it returns', function () {
    $response = $this->signedGet('monitoring/manifest')->assertOk();

    $verified = Signature::verify(
        ['test-secret'],
        $response->headers->get('X-Monitoring-Timestamp'),
        $response->getContent(),
        $response->headers->get('X-Monitoring-Signature'),
    );

    expect($verified)->toBeTrue();
});

it('signs a manifest with no sites at all', function () {
    MonitoredSite::query()->delete();

    $response = $this->signedGet('monitoring/manifest')->assertOk();

    expect(Signature::verify(
        ['test-secret'],
        $response->headers->get('X-Monitoring-Timestamp'),
        $response->getContent(),
        $response->headers->get('X-Monitoring-Signature'),
    ))->toBeTrue()->and($response->json('sites'))->toBe([]);
});

it('signs every delivery response, not only the manifest', function () {
    $deliveries = [
        'monitoring/results' => [
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
        ],
        'monitoring/heartbeats' => [
            'schema' => 1,
            'prober' => ['id' => 'cf-prober-1', 'location' => 'cloudflare'],
            'verdicts' => [],
            'suppressed' => [],
        ],
        'monitoring/exceptions' => [
            'schema' => 1,
            'prober' => ['id' => 'cf-prober-1', 'location' => 'cloudflare'],
            'exceptions' => [],
            'suppressed' => [],
            'dropped' => [],
        ],
    ];

    foreach ($deliveries as $uri => $payload) {
        $response = $this->signedPost($uri, $payload)->assertOk();

        expect(Signature::verify(
            ['test-secret'],
            $response->headers->get('X-Monitoring-Timestamp'),
            $response->getContent(),
            $response->headers->get('X-Monitoring-Signature'),
        ))->toBeTrue("Unsigned or wrongly signed: {$uri}");
    }
});

/** Useful rather than required: it says which tenant answered. */
it('names the tenant on the way back', function () {
    expect($this->signedGet('monitoring/manifest')->headers->get('X-Monitoring-Tenant'))->toBe('acme');
});

/**
 * Signed with the current secret. A prober holds current and previous and
 * accepts either, so a rotation has no gap in this direction either.
 */
it('signs with the current secret during a rotation', function () {
    config()->set('monitoring.probers.cf-prober-1.secrets', ['the-new-secret', 'test-secret']);

    $response = $this->signedGet('monitoring/manifest', ['secret' => 'test-secret'])->assertOk();

    expect(Signature::verify(
        ['the-new-secret'],
        $response->headers->get('X-Monitoring-Timestamp'),
        $response->getContent(),
        $response->headers->get('X-Monitoring-Signature'),
    ))->toBeTrue();
});

it('signs a timestamp the prober will accept', function () {
    $response = $this->signedGet('monitoring/manifest')->assertOk();

    expect(Signature::withinTolerance($response->headers->get('X-Monitoring-Timestamp'), 300))->toBeTrue();
});

/** A refused request is not signed: there is nothing to vouch for. */
it('does not sign a refusal', function () {
    $response = $this->signedGet('monitoring/manifest', ['secret' => 'wrong'])->assertUnauthorized();

    expect($response->headers->get('X-Monitoring-Signature'))->toBeNull();
});
