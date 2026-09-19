<?php

namespace Abigah\BotCopTrafficDivision\Livewire;

use Abigah\BotCopTrafficDivision\Facades\Monitoring;
use Abigah\BotCopTrafficDivision\Services\MonitorQuery;
use Abigah\BotCopTrafficDivision\Support\Period;
use Illuminate\View\View;
use Livewire\Component;

/**
 * The one screen to leave open on a second monitor.
 *
 * It answers three questions and stops: is anything down, has anything stopped
 * running, and is anything throwing errors it did not throw yesterday.
 *
 * It asks them across every owner the host names for the dashboard, not only
 * the current one, so someone on several sees everything at once. Each row
 * then says whose it is, and links through the host to open it as that owner.
 */
class Dashboard extends Component
{
    public string $period = '24h';

    public function mount(): void
    {
        // Somebody is looking, so this application is awake and the wake a
        // delivery costs has been paid. Ask the probers for what they have been
        // holding rather than showing checks that stop an hour ago.
        Monitoring::requestFlushIfStale();
    }

    public function render(): View
    {
        $owners = Monitoring::dashboardOwners()->keyBy(fn ($owner) => $owner->getKey());
        $query = MonitorQuery::forOwners($owners->keys());
        $period = Period::fromValue($this->period);
        $user = auth()->user();

        $siteOwners = $query->sitesByOwner()
            ->flatMap(fn ($sites, $ownerKey) => $sites->map(fn ($site) => [$site->getKey(), $ownerKey]))
            ->mapWithKeys(fn (array $siteOwner) => [$siteOwner[0] => $siteOwner[1]]);

        return view('monitoring::livewire.dashboard', [
            'summary' => $query->summary($period),
            'stats' => $query->stats($period),
            'chart' => $query->chartData($period, Monitoring::timezoneFor($user), Monitoring::uses24HourTimeFor($user)),
            'ongoing' => $query->incidents('ongoing')->with('monitor')->limit(10)->get(),
            'missedJobs' => $query->heartbeats()
                ->where('enabled', true)
                ->whereIn('status', ['missing', 'failed', 'timed_out'])
                ->get(),
            'newErrors' => $query->siteExceptions()
                ->unresolved()
                ->where('last_seen_at', '>=', now()->subDay())
                ->limit(10)
                ->get(),
            'showsOwners' => $owners->count() > 1,
            'ownerOfSite' => fn ($siteId) => $owners->get($siteOwners->get($siteId)),
            'ownerOfMonitor' => fn ($monitor) => $owners->get($monitor?->owner_id),
            'urlOnOwner' => fn ($owner, string $url): string => Monitoring::urlOnOwner($owner, $url),
        ]);
    }
}
