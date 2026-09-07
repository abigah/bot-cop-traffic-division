<?php

namespace Abigah\BotCopTrafficDivision\Livewire;

use Abigah\BotCopTrafficDivision\Models\MonitorNotificationPreference;
use Abigah\BotCopTrafficDivision\Services\MonitorQuery;
use Illuminate\Database\Eloquent\Model;
use Illuminate\View\View;
use Livewire\Component;

/**
 * What one person wants to hear about, and how.
 *
 * Preferences are per recipient and per subject, and the subject is a site or
 * one monitor on it. A row on a site covers everything under it; a row on a
 * monitor is someone saying "this one is different", and it wins outright
 * rather than merging — merging would take back the exception they just made.
 *
 * Nobody is required to set any of this. With no row at all a recipient gets
 * mail and a database notification, which is the right default for a system
 * whose whole job is telling people things.
 */
class NotificationPreferences extends Component
{
    /** The site whose preferences are open, or null for the list. */
    public int|string|null $openSiteId = null;

    /** @var array<int, string> */
    public const EVENTS = [
        'uptime_failed' => 'A monitor goes down',
        'uptime_recovered' => 'It comes back',
        'heartbeat_missing' => 'Scheduled work stops running',
        'heartbeat_recovered' => 'It starts again',
        'exception_reported' => 'A new kind of server error',
        'certificate_failed' => 'A certificate problem',
        'certificate_expires_soon' => 'A certificate expiring',
        'domain_expires_soon' => 'A domain expiring',
    ];

    public function toggleSite(int|string $site): void
    {
        $this->openSiteId = (string) $this->openSiteId === (string) $site ? null : $site;
    }

    /** Turn one switch on or off, creating the row the first time. */
    public function toggle(int|string $site, ?int $monitor, string $field): void
    {
        if (! $this->isAllowedField($field)) {
            return;
        }

        $preference = $this->preferenceRow($site, $monitor);

        if ($preference === null) {
            return;
        }

        $preference->forceFill([$field => ! $preference->{$field}])->save();
    }

    /**
     * Drop a monitor's own row so it goes back to following its site. The
     * absence of a row is meaningful here, so this is a delete rather than a
     * reset to defaults.
     */
    public function followSite(int $monitor): void
    {
        MonitorQuery::forCurrentOwner()
            ->monitors()
            ->whereKey($monitor)
            ->first()
            ?->notificationPreferences()
            ->where('notifiable_id', $this->recipient()?->getKey())
            ->delete();
    }

    public function render(): View
    {
        $query = MonitorQuery::forCurrentOwner();
        $sites = $query->sites();
        $recipient = $this->recipient();

        $rows = MonitorNotificationPreference::query()
            ->where('notifiable_id', $recipient?->getKey())
            ->get();

        return view('monitoring::livewire.notification-preferences', [
            'sites' => $sites,
            'recipient' => $recipient,
            'openSite' => $this->openSiteId === null ? null : $query->findSite($this->openSiteId),
            'sitePreferences' => $rows->whereNotNull('site_id')->keyBy('site_id'),
            'monitorPreferences' => $rows->whereNotNull('monitor_id')->keyBy('monitor_id'),
            'events' => self::EVENTS,
        ]);
    }

    protected function recipient(): ?Model
    {
        return auth()->user();
    }

    /**
     * The row for one subject, created empty the first time someone touches it.
     * Scoped, so an id from the browser cannot make a row against a site or
     * monitor this owner does not hold.
     */
    protected function preferenceRow(int|string $site, ?int $monitor): ?MonitorNotificationPreference
    {
        $query = MonitorQuery::forCurrentOwner();
        $recipient = $this->recipient();

        if ($recipient === null || $query->findSite($site) === null) {
            return null;
        }

        if ($monitor !== null) {
            $model = $query->findMonitor($monitor);

            if ($model === null || (string) $model->site_id !== (string) $site) {
                return null;
            }

            return MonitorNotificationPreference::firstOrCreate([
                'notifiable_id' => $recipient->getKey(),
                'monitor_id' => $monitor,
            ]);
        }

        return MonitorNotificationPreference::firstOrCreate([
            'notifiable_id' => $recipient->getKey(),
            'site_id' => $site,
        ]);
    }

    /** Field names arrive from the browser, so only these are ever written. */
    protected function isAllowedField(string $field): bool
    {
        return in_array($field, [
            'email_enabled',
            'database_enabled',
            'sms_enabled',
            ...array_keys(self::EVENTS),
        ], true);
    }
}
