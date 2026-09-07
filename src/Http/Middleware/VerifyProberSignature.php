<?php

namespace Abigah\BotCopTrafficDivision\Http\Middleware;

use Abigah\BotCopTrafficDivision\Facades\Monitoring;
use Abigah\BotCopTrafficDivision\Models\MonitorProberStatus;
use Abigah\BotCopTrafficDivision\Support\Signature;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a prober.
 *
 * The order matters and is the kit's, not a preference. Schema first and
 * timestamp second are cheap and reject most abuse before any cryptography
 * happens; neither is a substitute for the third. The body is not parsed until
 * all three have passed — a verifier that decodes JSON first has signed
 * something other than what arrived.
 */
class VerifyProberSignature
{
    public const SCHEMA_HEADER = 'X-Monitoring-Schema';

    public const TIMESTAMP_HEADER = 'X-Monitoring-Timestamp';

    public const SIGNATURE_HEADER = 'X-Monitoring-Signature';

    public const PROBER_HEADER = 'X-Monitoring-Prober';

    public const TENANT_HEADER = 'X-Monitoring-Tenant';

    public function handle(Request $request, Closure $next): Response
    {
        /*
         | An unknown version means the sender believes something about this
         | payload that this application does not. Refuse it; do not fall back
         | to the newest version known here.
         */
        if ((int) $request->header(self::SCHEMA_HEADER) !== Monitoring::schemaVersion()) {
            return $this->refuse('Unsupported schema version.', 400);
        }

        $timestamp = $request->header(self::TIMESTAMP_HEADER);

        if ($timestamp === null || ! ctype_digit((string) $timestamp)) {
            return $this->refuse('Missing or malformed timestamp.');
        }

        if (! Signature::withinTolerance($timestamp)) {
            return $this->refuse('Timestamp outside the permitted window.');
        }

        $proberId = (string) $request->header(self::PROBER_HEADER);
        $secrets = Monitoring::secretsFor($proberId);

        if ($secrets === []) {
            // Same answer as a bad signature: an unknown prober id learns
            // nothing about which ids exist.
            return $this->refuse('Signature verification failed.');
        }

        $verified = Signature::verify(
            $secrets,
            $timestamp,
            $request->getContent(),
            (string) $request->header(self::SIGNATURE_HEADER),
        );

        if (! $verified) {
            return $this->refuse('Signature verification failed.');
        }

        /*
         | The schema version travels in a header, which is outside the signed
         | string, and again inside every signed body — so for those payloads it
         | is covered transitively. A mismatch between the two means one of them
         | was tampered with.
         */
        $declared = $request->json('schema');

        if ($declared !== null && (int) $declared !== Monitoring::schemaVersion()) {
            return $this->refuse('Schema version in the body does not match the header.', 400);
        }

        $request->attributes->set('monitoring.prober_id', $proberId);

        $this->recordContact($request, $proberId);

        /*
         | Signed both ways. A manifest decides what a prober will spend the
         | next hour requesting, so an unsigned one is a way to point it at any
         | URL it can reach — it refuses an unverified manifest rather than
         | shrugging, and it is right to.
         |
         | Signed with the current secret. A prober holds current and previous
         | and accepts either, which is what makes a rotation gapless.
         */
        return $this->sign($next($request), $secrets[0]);
    }

    /**
     * Sign what is being sent back, over the exact bytes being sent.
     *
     * Read the content, sign that, return it untouched. Anything that
     * re-encodes between signing and sending — a formatter, a re-serialised
     * JsonResponse — produces a signature over bytes the prober never sees, and
     * the failure looks like a wrong secret rather than a wrong body.
     */
    protected function sign(Response $response, string $secret): Response
    {
        $timestamp = Carbon::now()->getTimestamp();

        $response->headers->set(self::SCHEMA_HEADER, (string) Monitoring::schemaVersion());
        $response->headers->set(self::TIMESTAMP_HEADER, (string) $timestamp);
        $response->headers->set(
            self::SIGNATURE_HEADER,
            Signature::header($secret, $timestamp, (string) $response->getContent()),
        );

        // Optional, and useful: it says which tenant answered.
        $response->headers->set(self::TENANT_HEADER, (string) config('monitoring.tenant'));

        return $response;
    }

    /**
     * Note that this prober reached us, and what for.
     *
     * Recorded here rather than in each controller because reachability is what
     * is being observed, not productivity: a prober with nothing due still
     * pulls a manifest, and that is exactly the signal that tells a silent
     * prober from a quiet one.
     *
     * Only ever after the signature has verified, so an unauthenticated request
     * cannot make a prober look alive.
     */
    protected function recordContact(Request $request, string $proberId): void
    {
        $now = Carbon::now();

        $column = match (true) {
            $request->is('*/results') => 'last_results_at',
            $request->is('*/heartbeats') => 'last_heartbeats_at',
            $request->is('*/exceptions') => 'last_exceptions_at',
            $request->is('*/manifest') => 'last_manifest_at',
            default => null,
        };

        MonitorProberStatus::updateOrCreate(
            ['prober_id' => $proberId],
            array_filter([
                'last_seen_at' => $now,
                'location' => $request->json('prober.location'),
                $column => $column === null ? null : $now,
            ]),
        );
    }

    protected function refuse(string $message, int $status = 401): Response
    {
        return response()->json(['message' => $message], $status);
    }
}
