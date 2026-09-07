<?php

namespace Abigah\BotCopTrafficDivision\Livewire;

use Abigah\BotCopTrafficDivision\Facades\Monitoring;
use Abigah\BotCopTrafficDivision\Livewire\Concerns\ManagesHeartbeats;
use Abigah\BotCopTrafficDivision\Livewire\Concerns\ManagesMonitors;
use Abigah\BotCopTrafficDivision\Livewire\Concerns\ManagesSiteSettings;
use Abigah\BotCopTrafficDivision\Models\MonitorSiteException;
use Abigah\BotCopTrafficDivision\Services\MonitorQuery;
use Abigah\BotCopTrafficDivision\Support\Period;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\View\View;
use Livewire\Component;

/**
 * One site, and everything that is true about it.
 *
 * The three signals sit on one page on purpose. "The homepage is up, the
 * nightly digest has not run since Tuesday, and there have been four hundred
 * database errors today" is one story about one site, and reading it as three
 * unrelated screens is how the middle sentence goes unnoticed for a week.
 */
class SiteOverview extends Component
{
    use ManagesHeartbeats;
    use ManagesMonitors;
    use ManagesSiteSettings;

    /**
     * The id, kept separate from the model the view receives. A public property
     * named `site` would shadow it — Livewire merges its own state into the
     * view data, and the id would win.
     */
    public int|string $siteId;

    public string $period = '24h';

    public function mount(int|string $site): void
    {
        // Through the scoped query, so an id from the URL cannot address
        // another owner's site by guessing.
        if (MonitorQuery::forCurrentOwner()->findSite($site) === null) {
            throw (new ModelNotFoundException)->setModel(config('monitoring.site_model'), [$site]);
        }

        $this->siteId = $site;
    }

    public function resolveException(int $exception): void
    {
        $query = MonitorQuery::forCurrentOwner();

        $error = $query->siteExceptions()->whereKey($exception)->first();

        // Resolving is what makes a recurrence after a fix count as new again,
        // rather than disappearing into a count that has been climbing for a
        // month.
        $error?->resolve();
    }

    public function render(): View
    {
        $query = MonitorQuery::forCurrentOwner();
        $site = $query->findSite($this->siteId);
        $period = Period::fromValue($this->period);

        $monitors = $site->monitors()->orderByDesc('critical')->orderBy('url')->get();

        return view('monitoring::livewire.site-overview', [
            'site' => $site,
            'monitors' => $monitors,
            'heartbeats' => $site->heartbeats()->orderBy('name')->get(),
            'exceptions' => $site->siteExceptions()->unresolved()->orderByDesc('last_seen_at')->limit(20)->get(),
            'incidents' => $query->incidents()->whereIn('monitor_id', $monitors->modelKeys())->limit(10)->get(),
            'deployment' => $site->deployments()->ongoing()->latest('started_at')->first(),
            'stats' => $query->stats($period, includeIncidents: true, monitorIds: $monitors->modelKeys()),
            'chart' => $query->chartData($period, $this->timezone(), monitorIds: $monitors->modelKeys()),
            'suppressedHeartbeats' => $site->heartbeats()->whereNotNull('suppressed_since')->count(),
            'resolvedErrors' => MonitorSiteException::query()
                ->where('site_id', $site->getKey())
                ->whereNotNull('resolved_at')
                ->count(),
        ]);
    }

    protected function timezone(): string
    {
        return Monitoring::timezoneFor(auth()->user());
    }
}
