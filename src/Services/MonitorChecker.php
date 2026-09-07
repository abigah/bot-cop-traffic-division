<?php

namespace Abigah\BotCopTrafficDivision\Services;

use Abigah\BotCopTrafficDivision\Models\Monitor;
use Abigah\BotCopTrafficDivision\Support\CheckResult;
use Carbon\Carbon;
use GrahamCampbell\GuzzleFactory\GuzzleFactory;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Pool;
use GuzzleHttp\TransferStats;
use Illuminate\Support\Collection;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Uptime checking performed by this application itself.
 *
 * This is what a prober replaces. In `remote` mode nothing here runs, and
 * results arrive at the same seam having been taken from outside this
 * infrastructure instead — which is the whole point: a check from inside the
 * network that hosts the site proves less than one from outside it.
 */
class MonitorChecker
{
    /**
     * Run the uptime check for the given monitors concurrently.
     *
     * @param  iterable<int, Monitor>  $monitors
     */
    public function check(iterable $monitors): void
    {
        /** @var Collection<int, Monitor> $monitors */
        $monitors = Collection::make($monitors)->values();

        if ($monitors->isEmpty()) {
            return;
        }

        $client = GuzzleFactory::make(
            config('monitoring.uptime.guzzle_options', []),
            config('monitoring.uptime.retry_after_milliseconds', 100),
        );

        $requests = function () use ($monitors, $client) {
            foreach ($monitors as $index => $monitor) {
                yield $index => fn () => $client->requestAsync(
                    $monitor->uptime_check_method ?: 'get',
                    (string) $monitor->url,
                    $this->requestOptions($monitor),
                );
            }
        };

        $pool = new Pool($client, $requests(), [
            'concurrency' => config('monitoring.uptime.concurrent_checks', 10),
            'fulfilled' => fn (ResponseInterface $response, int $index) => $this->recordResponse($monitors[$index], $response),
            'rejected' => fn ($reason, int $index) => $this->recordFailure($monitors[$index], $reason),
        ]);

        $pool->promise()->wait();
    }

    /** @return array<string, mixed> */
    protected function requestOptions(Monitor $monitor): array
    {
        return array_filter([
            'connect_timeout' => config('monitoring.uptime.timeout_seconds', 10),
            'timeout' => config('monitoring.uptime.timeout_seconds', 10),
            'headers' => $this->headers($monitor),
            'body' => $monitor->uptime_check_payload,
            'on_stats' => function (TransferStats $stats) use ($monitor) {
                $monitor->lastResponseTimeMs = (int) round(($stats->getTransferTime() ?? 0) * 1000);
            },
        ]);
    }

    /** @return array<string, string> */
    protected function headers(Monitor $monitor): array
    {
        return collect([
            'User-Agent' => config('monitoring.uptime.user_agent'),

            // A cached 200 is a check that never reached the origin. Asking not
            // to be served one is the half of the problem this side can fix;
            // the other half is a cache rule at the CDN, and a response that
            // comes back cached anyway is recorded as such.
            'Cache-Control' => 'no-cache',
        ])
            ->merge(config('monitoring.uptime.additional_headers', []))
            ->merge($monitor->uptime_check_additional_headers ?? [])
            ->toArray();
    }

    protected function recordResponse(Monitor $monitor, ResponseInterface $response): void
    {
        $responseTimeMs = $monitor->lastResponseTimeMs;
        $statusCode = $response->getStatusCode();
        $checkedAt = Carbon::now();
        $servedFromCache = $this->wasServedFromCache($response);

        if ($statusCode >= 400) {
            $monitor->recordUptimeResult(CheckResult::down(
                failureReason: "Responded with HTTP status code `{$statusCode}`",
                responseTimeMs: $responseTimeMs,
                checkedAt: $checkedAt,
                statusCode: $statusCode,
            ));

            return;
        }

        $body = (string) $response->getBody();

        if (filled($monitor->fail_for_string) && str_contains($body, $monitor->fail_for_string)) {
            $monitor->recordUptimeResult(CheckResult::down(
                failureReason: "Found failure string `{$monitor->fail_for_string}` on the page",
                responseTimeMs: $responseTimeMs,
                checkedAt: $checkedAt,
                statusCode: $statusCode,
            ));

            return;
        }

        if (filled($monitor->look_for_string) && ! str_contains($body, $monitor->look_for_string)) {
            $monitor->recordUptimeResult(CheckResult::down(
                failureReason: "String `{$monitor->look_for_string}` was not found on the page",
                responseTimeMs: $responseTimeMs,
                checkedAt: $checkedAt,
                statusCode: $statusCode,
            ));

            return;
        }

        $monitor->recordUptimeResult(CheckResult::up(
            responseTimeMs: $responseTimeMs,
            checkedAt: $checkedAt,
            statusCode: $statusCode,
            servedFromCache: $servedFromCache,
        ));
    }

    /**
     * Whether the response came from a cache rather than the origin. A CDN hit
     * says so outright; anything else carrying a non-zero Age has been sitting
     * somewhere too.
     */
    protected function wasServedFromCache(ResponseInterface $response): bool
    {
        if (strtoupper($response->getHeaderLine('cf-cache-status')) === 'HIT') {
            return true;
        }

        $age = $response->getHeaderLine('age');

        return $age !== '' && (int) $age > 0;
    }

    protected function recordFailure(Monitor $monitor, mixed $reason): void
    {
        if ($reason instanceof RequestException && $reason->hasResponse()) {
            $this->recordResponse($monitor, $reason->getResponse());

            return;
        }

        $monitor->recordUptimeResult(CheckResult::down(
            failureReason: $reason instanceof Throwable ? $reason->getMessage() : 'The uptime check failed',
            responseTimeMs: $monitor->lastResponseTimeMs,
        ));
    }
}
