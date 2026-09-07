<?php

namespace Abigah\BotCopTrafficDivision\Jobs;

use Abigah\BotCopTrafficDivision\Facades\Monitoring;
use Abigah\BotCopTrafficDivision\Support\Signature;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tell every prober that what this application wants checked has changed.
 *
 * The body is empty; the notice says only "pull again". That makes this a fast
 * path rather than a correctness requirement — the prober reconciles on its own
 * schedule anyway, so a notice that never arrives costs a delay, not a
 * divergence. Which is why a failure here is logged and dropped rather than
 * retried forever.
 *
 * Unique for a few seconds, so editing five monitors in a row sends one notice
 * rather than five.
 */
class NotifyProbersOfChange implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 10;

    public function uniqueId(): string
    {
        return 'monitoring-change-notice';
    }

    public function handle(): void
    {
        $tenant = (string) config('monitoring.tenant');

        foreach (Monitoring::probers() as $proberId => $prober) {
            $secrets = Monitoring::secretsFor($proberId);

            if ($secrets === [] || empty($prober['base_url'])) {
                continue;
            }

            $this->notify($prober['base_url'], $tenant, $secrets[0]);
        }
    }

    protected function notify(string $baseUrl, string $tenant, string $secret): void
    {
        $timestamp = time();

        // An empty body signs as the timestamp, a dot, and nothing.
        $url = rtrim($baseUrl, '/')."/tenants/{$tenant}/changed";

        try {
            Http::withHeaders([
                'X-Monitoring-Schema' => (string) Monitoring::schemaVersion(),
                'X-Monitoring-Timestamp' => (string) $timestamp,
                'X-Monitoring-Signature' => Signature::header($secret, $timestamp, ''),
                'X-Monitoring-Tenant' => $tenant,
            ])
                /*
                 | Zero bytes, not an empty JSON object. `post($url)` with no
                 | data sends `[]`, which would not match a signature computed
                 | over nothing — and the verifier signs the bytes on the wire,
                 | so the notice would be refused every time.
                 */
                ->withBody('', 'application/json')
                ->timeout(10)
                ->post($url);
        } catch (Throwable $exception) {
            Log::warning('Monitoring change notice failed', [
                'url' => $url,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
