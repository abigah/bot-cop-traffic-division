<?php

namespace Abigah\BotCopTrafficDivision\Livewire\Concerns;

use Abigah\BotCopTrafficDivision\Services\MonitorQuery;
use Illuminate\Support\Str;

/**
 * The two things the package knows about a site that the host's own model has
 * no reason to store.
 */
trait ManagesSiteSettings
{
    public ?string $revealedIngestToken = null;

    /**
     * A site that hibernates is only awake when something wakes it, so every
     * check costs a cold start. Saying so floors its monitors at a much longer
     * interval and leans on heartbeats for the gap between them.
     */
    public function toggleHibernates(): void
    {
        $site = MonitorQuery::forCurrentOwner()->findSite($this->siteId);

        if ($site === null) {
            return;
        }

        $settings = $site->monitoringSettings()->firstOrCreate([], [
            'ingest_token' => Str::random(48),
        ]);

        $settings->forceFill(['hibernates' => ! $settings->hibernates])->save();
    }

    public function revealIngestToken(): void
    {
        $site = MonitorQuery::forCurrentOwner()->findSite($this->siteId);

        $this->revealedIngestToken = $site?->ingestToken();
    }

    /**
     * Reports signed with the old token are refused from now until every prober
     * has pulled a fresh manifest. Worth doing when a token leaks; not worth
     * doing idly.
     */
    public function rotateIngestToken(): void
    {
        $site = MonitorQuery::forCurrentOwner()->findSite($this->siteId);

        if ($site === null) {
            return;
        }

        $settings = $site->monitoringSettings()->firstOrCreate([], [
            'ingest_token' => Str::random(48),
        ]);

        $settings->forceFill(['ingest_token' => Str::random(48)])->save();

        $this->revealedIngestToken = $settings->ingest_token;
    }
}
