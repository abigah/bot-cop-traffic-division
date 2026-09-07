<?php

namespace Abigah\BotCopTrafficDivision\Observers;

use Abigah\BotCopTrafficDivision\Facades\Monitoring;
use Abigah\BotCopTrafficDivision\Jobs\NotifyProbersOfChange;
use Illuminate\Database\Eloquent\Model;

/**
 * Watches the parts of a monitor or heartbeat that appear in the manifest, and
 * pokes the probers when one of them changes.
 *
 * Only those parts: a monitor's status, its last check date and its failure
 * count change constantly and mean nothing to a prober, which is told what to
 * check rather than what was found. Sending a notice on every check would wake
 * the prober once a minute to be told nothing.
 */
class DeclarationObserver
{
    /** @var array<int, string> */
    protected array $watched = [
        'url',
        'uptime_check_method',
        'uptime_check_payload',
        'uptime_check_additional_headers',
        'look_for_string',
        'fail_for_string',
        'uptime_check_interval_in_minutes',
        'critical',
        'uptime_check_enabled',
        'site_id',
        'name',
        'kind',
        'token',
        'enabled',
        'interval_minutes',
        'grace_minutes',
        'timeout_minutes',
        'hibernates',
        'ingest_token',
    ];

    public function created(Model $model): void
    {
        $this->notify();
    }

    public function updated(Model $model): void
    {
        if (array_intersect(array_keys($model->getChanges()), $this->watched) === []) {
            return;
        }

        $this->notify();
    }

    public function deleted(Model $model): void
    {
        $this->notify();
    }

    protected function notify(): void
    {
        if (! Monitoring::checksRemotely()) {
            return;
        }

        NotifyProbersOfChange::dispatch();
    }
}
