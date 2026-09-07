<?php

namespace Abigah\BotCopTrafficDivision\Events;

use Abigah\BotCopTrafficDivision\Models\Monitor;

class DomainExpiresSoon
{
    public function __construct(public Monitor $monitor) {}
}
