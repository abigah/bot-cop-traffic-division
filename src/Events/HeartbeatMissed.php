<?php

namespace Abigah\BotCopTrafficDivision\Events;

use Abigah\BotCopTrafficDivision\Enums\HeartbeatStatus;
use Abigah\BotCopTrafficDivision\Models\MonitorHeartbeat;

/**
 * Work that should have happened and did not: a job that never ran, a deploy
 * that never reported finishing, or one that reported failing.
 *
 * A verdict only reaches here when the site was up at the time. A job that
 * stopped because the whole site was down is part of that outage, and the
 * prober folds it into the incident rather than paging separately.
 */
class HeartbeatMissed
{
    public function __construct(
        public MonitorHeartbeat $heartbeat,
        public HeartbeatStatus $status,
        public ?string $message = null,
    ) {}
}
