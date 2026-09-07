<?php

namespace Abigah\BotCopTrafficDivision\Events;

use Abigah\BotCopTrafficDivision\Models\Monitor;

class UptimeCheckFailed
{
    public function __construct(public Monitor $monitor) {}
}
