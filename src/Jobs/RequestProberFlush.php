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
 * Ask every prober to deliver what it is holding, now rather than at its slot.
 *
 * Results batch on a slow cadence because each delivery wakes an application
 * that sleeps between requests. That trade stops making sense the moment
 * somebody is looking at the screens: the application is awake already, and the
 * wake a delivery would cost has been paid by whoever opened the page.
 *
 * Nothing is pulled. The notice says only "send what you have", and the prober
 * delivers through the same endpoint, in the same direction, with the same
 * cursor as every scheduled batch — one way for results to arrive, not two.
 *
 * Unique for a minute, which is also the fastest a prober can act on it: its
 * own delivery happens on the next sweep. A dashboard left polling therefore
 * costs one notice a minute at worst, and usually none, because the staleness
 * test that dispatches this stops being true as soon as the batch lands.
 */
class RequestProberFlush implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $uniqueFor = 60;

    public function uniqueId(): string
    {
        return 'monitoring-flush-notice';
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
     * A failure here is a page that stays a few minutes stale, which is what it
     * would have been anyway. Logged and dropped rather than retried: by the
     * time a retry ran, the batch it was asking for would be on its way.
     */
    protected function ask(string $baseUrl, string $tenant, string $secret): void
    {
        $timestamp = time();

        $url = rtrim($baseUrl, '/')."/tenants/{$tenant}/flush";

        try {
            Http::withHeaders([
                'X-Monitoring-Schema' => (string) Monitoring::schemaVersion(),
                'X-Monitoring-Timestamp' => (string) $timestamp,
                'X-Monitoring-Signature' => Signature::header($secret, $timestamp, ''),
                'X-Monitoring-Tenant' => $tenant,
            ])
                /*
                 | Zero bytes, not an empty JSON object, and signed as the
                 | timestamp, a dot, and nothing. `post($url)` with no data
                 | sends `[]`, which would not match — and the prober verifies
                 | the bytes on the wire, so the notice would be refused.
                 */
                ->withBody('', 'application/json')
                ->timeout(10)
                ->post($url);
        } catch (Throwable $exception) {
            Log::warning('Monitoring flush notice failed', [
                'url' => $url,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
