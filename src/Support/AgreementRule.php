<?php

namespace Abigah\BotCopTrafficDivision\Support;

use Abigah\BotCopTrafficDivision\Models\Monitor;
use Abigah\BotCopTrafficDivision\Models\MonitorCheck;

/**
 * A monitor is down when every prober that reported in the window says down.
 *
 * With one prober this degenerates to "the prober says down", which is what
 * this application would have concluded anyway — but the rule is here from the
 * start because retrofitting it means touching every extranet.
 *
 * A failure only one location saw is recorded, with its location tag, and shown
 * as degraded. It does not advance the state machine and so does not open an
 * incident: a route between one data centre and one origin is not the site.
 */
class AgreementRule
{
    /**
     * Whether a failure should be believed, or only recorded.
     *
     * The window is the monitor's own interval: two probers checking the same
     * URL on the same schedule land within one of each other, and anything
     * older than that is describing a different moment rather than disagreeing
     * about this one.
     */
    public function isCorroborated(Monitor $monitor, CheckResult $result): bool
    {
        if ($result->up || $result->proberId === null) {
            return true;
        }

        $window = $monitor->effectiveIntervalMinutes();

        return ! MonitorCheck::query()
            ->where('monitor_id', $monitor->getKey())
            ->where('status', 'up')
            ->whereNotNull('prober_id')
            ->where('prober_id', '!=', $result->proberId)
            ->whereBetween('checked_at', [
                $result->checkedAt->copy()->subMinutes($window),
                $result->checkedAt->copy()->addMinutes($window),
            ])
            ->exists();
    }
}
