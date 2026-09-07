<?php

namespace Abigah\BotCopTrafficDivision\Enums;

/**
 * Periodic work versus bounded work.
 *
 * A HEARTBEAT is due again on an interval: it is overdue when nothing has
 * pinged within `interval + grace`. An EVENT has a beginning and an end: it has
 * timed out when a `start` has no `finish` inside `timeout`, and can also fail
 * explicitly.
 */
enum HeartbeatKind: string
{
    case HEARTBEAT = 'heartbeat';
    case EVENT = 'event';
}
