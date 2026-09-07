<?php

namespace Abigah\BotCopTrafficDivision\Services;

use Abigah\BotCopTrafficDivision\Enums\UptimeStatus;
use Abigah\BotCopTrafficDivision\Facades\Monitoring;
use Abigah\BotCopTrafficDivision\Models\Monitor;
use Abigah\BotCopTrafficDivision\Models\MonitorCheck;
use Abigah\BotCopTrafficDivision\Models\MonitorHeartbeat;
use Abigah\BotCopTrafficDivision\Models\MonitorIncident;
use Abigah\BotCopTrafficDivision\Models\MonitorSiteException;
use Abigah\BotCopTrafficDivision\Support\Period;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Every read of one owner's monitoring data.
 *
 * The scope is fixed when the query is built and cannot be widened afterwards,
 * so "this owner's monitors" is defined once instead of being re-derived at
 * each call site. Anything that reaches a monitor, check or incident by an id
 * from outside — a Livewire action, an API request — should go through
 * findMonitor()/findIncident() rather than the models directly, which is what
 * keeps one owner from addressing another's records by guessing an id.
 */
class MonitorQuery
{
    private function __construct(private readonly mixed $ownerKey) {}

    /**
     * Scope to an owner by key.
     */
    public static function forOwner(mixed $ownerKey): self
    {
        return new self($ownerKey);
    }

    /**
     * Scope to whichever owner the host application currently resolves.
     */
    public static function forCurrentOwner(): self
    {
        $owner = Monitoring::currentOwner();

        if ($owner === null) {
            throw new RuntimeException(
                'Monitoring::resolveCurrentOwnerUsing() resolved no owner, so there is nothing to scope monitoring to.'
            );
        }

        return new self($owner->getKey());
    }

    public function ownerKey(): mixed
    {
        return $this->ownerKey;
    }

    /**
     * This owner's sites, in the order the host returns them.
     *
     * The site model is the host's, so this goes through the resolver rather
     * than querying a table the package may not own.
     *
     * @return Collection<int, Model>
     */
    public function sites(): Collection
    {
        $owner = Monitoring::ownerModel()::find($this->ownerKey);

        return $owner === null
            ? collect()
            : collect(Monitoring::sitesFor($owner));
    }

    /** @return list<int|string> */
    public function siteIds(): array
    {
        return $this->sites()->map(fn ($site) => $site->getKey())->all();
    }

    /**
     * One of this owner's sites, or null when the id belongs to someone else or
     * to nothing at all. The two cases are deliberately indistinguishable.
     */
    public function findSite(int|string|null $id): mixed
    {
        if ($id === null) {
            return null;
        }

        return $this->sites()->first(fn ($site) => (string) $site->getKey() === (string) $id);
    }

    /**
     * Heartbeats across this owner's sites.
     *
     * @return Builder<MonitorHeartbeat>
     */
    public function heartbeats(): Builder
    {
        return MonitorHeartbeat::query()
            ->whereIn('site_id', $this->siteIds())
            ->orderBy('name');
    }

    /**
     * Reported server errors across this owner's sites, most recent first.
     *
     * @return Builder<MonitorSiteException>
     */
    public function siteExceptions(): Builder
    {
        return MonitorSiteException::query()
            ->whereIn('site_id', $this->siteIds())
            ->orderByDesc('last_seen_at');
    }

    /**
     * This owner's monitors.
     *
     * @return Builder<Monitor>
     */
    public function monitors(): Builder
    {
        return Monitor::query()->forOwner($this->ownerKey);
    }

