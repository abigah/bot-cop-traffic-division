<?php

namespace Abigah\BotCopTrafficDivision\Events;

use Abigah\BotCopTrafficDivision\Models\MonitorSiteException;

/**
 * A fingerprint of server error nobody has seen before — or has not seen since
 * it was resolved. Only new fingerprints raise this; one that keeps firing gets
 * a digest on the delivery cadence, never a page per occurrence.
 */
class SiteExceptionReported
{
    public function __construct(public MonitorSiteException $exception) {}
}
