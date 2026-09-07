<?php

use Abigah\BotCopTrafficDivision\Support\Signature;
use Abigah\BotCopTrafficDivision\Tests\Support\SignsRequests;

uses(SignsRequests::class);

beforeEach(function () {
    config()->set('monitoring.tenant', 'acme');
});

it('lets a correctly signed request through', function () {
    $this->signedGet('monitoring/manifest')->assertOk();
});

it('refuses a schema version it does not implement', function () {
    $this->signedGet('monitoring/manifest', ['schema' => 2])->assertStatus(400);
});

it('refuses a timestamp outside the window', function () {
    $this->signedGet('monitoring/manifest', ['timestamp' => time() - 301])->assertUnauthorized();
    $this->signedGet('monitoring/manifest', ['timestamp' => time() + 301])->assertUnauthorized();
});

it('refuses a signature made with the wrong secret', function () {
    $this->signedGet('monitoring/manifest', ['secret' => 'not-the-secret'])->assertUnauthorized();
});

it('refuses an unknown prober', function () {
    $this->signedGet('monitoring/manifest', ['prober' => 'cf-prober-9'])->assertUnauthorized();
});

it('refuses an unsigned request', function () {
    $this->getJson('monitoring/manifest')->assertStatus(400);
});

/**
 * The signature covers the bytes on the wire. A body that changed in transit
 * has to fail even though it is still valid JSON, which is the whole reason the
 * raw content is verified before anything parses it.
 */
it('refuses a body that was altered after signing', function () {
    $payload = ['schema' => 1, 'prober' => ['id' => 'cf-prober-1', 'location' => 'cloudflare'], 'results' => []];
    $timestamp = time();
    $signature = Signature::header('test-secret', $timestamp, json_encode($payload, JSON_THROW_ON_ERROR));

    $tampered = json_encode([...$payload, 'results' => [['injected' => true]]], JSON_THROW_ON_ERROR);

    $this->call('POST', 'monitoring/results', server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_MONITORING_SCHEMA' => '1',
        'HTTP_X_MONITORING_TIMESTAMP' => (string) $timestamp,
        'HTTP_X_MONITORING_PROBER' => 'cf-prober-1',
        'HTTP_X_MONITORING_SIGNATURE' => $signature,
    ], content: $tampered)->assertUnauthorized();
});

it('accepts a request signed with the previous secret during rotation', function () {
    config()->set('monitoring.probers.cf-prober-1.secrets', ['the-new-secret', 'test-secret']);

    $this->signedGet('monitoring/manifest', ['secret' => 'test-secret'])->assertOk();
    $this->signedGet('monitoring/manifest', ['secret' => 'the-new-secret'])->assertOk();
    $this->signedGet('monitoring/manifest', ['secret' => 'a-third-secret'])->assertUnauthorized();
});

it('refuses a body whose schema field contradicts the header', function () {
    $this->signedPost('monitoring/results', [
        'schema' => 2,
        'prober' => ['id' => 'cf-prober-1', 'location' => 'cloudflare'],
        'results' => [],
    ])->assertStatus(400);
});
