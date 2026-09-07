<?php

namespace Abigah\BotCopTrafficDivision\Jobs;

use Abigah\BotCopTrafficDivision\Models\Monitor;
use Abigah\BotCopTrafficDivision\Services\MonitorChecker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Local uptime checking. Never scheduled in `remote` mode — a prober is doing
 * this from outside — but still dispatchable by hand, which is what makes
 * running both modes side by side and comparing timelines possible.
 */
class CheckUptimeJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public ?string $url = null) {}

    public function handle(MonitorChecker $checker): void
    {
        $monitors = $this->url
            ? Monitor::query()->where('url', $this->url)->get()
            : Monitor::enabled()->get()->filter->shouldCheckUptime();

        $checker->check($monitors);
    }
}