    /**
     * The ids of this owner's monitors, optionally only the enabled ones.
     *
     * @return list<int>
     */
    public function monitorIds(bool $enabledOnly = false): array
    {
        $query = $this->monitors();

        if ($enabledOnly) {
            $query->enabled();
        }

        return $query->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * One of this owner's monitors, or null when the id belongs to someone
     * else or to nothing at all. The two cases are deliberately
     * indistinguishable to the caller.
     */
    public function findMonitor(int|string|null $id): ?Monitor
    {
        if ($id === null) {
            return null;
        }

        return $this->monitors()->whereKey($id)->first();
    }

    /**
     * As findMonitor(), but for a caller that has nothing sensible to do
     * without the monitor.
     */
    public function findMonitorOrFail(int|string|null $id): Monitor
    {
        $monitor = $this->findMonitor($id);

        if ($monitor === null) {
            throw (new ModelNotFoundException)->setModel(Monitor::class, [$id]);
        }

        return $monitor;
    }

    /**
     * The checks recorded against one of this owner's monitors, newest first.
     *
     * @return Builder<MonitorCheck>
     */
    public function checks(Monitor|int $monitor): Builder
    {
        $monitorId = $monitor instanceof Monitor ? $monitor->getKey() : $monitor;

        return MonitorCheck::query()
            ->whereIn('monitor_id', $this->monitorIds())
            ->where('monitor_id', $monitorId)
            ->orderByDesc('checked_at');
    }

    /**
     * Incidents across this owner's monitors, newest first.
     *
     * The filter matches the incident screen's tabs: 'ongoing', 'resolved',
     * 'archived', or null for everything still live.
     *
     * @return Builder<MonitorIncident>
     */
    public function incidents(?string $filter = null): Builder
    {
        $query = MonitorIncident::query()
            ->whereIn('monitor_id', $this->monitorIds())
            ->orderByDesc('started_at');

        return match ($filter) {
            'ongoing' => $query->ongoing(),
            'resolved' => $query->whereNotNull('resolved_at')->whereNull('archived_at'),
            'archived' => $query->whereNotNull('archived_at'),
            default => $query->active(),
        };
    }

    /**
     * One incident on one of this owner's monitors, or null.
     */
    public function findIncident(int|string|null $id): ?MonitorIncident
    {
        if ($id === null) {
            return null;
        }

        return MonitorIncident::query()
            ->whereKey($id)
            ->whereIn('monitor_id', $this->monitorIds())
            ->first();
    }

    /**
     * The incident behind a monitor's current outage, if it is down.
     */
    public function ongoingIncidentFor(Monitor|int $monitor): ?MonitorIncident
    {
        $monitorId = $monitor instanceof Monitor ? $monitor->getKey() : $monitor;

        return MonitorIncident::query()
            ->where('monitor_id', $monitorId)
            ->whereIn('monitor_id', $this->monitorIds())
            ->ongoing()
            ->latest('started_at')
            ->first();
    }

    /**
     * Response-time and status series for the owner's enabled monitors, or for
     * the given ones.
     *
     * @param  list<int>|null  $monitorIds
     * @return array{labels: list<string>, response_times: list<int|null>, statuses: list<string>}
     */
    public function chartData(
        Period $period,
        string $timezone,
        bool $use24hr = false,
        bool $rawIndividualChecks = false,
        ?array $monitorIds = null,
    ): array {
        return app(MonitorChartService::class)->chartData(
            $this->resolveMonitorIds($monitorIds),
            $period->value,
            $period->since(),
            $timezone,
            $use24hr,
            $rawIndividualChecks,
        );
    }

    /**
     * Uptime and response-time figures over the period.
     *
     * @param  list<int>|null  $monitorIds
     * @return array{uptime: float, total: int, up: int, down: int, avg_ms: int, min_ms: int, max_ms: int, incidents?: int}
     */
    public function stats(Period $period, bool $includeIncidents = false, ?array $monitorIds = null): array
    {
        return app(MonitorChartService::class)->stats(
            $this->resolveMonitorIds($monitorIds),
            $period->value,
            $period->since(),
            $includeIncidents,
        );
    }

    /**
     * A single figure for every question a status tile asks.
     *
     * @return array{sites: int, sites_down: int, monitors: int, up: int, down: int, paused: int, ongoing_incidents: int, uptime: float}
     */
    public function summary(Period $period = Period::Day): array
    {
        $monitors = $this->monitors()->get(['id', 'uptime_check_enabled', 'uptime_status', 'critical', 'site_id']);

        $enabled = $monitors->where('uptime_check_enabled', true);

        /*
         | "Sites down" is not "monitors down". A site is down when one of its
         | critical monitors is, so a blog subdomain failing is one monitor down
         | and no sites down — which is the distinction the whole `critical`
         | flag exists to draw.
         */
        $sitesDown = $enabled
            ->where('critical', true)
            ->where('uptime_status', UptimeStatus::DOWN->value)
            ->pluck('site_id')
            ->filter()
            ->unique()
            ->count();

        return [
            'sites' => $this->sites()->count(),
            'sites_down' => $sitesDown,
            'monitors' => $monitors->count(),
            'up' => $enabled->where('uptime_status', UptimeStatus::UP->value)->count(),
            'down' => $enabled->where('uptime_status', UptimeStatus::DOWN->value)->count(),
            'paused' => $monitors->where('uptime_check_enabled', false)->count(),
            'ongoing_incidents' => $this->incidents('ongoing')->count(),
            'uptime' => $this->stats($period)['uptime'],
        ];
    }

    /**
     * Narrow a caller's monitor ids to the ones this owner actually holds, or
     * fall back to every enabled monitor.
     *
     * @param  list<int>|null  $monitorIds
     * @return list<int>
     */
    private function resolveMonitorIds(?array $monitorIds): array
    {
        if ($monitorIds === null) {
            return $this->monitorIds(enabledOnly: true);
        }

        return array_values(array_intersect($monitorIds, $this->monitorIds()));
    }
}
