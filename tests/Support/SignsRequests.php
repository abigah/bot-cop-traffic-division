<?php

namespace Abigah\BotCopTrafficDivision\Tests\Support;

use Abigah\BotCopTrafficDivision\Support\Signature;
use Illuminate\Testing\TestResponse;

/**
 * Talks to the prober-facing API the way a prober does: raw bytes, signed, with
 * the four headers. Nothing here uses `postJson`, because re-encoding a payload
 * after signing it is exactly the mistake the signature exists to catch.
 */
trait SignsRequests
{
    protected string $proberId = 'cf-prober-1';

    protected string $proberSecret = 'test-secret';

    /** @param  array<string, mixed>  $payload */
    protected function signedPost(string $uri, array $payload, array $overrides = []): TestResponse
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        return $this->call(
            'POST',
            $uri,
            server: $this->serverHeaders($body, $overrides),
            content: $body,
        );
    }

    protected function signedGet(string $uri, array $overrides = []): TestResponse
    {
        return $this->call(
            'GET',
            $uri,
            server: $this->serverHeaders('', $overrides),
        );
    }

    /** @return array<string, string> */
    protected function serverHeaders(string $body, array $overrides = []): array
    {
        $timestamp = $overrides['timestamp'] ?? time();
        $secret = $overrides['secret'] ?? $this->proberSecret;

        return [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_MONITORING_SCHEMA' => (string) ($overrides['schema'] ?? 1),
            'HTTP_X_MONITORING_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_MONITORING_PROBER' => $overrides['prober'] ?? $this->proberId,
            'HTTP_X_MONITORING_SIGNATURE' => $overrides['signature']
                ?? Signature::header($secret, $timestamp, $body),
        ];
    }
}
