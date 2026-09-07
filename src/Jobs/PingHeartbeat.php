<?php

namespace Abigah\BotCopTrafficDivision\Jobs;

use Abigah\BotCopTrafficDivision\Facades\Monitoring;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tell the probers that a piece of this application's own work has happened.
 *
 * Only deploys use this today. Everything else that pings comes from site code
 * through the client package, and this exists because a deploy is signalled to
 * the extranet — the deploy script only ever talks to one place — while the
 * thing that judges an unfinished deploy lives on the prober.
 *
 * It fans out to every configured prober, because a prober that did not hear
 * about the deploy would judge it by its own timeout and report a deploy that
 * never finished. Fire-and-forget and never throwing: a monitoring ping must
 * never be the reason a deploy fails.
 */
class PingHeartbeat implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public function __construct(
        public string $token,
        public ?string $action = null,
        public ?string $message = null,
    ) {}

    public function handle(): void
    {
        foreach (Monitoring::probers() as $proberId => $prober) {
            if (empty($prober['base_url']) || Monitoring::secretsFor($proberId) === []) {
                continue;
            }

            $this->ping(rtrim($prober['base_url'], '/'));
        }
    }

    protected function ping(string $baseUrl): void
    {
        $url = $baseUrl.'/ping/'.$this->token.($this->action ? '/'.$this->action : '');

        try {
            // Unsigned by design: a ping token grants nothing but the ability
            // to say that this one heartbeat fired.
            Http::timeout(5)->post($url, array_filter(['message' => $this->message]));
        } catch (Throwable $exception) {
            Log::warning('Monitoring heartbeat ping failed', [
                'url' => $url,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
