<?php

namespace Abigah\BotCopTrafficDivision\Http\Controllers;

use Abigah\BotCopTrafficDivision\Models\MonitorIncident;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * The "stop telling me about this" link in a notification.
 *
 * Signed and per recipient, so it needs no login — someone woken at 3am should
 * not have to authenticate to make the phone stop. It silences one outage on
 * every channel, and only that outage: it expires with the incident, so nobody
 * accidentally mutes themselves forever.
 */
class MuteIncidentController
{
    public function __invoke(Request $request, MonitorIncident $incident, string $notifiable): View
    {
        $recipient = config('monitoring.notifiable_model')::findOrFail($notifiable);

        $undo = $request->boolean('undo');

        if ($undo) {
            $incident->mutedBy()->detach($recipient->getKey());
        } else {
            $incident->mutedBy()->syncWithoutDetaching([$recipient->getKey()]);
        }

        return view('monitoring::incidents.muted', [
            'incident' => $incident->load('monitor'),
            'recipient' => $recipient,
            'undone' => $undo,
            'toggleUrl' => URL::signedRoute('monitoring.incident.mute', [
                'incident' => $incident->getKey(),
                'notifiable' => $recipient->getKey(),
                'undo' => $undo ? null : 1,
            ]),
        ]);
    }
}
