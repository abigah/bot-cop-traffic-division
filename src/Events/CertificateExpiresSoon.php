<?php

namespace Abigah\BotCopTrafficDivision\Events;

use Abigah\BotCopTrafficDivision\Models\Monitor;

class CertificateExpiresSoon
{
    public function __construct(public Monitor $monitor) {}
}
