<?php

namespace Abigah\BotCopTrafficDivision\Concerns;

use Abigah\BotCopTrafficDivision\Services\MonitorQuery;
use Illuminate\Support\Facades\Auth;

trait DismissesIncidents
{
    public bool $showDismissModal = false;

    public ?int $dismissingIncidentId = null;

    public string $dismissalReason = '';

    public string $dismissalNote = '';

    public function openDismissModal(int $incidentId): void
    {
        $this->dismissingIncidentId = $incidentId;
        $this->dismissalReason = '';
        $this->dismissalNote = '';
        $this->showDismissModal = true;
    }

    public function dismissIncident(): void
    {
        $this->validate([
            'dismissalReason' => 'required|in:deployment,testing,third_party,other',
            'dismissalNote' => 'nullable|string|max:1000',
        ]);

        // The id came from the browser, so it is only ever honoured when it
        // names an incident on one of this owner's monitors.
        $incident = MonitorQuery::forCurrentOwner()->findIncident($this->dismissingIncidentId);

        if (! $incident) {
            $this->showDismissModal = false;
            $this->dismissingIncidentId = null;

            return;
        }

        $incident->update([
            'dismissal_reason' => $this->dismissalReason,
            'dismissal_note' => $this->dismissalNote ?: null,
            'dismissed_by' => Auth::id(),
            'archived_at' => now(),
            'archived_by' => Auth::id(),
        ]);

        $this->showDismissModal = false;
        $this->dismissingIncidentId = null;
    }
}
