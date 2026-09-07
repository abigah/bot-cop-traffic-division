<?php

namespace Abigah\BotCopTrafficDivision\Support;

class DnsLookupResult
{
    /**
     * @param  array<int, array{type: string, name: string, value: string, ttl: int}>  $records
     */
    public function __construct(
        public bool $success,
        public array $records = [],
        public ?string $failureReason = null,
    ) {}
}
