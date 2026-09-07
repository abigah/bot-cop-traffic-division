<?php

namespace Abigah\BotCopTrafficDivision\Listeners;

use Abigah\BotCopTrafficDivision\Events\CertificateCheckFailed;
use Abigah\BotCopTrafficDivision\Events\CertificateExpiresSoon;
use Abigah\BotCopTrafficDivision\Events\DomainExpiresSoon;
use Abigah\BotCopTrafficDivision\Events\HeartbeatMissed;
use Abigah\BotCopTrafficDivision\Events\HeartbeatRecovered;
use Abigah\BotCopTrafficDivision\Events\SiteExceptionReported;
use Abigah\BotCopTrafficDivision\Events\UptimeCheckFailed;
use Abigah\BotCopTrafficDivision\Events\UptimeCheckRecovered;
use Abigah\BotCopTrafficDivision\Facades\Monitoring;
use Abigah\BotCopTrafficDivision\Models\Monitor;
use Abigah\BotCopTrafficDivision\Models\MonitorHeartbeat;
use Abigah\BotCopTrafficDivision\Models\MonitorIncident;
use Abigah\BotCopTrafficDivision\Models\MonitorSiteException;
use Abigah\BotCopTrafficDivision\Notifications\CertificateCheckFailedNotification;
use Abigah\BotCopTrafficDivision\Notifications\CertificateExpiresSoonNotification;
use Abigah\BotCopTrafficDivision\Notifications\DomainExpiresSoonNotification;
use Abigah\BotCopTrafficDivision\Notifications\HeartbeatMissedNotification;
use Abigah\BotCopTrafficDivision\Notifications\HeartbeatRecoveredNotification;
use Abigah\BotCopTrafficDivision\Notifications\MonitoringNotification;
use Abigah\BotCopTrafficDivision\Notifications\SiteExceptionReportedNotification;
use Abigah\BotCopTrafficDivision\Notifications\UptimeCheckFailedNotification;
use Abigah\BotCopTrafficDivision\Notifications\UptimeCheckRecoveredNotification;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;

/**
 * The one road every notification takes.
 *
 * An outage, a job that stopped running and a new kind of server error are
 * different news about the same site, and they all come through here — which is
 * what makes one mute cover all three, and what stops the next kind of news
 * needing its own preferences, its own mute and its own way of finding
 * recipients.
 */
class MonitorEventSubscriber
{
    /**
     * Event class, the preference column that governs it, and what to send.
     *
     * @var array<class-string, array{string, class-string<MonitoringNotification>}>
     */
    private const EVENT_MAP = [
        UptimeCheckFailed::class => ['uptime_failed', UptimeCheckFailedNotification::class],
        UptimeCheckRecovered::class => ['uptime_recovered', UptimeCheckRecoveredNotification::class],
        CertificateCheckFailed::class => ['certificate_failed', CertificateCheckFailedNotification::class],
        CertificateExpiresSoon::class => ['certificate_expires_soon', CertificateExpiresSoonNotification::class],
        DomainExpiresSoon::class => ['domain_expires_soon', DomainExpiresSoonNotification::class],
        HeartbeatMissed::class => ['heartbeat_missing', HeartbeatMissedNotification::class],
        HeartbeatRecovered::class => ['heartbeat_recovered', HeartbeatRecoveredNotification::class],
        SiteExceptionReported::class => ['exception_reported', SiteExceptionReportedNotification::class],
    ];

    /**
     * Events that a mute silences.
     *
     * A mute says "I know, stop telling me about this outage" — so it covers
     * the bad news and never the good. Silencing a recovery would leave someone
     * believing a site is still down long after it came back, which is the one
     * thing worse than being told twice.
     *
     * @var array<int, string>
     */
    private const MUTABLE = [
        'uptime_failed',
        'certificate_failed',
        'heartbeat_missing',
        'exception_reported',
    ];

    public function subscribe(Dispatcher $events): void
    {
        foreach (self::EVENT_MAP as $eventClass => [$eventType, $notificationClass]) {
            $events->listen($eventClass, function (object $event) use ($eventType, $notificationClass): void {
                $this->notify($this->subjectOf($event), $eventType, $notificationClass);
            });
        }
    }

    protected function subjectOf(object $event): Model
    {
        return match (true) {
            property_exists($event, 'monitor') => $event->monitor,
            property_exists($event, 'heartbeat') => $event->heartbeat,
            property_exists($event, 'exception') => $event->exception,
        };
    }

    /**
     * @param  class-string<MonitoringNotification>  $notificationClass
     */
    protected function notify(Model $subject, string $eventType, string $notificationClass): void
    {
        $owner = $this->ownerFor($subject);

        if ($owner === null) {
            return;
        }

        $muted = in_array($eventType, self::MUTABLE, true)
            ? $this->mutedRecipientKeys($subject)
            : [];

        foreach (Monitoring::recipientsFor($owner, $subject) as $recipient) {
            if (in_array($recipient->getKey(), $muted, true)) {
                continue;
            }

            $channels = Monitoring::channelsFor($recipient, $subject, $eventType);

            if ($channels === []) {
                continue;
            }

            $recipient->notify(new $notificationClass($subject, $channels));
        }
    }

    /**
     * Who to tell.
     *
     * Everything monitored belongs to a site and every site belongs to an
     * owner, so the question is always the same one — but a monitor carries a
     * denormalised owner_id that answers it without a lookup, and an install
     * mid-backfill may have monitors with no site yet.
     */
    protected function ownerFor(Model $subject): mixed
    {
        $siteId = $subject->site_id ?? null;

        if ($siteId !== null) {
            $site = Monitoring::siteModel()::find($siteId);

            if ($site !== null) {
                return Monitoring::ownerForSite($site);
            }
        }

        if ($subject instanceof Monitor && $subject->owner_id !== null) {
            return Monitoring::ownerModel()::find($subject->owner_id);
        }

        return null;
    }

    /**
     * Recipients who have silenced the outage this news belongs to.
     *
     * For a monitor that is its own ongoing incident. For a heartbeat or an
     * exception it is the site's — the earliest ongoing incident among its
     * critical monitors — because a job that stopped and a 500 storm during an
     * outage are the same outage, told twice.
     *
     * @return array<int, mixed>
     */
    protected function mutedRecipientKeys(Model $subject): array
    {
        $incident = $subject instanceof Monitor
            ? $subject->incidents()->ongoing()->latest('started_at')->first()
            : $this->siteIncidentFor($subject);

        if ($incident === null) {
            return [];
        }

        return $incident->mutedBy()->pluck($incident->mutedBy()->getRelated()->getQualifiedKeyName())->all();
    }

    protected function siteIncidentFor(Model $subject): ?MonitorIncident
    {
        if (! $subject instanceof MonitorHeartbeat && ! $subject instanceof MonitorSiteException) {
            return null;
        }

        $site = Monitoring::siteModel()::find($subject->site_id);

        return $site?->openIncident();
    }
}
