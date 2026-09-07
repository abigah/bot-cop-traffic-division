<?php

namespace Abigah\BotCopTrafficDivision\Events;

use Abigah\BotCopTrafficDivision\Models\Monitor;

class CertificateCheckFailed
{
    public function __construct(public Monitor $monitor, public string $reason = '') {}
}
