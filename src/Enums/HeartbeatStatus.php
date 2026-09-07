<?php

namespace Abigah\BotCopTrafficDivision\Enums;

enum HeartbeatStatus: string
{
    case PENDING = 'pending';
    case OK = 'ok';
    case RUNNING = 'running';
    case MISSING = 'missing';
    case FAILED = 'failed';
    case TIMED_OUT = 'timed_out';

    /** Whether this status is one a verdict can report to a recipient. */
    public function isAlerting(): bool
    {
        return in_array($this, [self::MISSING, self::FAILED, self::TIMED_OUT], true);
    }
}
