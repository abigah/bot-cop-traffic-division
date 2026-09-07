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
 */
class Dashboard extends Component
{
    public string $period = '24h';

    public function render(): View
    {
        $query = MonitorQuery::forCurrentOwner();
        $period = Period::fromValue($this->period);
        $user = auth()->user();

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
        ]);
    }
}
