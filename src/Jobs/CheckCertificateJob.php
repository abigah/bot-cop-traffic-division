<?php

namespace Abigah\BotCopTrafficDivision\Jobs;

use Abigah\BotCopTrafficDivision\Models\Monitor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CheckCertificateJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public ?string $url = null) {}

    public function handle(): void
    {
        $monitors = $this->url
            ? Monitor::query()->where('url', $this->url)->get()
            : Monitor::enabled()->where('certificate_check_enabled', true)->get();

        $monitors->each(fn (Monitor $monitor) => $monitor->checkCertificate());
    }
}
