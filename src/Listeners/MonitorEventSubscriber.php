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
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Stringable;

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
        [$owner, $site] = $this->ownerAndSiteFor($subject);

        if ($owner === null) {
            return;
        }

        // Worked out once for the event, never once per recipient — the moment
        // included, so everyone told about this event is told the same time.
        $occurredAt = CarbonImmutable::now();
        $siteName = $this->siteNameFor($site);
        $incident = $this->incidentFor($subject, $eventType, $site);
        $timeSensitive = $this->isTimeSensitive($subject, $eventType, $incident);

        $muted = in_array($eventType, self::MUTABLE, true)
            ? $this->mutedRecipientKeys($incident)
            : [];

        foreach (Monitoring::recipientsFor($owner, $subject) as $recipient) {
            if (in_array($recipient->getKey(), $muted, true)) {
                continue;
            }

            $channels = Monitoring::channelsFor($recipient, $subject, $eventType);

            if ($channels === []) {
                continue;
            }

            $recipient->notify(new $notificationClass(
                subject: $subject,
                channels: $channels,
                ownerId: $owner->getKey(),
                incidentId: $incident?->getKey(),
                timeSensitive: $timeSensitive,
                occurredAt: $occurredAt,
                siteName: $siteName,
            ));
        }
    }

    /**
     * The outage this news belongs to, if any.
     *
     * A monitor's failure belongs to its own ongoing incident, which is already
     * open even for the very first failure: the event fires once the check is
     * recorded and the outage is on record. A heartbeat or an exception belongs
     * to the site's outage. A monitor's recovery fires before its incident is
     * resolved, so it too belongs to the ongoing incident: the one ending now.
     * With none open there is no outage ending, and the recovery belongs to no
     * incident — never to one that ended long ago. Expiry warnings and a
     * heartbeat's recovery are not about an outage at all.
     *
     * $site is the subject's site when it has already been read, so finding
     * the site's outage does not read it again.
     */
    protected function incidentFor(Model $subject, string $eventType, ?Model $site = null): ?MonitorIncident
    {
        return match ($eventType) {
            'uptime_failed', 'certificate_failed', 'uptime_recovered' => $subject instanceof Monitor
                ? $subject->incidents()->ongoing()->latest('started_at')->first()
                : null,
            'heartbeat_missing', 'exception_reported' => $this->siteIncidentFor($subject, $site),
            default => null,
        };
    }

    /**
     * Whether this news should break through a recipient's focus.
     *
     * Only a failure that means the site is down, and "the site is down" means a
     * critical monitor is down: a critical monitor's own failure, or a missed
     * job or new error while the site's outage is open — which is only ever an
     * incident on a critical monitor. Warnings and recoveries never are.
     */
    protected function isTimeSensitive(Model $subject, string $eventType, ?MonitorIncident $incident): bool
    {
        return match ($eventType) {
            'uptime_failed', 'certificate_failed' => $subject instanceof Monitor && (bool) $subject->critical,
            'heartbeat_missing', 'exception_reported' => $incident !== null,
            default => false,
        };
    }

    /**
     * Who to tell, and the site the news is about.
     *
     * Everything monitored belongs to a site and every site belongs to an
     * owner, so the question is always the same one — but a monitor carries a
     * denormalised owner_id that answers it without a lookup, and an install
     * mid-backfill may have monitors with no site yet.
     *
     * The site comes back too, when there is one: it has already been read to
     * find the owner, and its name is what a push says the news concerns.
     *
     * @return array{mixed, Model|null}
     */
    protected function ownerAndSiteFor(Model $subject): array
    {
        $siteId = $subject->site_id ?? null;

        if ($siteId !== null) {
            $site = Monitoring::siteModel()::find($siteId);

            if ($site !== null) {
                return [Monitoring::ownerForSite($site), $site];
            }
        }

        if ($subject instanceof Monitor && $subject->owner_id !== null) {
            return [Monitoring::ownerModel()::find($subject->owner_id), null];
        }

        return [null, null];
    }

    /**
     * The name a push gives the site: its `name`, the way the package names a
     * site everywhere, when it has one.
     *
     * A host's site model need not have a name. With none, the push says what
     * happened without a site rather than "Site 12", which tells someone
     * reading a lock screen nothing they can place.
     */
    protected function siteNameFor(?Model $site): ?string
    {
        $name = $site?->name ?? null;

        return (is_string($name) || $name instanceof Stringable) && filled($name) ? (string) $name : null;
    }

    /**
     * Recipients who have silenced the outage this news belongs to.
     *
     * For a monitor that is its own ongoing incident. For a heartbeat or an
     * exception it is the site's — the earliest ongoing incident among its
     * critical monitors — because a job that stopped and a 500 storm during an
     * outage are the same outage, told twice.
     *
     * Only live mutes count: one with no expiry, which lasts until recovery, or
     * one whose expiry is still ahead. An expired row is read as over the moment
     * it expires, so nothing here waits on a cleanup to have run.
     *
     * The incident is the one incidentFor() found for this news, so a mute and
     * a push always agree about which outage they mean.
     *
     * @return array<int, mixed>
     */
    protected function mutedRecipientKeys(?MonitorIncident $incident): array
    {
        if ($incident === null) {
            return [];
        }

        $now = now();

        return $incident->mutedBy()
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('monitor_incident_notification_mutes.muted_until')
                    ->orWhere('monitor_incident_notification_mutes.muted_until', '>', $now);
            })
            ->pluck($incident->mutedBy()->getRelated()->getQualifiedKeyName())
            ->all();
    }

    /**
     * The site's outage a heartbeat or an exception belongs to.
     *
     * This asks the host's site model for its openIncident(), which comes from
     * the IsMonitoredSite concern. A heartbeat's or an exception's incident, the
     * mute that covers it and whether it is Time Sensitive all rest on that
     * answer, so a host site model that overrides it changes all three.
     *
     * $site is the subject's site when it has already been read; only without
     * one is it looked up.
     */
    protected function siteIncidentFor(Model $subject, ?Model $site = null): ?MonitorIncident
    {
        if (! $subject instanceof MonitorHeartbeat && ! $subject instanceof MonitorSiteException) {
            return null;
        }

        $site ??= Monitoring::siteModel()::find($subject->site_id);

        return $site?->openIncident();
    }
}
