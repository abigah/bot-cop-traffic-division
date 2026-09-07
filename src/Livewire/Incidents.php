<?php

namespace Abigah\BotCopTrafficDivision\Livewire;

use Abigah\BotCopTrafficDivision\Concerns\DismissesIncidents;
use Abigah\BotCopTrafficDivision\Services\MonitorQuery;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Outages, past and present.
 *
 * Dismissing one is not the same as it being resolved: an incident is resolved
 * when the site comes back, and dismissed when a person decides it was a deploy
 * or a test and not worth keeping in the record. Both are kept; only the second
 * needs a reason.
 */
class Incidents extends Component
{
    use DismissesIncidents;
    use WithPagination;

    /** ongoing, resolved, archived, or all live ones. */
    public string $filter = 'ongoing';

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $query = MonitorQuery::forCurrentOwner();

        return view('monitoring::livewire.incidents', [
            'incidents' => $query->incidents($this->filter === 'all' ? null : $this->filter)
                ->with('monitor')
                ->paginate(20),
            'counts' => [
                'ongoing' => $query->incidents('ongoing')->count(),
                'resolved' => $query->incidents('resolved')->count(),
                'archived' => $query->incidents('archived')->count(),
            ],
        ]);
    }
}
