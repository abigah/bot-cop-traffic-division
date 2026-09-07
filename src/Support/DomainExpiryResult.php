<?php

namespace Abigah\BotCopTrafficDivision\Support;

use Abigah\BotCopTrafficDivision\Enums\DomainExpiryStatus;
use Carbon\Carbon;

class DomainExpiryResult
{
    public function __construct(
        public DomainExpiryStatus $status,
        public ?Carbon $expirationDate = null,
        public ?string $registrar = null,
        public ?string $failureReason = null,
    ) {}
}
