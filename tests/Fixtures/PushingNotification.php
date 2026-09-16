<?php

namespace Abigah\BotCopTrafficDivision\Tests\Fixtures;

use Abigah\BotCopTrafficDivision\Notifications\MonitoringNotification;
use Abigah\BotCopTrafficDivision\Support\PushMessage;

/**
 * Describes its push through the base helper exactly the way a notification's
 * own toPush() will.
 *
 * Named rather than anonymous so it can go through the queue's serialisation —
 * which is where a push is really described, in a worker, some time after the
 * event.
 */
class PushingNotification extends MonitoringNotification
{
    public function toPush(object $notifiable): PushMessage
    {
        return $this->buildPushMessage('uptime_failed', 'Site is down', 'site.test failed its uptime check.');
    }
}
