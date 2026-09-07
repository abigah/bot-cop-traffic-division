<?php

namespace Abigah\BotCopTrafficDivision\Livewire;

use Abigah\BotCopTrafficDivision\Facades\Monitoring;
use Abigah\BotCopTrafficDivision\Services\MonitorQuery;
use Abigah\BotCopTrafficDivision\Support\Period;
use Illuminate\View\View;
use Livewire\Component;

/**
 * One monitor, in detail: what it has been doing and what it is doing now.
 *
 * This is the screen someone opens after a page at 3am, so it leads with the
 * things that answer "what is actually wrong" — the failure reason, the recent
 * checks, whether a deploy was underway — and leaves the certificate and domain
 * panels further down where they belong.
 */
class MonitorHistory extends Component
{
    /** The id. The view receives the model under `monitor`; see SiteOverview. */
    public int|string $monitorId;

    public string $period = '24h';

    public bool $rawChecks = false;

    public function mount(int|string $monitor): void
    {
        // Scoped: an id from the URL cannot reach another owner's monitor.
        $this->monitorId = MonitorQuery::forCurrentOwner()->findMonitorOrFail($monitor)->getKey();
    }

    public function render(): View
    {
        $query = MonitorQuery::forCurrentOwner();
        $monitor = $query->findMonitorOrFail($this->monitorId);
        $period = Period::fromValue($this->period);
        $user = auth()->user();

        return view('monitoring::livewire.monitor-history', [
            'monitor' => $monitor,
            'site' => $monitor->site,
            'checks' => $query->checks($monitor)->limit(50)->get(),
            'incidents' => $query->incidents()->where('monitor_id', $monitor->getKey())->limit(10)->get(),
            'ongoingIncident' => $query->ongoingIncidentFor($monitor),
            'stats' => $query->stats($period, includeIncidents: true, monitorIds: [$monitor->getKey()]),
            'chart' => $query->chartData(
                $period,
                Monitoring::timezoneFor($user),
                Monitoring::uses24HourTimeFor($user),
                $this->rawChecks,
                [$monitor->getKey()],
            ),
            'gaps' => $monitor->checkGaps()->orderByDesc('oldest_at')->limit(5)->get(),
            'deploying' => $monitor->isDeploying(),
        ]);
    }
}
