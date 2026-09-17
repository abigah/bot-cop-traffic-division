<?php

namespace Abigah\BotCopTrafficDivision\Jobs;

use Abigah\BotCopTrafficDivision\Facades\Monitoring;
use Abigah\BotCopTrafficDivision\Support\Signature;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ask every prober to check one monitor now rather than at its next turn.
 *
 * The prober checks it from outside, like any other check, and delivers the
 * answer on its next sweep rather than holding it for the batch — somebody
 * pressed the button and is waiting on the screen. Nothing is pulled: the
 * result arrives through the same endpoint as every other.
 *
 * Unique per monitor for a minute, which is also the most often a prober will
 * check a monitor on request however often it is asked.
 */
class RequestProberCheck implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 60;

    public function __construct(public int $monitorId) {}

    public function uniqueId(): string
    {
        return 'monitoring-check-request-'.$this->monitorId;
    }

    public function handle(): void
    {
        $tenant = (string) config('monitoring.tenant');

        foreach (Monitoring::probers() as $proberId => $prober) {
            $secrets = Monitoring::secretsFor($proberId);

            if ($secrets === [] || empty($prober['base_url'])) {
                continue;
            }

            $this->ask($prober['base_url'], $tenant, $secrets[0]);
        }
    }

    /**
     * A failure here costs the person asking an early answer, not a check: the
     * monitor still comes round on its interval. Logged and dropped rather than
     * retried, because a retry a minute later is no longer "now".
     */
    protected function ask(string $baseUrl, string $tenant, string $secret): void
    {
        $timestamp = time();

        $url = rtrim($baseUrl, '/')."/tenants/{$tenant}/monitors/{$this->monitorId}/check";

        try {
            $response = Http::withHeaders([
                'X-Monitoring-Schema' => (string) Monitoring::schemaVersion(),
                'X-Monitoring-Timestamp' => (string) $timestamp,
                'X-Monitoring-Signature' => Signature::header($secret, $timestamp, ''),
                'X-Monitoring-Tenant' => $tenant,
            ])
                // Zero bytes, signed as nothing — see RequestProberFlush.
                ->withBody('', 'application/json')
                ->timeout(10)
                ->post($url);

            // A 404 is a prober whose last manifest predates this monitor, or
            // one too old to serve the endpoint.
            if ($response->failed()) {
                Log::warning('Monitoring check request refused', [
                    'url' => $url,
                    'status' => $response->status(),
                ]);
            }
        } catch (Throwable $exception) {
            Log::warning('Monitoring check request failed', [
                'url' => $url,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
