<?php

namespace Abigah\BotCopTrafficDivision\Events;

use Abigah\BotCopTrafficDivision\Models\Monitor;

class UptimeCheckRecovered
{
    public function __construct(public Monitor $monitor) {}
}
