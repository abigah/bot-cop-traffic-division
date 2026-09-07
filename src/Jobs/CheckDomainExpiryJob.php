<?php

namespace Abigah\BotCopTrafficDivision\Jobs;

use Abigah\BotCopTrafficDivision\Models\Monitor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CheckDomainExpiryJob implements ShouldQueue
{
    use Queueable;

    /** Seconds before the queue worker kills this job. */
    public int $timeout = 30;

    /** Seconds between each WHOIS lookup, to stay under registrar rate limits. */
    private const STAGGER_SECONDS = 60;

    public function __construct(
        public ?string $url = null,
        public bool $expiringSoonOnly = false,
    ) {}

    public function handle(): void
    {
        if ($this->url) {
            Monitor::query()
                ->where('url', $this->url)
                ->get()
                ->each(fn (Monitor $monitor) => $monitor->checkDomainExpiry());

            return;
        }

        $query = Monitor::query()->where('domain_expiry_check_enabled', true);

        if ($this->expiringSoonOnly) {
            $query->whereNotNull('domain_expiration_date')
                ->where('domain_expiration_date', '<=', now()->addDays(30));
        }

        $query->get()->each(fn (Monitor $monitor, int $index) => self::dispatch((string) $monitor->url)
            ->delay(now()->addSeconds($index * self::STAGGER_SECONDS))
        );
    }
}
