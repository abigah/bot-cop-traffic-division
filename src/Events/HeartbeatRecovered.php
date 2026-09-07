<?php

namespace Abigah\BotCopTrafficDivision\Events;

use Abigah\BotCopTrafficDivision\Models\MonitorHeartbeat;

class HeartbeatRecovered
{
    public function __construct(public MonitorHeartbeat $heartbeat) {}
}
