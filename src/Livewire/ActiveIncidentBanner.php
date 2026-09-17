<?php

namespace Abigah\BotCopTrafficDivision\Livewire;

use Abigah\BotCopTrafficDivision\Services\MonitorQuery;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Drops into a host's layout so an outage is visible from wherever someone
 * happens to be, rather than only from the monitoring screens. Renders nothing
 * at all when everything is up.
 */
class ActiveIncidentBanner extends Component
{
    /**
     * Draw again because the incidents behind the banner have changed.
     *
     * The banner sits in the layout rather than on the screen somebody is
     * using, so nothing else on the page re-renders it. Without this it goes on
     * naming a monitor that has just been deleted until the page is reloaded.
     */
    #[On('monitoring-incidents-changed')]
    public function incidentsChanged(): void {}

    public function render(): View
    {
        $query = MonitorQuery::forCurrentOwner();

        return view('monitoring::livewire.active-incident-banner', [
            'incidents' => $query->incidents('ongoing')->with('monitor')->limit(5)->get(),
        ]);
    }
}
