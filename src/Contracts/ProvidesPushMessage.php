<?php

namespace Abigah\BotCopTrafficDivision\Contracts;

use Abigah\BotCopTrafficDivision\Support\PushMessage;

/**
 * A notification that can describe itself for push delivery.
 *
 * A host's push channel asks for this rather than for any one notification
 * class, so news this package has not invented yet can be pushed the same way.
 */
interface ProvidesPushMessage
{
    public function toPush(object $notifiable): PushMessage;
}
