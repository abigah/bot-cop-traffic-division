<?php

use Abigah\BotCopTrafficDivision\Facades\Monitoring;
use Abigah\BotCopTrafficDivision\Jobs\CheckCertificateJob;
use Abigah\BotCopTrafficDivision\Jobs\CheckUptimeJob;
use Illuminate\Console\Scheduling\Schedule;

function scheduledJobs(): array
{
    Monitoring::schedule($schedule = app(Schedule::class));

    return collect($schedule->events())
        ->map(fn ($event) => $event->description ?? $event->getSummaryForDisplay())
        ->all();
}

it('schedules local uptime checking by default', function () {
    config()->set('monitoring.checker', 'local');

    expect(scheduledJobs())->toContain(CheckUptimeJob::class);
});

/**
 * The one thing `remote` mode changes on the scheduler. Certificate and
 * domain-expiry checking never left this side, so they stay registered — on a
 * hibernating extranet, the scheduler waking the app for a daily certificate
 * check is exactly what is wanted.
 */
it('drops the uptime job in remote mode and keeps everything else', function () {
    config()->set('monitoring.checker', 'remote');

    $scheduled = scheduledJobs();

    expect($scheduled)->not->toContain(CheckUptimeJob::class)
        ->and($scheduled)->toContain(CheckCertificateJob::class);
});

it('knows which mode it is in', function () {
    config()->set('monitoring.checker', 'remote');
    expect(Monitoring::checksRemotely())->toBeTrue();

    config()->set('monitoring.checker', 'local');
    expect(Monitoring::checksRemotely())->toBeFalse();
});
