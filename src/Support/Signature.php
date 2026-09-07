<?php

namespace Abigah\BotCopTrafficDivision\Support;

/**
 * The one thing both implementations must agree on byte for byte.
 *
 * HMAC-SHA256 over `{timestamp}.{raw body}`, keyed with the shared secret,
 * rendered as lowercase hex. The dot is not decoration: without a separator, a
 * timestamp of 1788660000 and a body of "1" would sign identically to
 * 178866000 and "01".
 *
 * The body is the exact bytes on the wire. A verifier that parses JSON before
 * checking the signature has already lost — it must hold the raw body, verify,
 * and only then parse.
 *
 * Pinned by `v1/hmac/vectors.json` in the conformance kit.
 */
class Signature
{
    public const PREFIX = 'v1=';

    public static function compute(string $secret, int|string $timestamp, string $body): string
    {
        return hash_hmac('sha256', "{$timestamp}.{$body}", $secret);
    }

    /** The value of the `X-Monitoring-Signature` header. */
    public static function header(string $secret, int|string $timestamp, string $body): string
    {
        return self::PREFIX.self::compute($secret, $timestamp, $body);
    }

    /**
     * Whether the given header matches the body under any of the secrets held
     * for this counterparty.
     *
     * Two are configured during rotation, current and previous, which is what
     * lets a secret change without a gap. The comparison is constant time: a
     * byte-by-byte one that returns early leaks the signature one character at
     * a time.
     *
     * @param  array<int, string>  $secrets
     */
    public static function verify(array $secrets, int|string $timestamp, string $body, string $header): bool
    {
        $offered = str_starts_with($header, self::PREFIX)
            ? substr($header, strlen(self::PREFIX))
            : $header;

        $matched = false;

        foreach ($secrets as $secret) {
            // Every secret is tried even after one matches, so the time taken
            // does not say which one it was.
            $matched = hash_equals(self::compute($secret, $timestamp, $body), $offered) || $matched;
        }

        return $matched;
    }

    /**
     * Whether a timestamp is close enough to now to be believed. This is what
     * stops a captured request being replayed tomorrow.
     */
    public static function withinTolerance(int|string $timestamp, ?int $toleranceSeconds = null, ?int $now = null): bool
    {
        $tolerance = $toleranceSeconds ?? (int) config('monitoring.signature_tolerance_seconds', 300);

        return abs(($now ?? time()) - (int) $timestamp) <= $tolerance;
    }
}
