<?php

namespace Abigah\BotCopTrafficDivision\Livewire;

use Abigah\BotCopTrafficDivision\Services\MonitorQuery;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Drops into a host's layout so an outage is visible from wherever someone
 * happens to be, rather than only from the monitoring screens. Renders nothing
 * at all when everything is up.
 */
class ActiveIncidentBanner extends Component
{
    public function render(): View
    {
        $query = MonitorQuery::forCurrentOwner();

        return view('monitoring::livewire.active-incident-banner', [
            'incidents' => $query->incidents('ongoing')->with('monitor')->limit(5)->get(),
        ]);
    }
}
