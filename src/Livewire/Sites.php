<?php

namespace Abigah\BotCopTrafficDivision\Livewire;

use Abigah\BotCopTrafficDivision\Enums\UptimeStatus;
use Abigah\BotCopTrafficDivision\Facades\Monitoring;
use Abigah\BotCopTrafficDivision\Models\Monitor;
use Abigah\BotCopTrafficDivision\Models\MonitoredSite;
use Abigah\BotCopTrafficDivision\Models\MonitorHeartbeat;
use Abigah\BotCopTrafficDivision\Models\MonitorSiteException;
use Abigah\BotCopTrafficDivision\Services\MonitorQuery;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Component;

/**
 * The index. Sites, not monitors.
 *
 * A flat list of URLs answers "is this URL responding". The question people
 * actually arrive with is "is this site working", and that is a site's critical
 * monitors, its jobs and its error rate read together — three things that are
 * separate rows in a monitor list and one row here.
 */
class Sites extends Component
{
    public string $search = '';

    /** all, down, quiet — sites with something wrong but nothing failing outright. */
    public string $filter = 'all';

    /** @var array<int, string> Site keys, as strings — they come from checkboxes. */
    public array $selected = [];

    public bool $showBulkForm = false;

    /** @var array<string, mixed> */
    public array $bulkForm = [];

    public bool $showSiteForm = false;

    public string $newSiteName = '';

    /**
     * Whether this application can create a site from here.
     *
     * Only when the site model is the package's own. A host that supplies its
     * own — one belongs to a Client, another to a Project — creates sites
     * wherever it already creates them, and the package has no business
     * inventing rows in a table it does not own or guessing at columns it has
     * never seen.
     */
    public function canCreateSites(): bool
    {
        return Monitoring::siteModel() === MonitoredSite::class;
    }

    public function createSite(): void
    {
        if (! $this->canCreateSites()) {
            return;
        }

        $this->validate(['newSiteName' => ['required', 'string', 'max:255']]);

        MonitoredSite::create([
            'name' => $this->newSiteName,
            'owner_id' => MonitorQuery::forCurrentOwner()->ownerKey(),
        ]);

        $this->newSiteName = '';
        $this->showSiteForm = false;
    }

    /**
     * Add the same monitor to every selected site.
     *
     * Thirty-one sites arriving from an import with one monitor each is the
     * ordinary shape of this, and adding `/up` to all of them one at a time is
     * thirty-one visits to the same form. The path is appended to each site's
     * own host, which is the only part that differs.
     */
    public function bulkAddMonitor(): void
    {
        $data = $this->validate([
            'bulkForm.path' => ['required', 'string', 'starts_with:/'],
            'bulkForm.interval' => ['required', 'integer', 'min:'.config('monitoring.uptime.minimum_interval_minutes', 5), 'max:10080'],
            'bulkForm.critical' => ['boolean'],
            'bulkForm.look_for_string' => ['nullable', 'string', 'max:255'],
        ])['bulkForm'];

        $query = MonitorQuery::forCurrentOwner();

        foreach ($this->selectedSites() as $site) {
            $host = $this->hostFor($site);

            if ($host === null) {
                // Nothing to append a path to. A site with no monitor yet has
                // not told this application what it is.
                continue;
            }

            Monitor::updateOrCreate(
                ['site_id' => $site->getKey(), 'url' => $host.$data['path']],
                [
                    'owner_id' => $query->ownerKey(),
                    'uptime_check_interval_in_minutes' => (int) $data['interval'],
                    'critical' => (bool) $data['critical'],
                    'look_for_string' => $data['look_for_string'] ?? '',
                    'uptime_check_enabled' => true,
                ],
            );
        }

        $this->selected = [];
        $this->showBulkForm = false;
    }

    /** Mark every monitor on the selected sites critical, or stop them being. */
    public function bulkSetCritical(bool $critical): void
    {
        $siteIds = $this->selectedSites()->map(fn (Model $site) => $site->getKey())->all();

        if ($siteIds === []) {
            return;
        }

        Monitor::query()
            ->whereIn('site_id', $siteIds)
            ->where('owner_id', MonitorQuery::forCurrentOwner()->ownerKey())
            ->update(['critical' => $critical]);

        $this->selected = [];
    }

    public function openBulkForm(): void
    {
        $this->bulkForm = [
            'path' => '/up',
            'interval' => (int) config('monitoring.uptime.minimum_interval_minutes', 5),
            'critical' => true,
            'look_for_string' => '',
        ];
        $this->resetValidation();
        $this->showBulkForm = true;
    }

    /**
     * The selection, narrowed to sites this owner actually holds — the ids came
     * from checkboxes in a browser.
     *
     * @return Collection<int, Model>
     */
    protected function selectedSites(): Collection
    {
        $query = MonitorQuery::forCurrentOwner();

        return collect($this->selected)
            ->map(fn ($id) => $query->findSite($id))
            ->filter()
            ->values();
    }

    /**
     * The scheme and host this site is already being checked on. Taken from its
     * monitors rather than its name, because a name is a label and a URL is a
     * fact.
     */
    protected function hostFor(Model $site): ?string
    {
        $url = $site->monitors()->orderBy('id')->value('url');

        if ($url === null) {
            return null;
        }

        $parts = parse_url((string) $url);

        return isset($parts['scheme'], $parts['host'])
            ? $parts['scheme'].'://'.$parts['host']
            : null;
    }

    public function render(): View
    {
        return view('monitoring::livewire.sites', [
            'sites' => $this->sites(),
            'summary' => MonitorQuery::forCurrentOwner()->summary(),
        ]);
    }

    /** @return Collection<int, array<string, mixed>> */
    protected function sites(): Collection
    {
        $query = MonitorQuery::forCurrentOwner();
        $sites = $query->sites();
        $siteIds = $sites->map(fn (Model $site) => $site->getKey())->all();

        // Three queries for the whole page rather than three per site.
        $monitors = Monitor::query()->whereIn('site_id', $siteIds)->get()->groupBy('site_id');
        $heartbeats = MonitorHeartbeat::query()->whereIn('site_id', $siteIds)->get()->groupBy('site_id');
        $errors = MonitorSiteException::query()
            ->whereIn('site_id', $siteIds)
            ->unresolved()
            ->where('last_seen_at', '>=', now()->subDay())
            ->get()
            ->groupBy('site_id');

        return $sites
            ->map(fn (Model $site) => $this->summarise(
                $site,
                $monitors->get($site->getKey(), collect()),
                $heartbeats->get($site->getKey(), collect()),
                $errors->get($site->getKey(), collect()),
            ))
            ->filter(fn (array $row) => $this->matchesSearch($row) && $this->matchesFilter($row))
            ->sortBy([
                // Anything wrong comes first. A list you have to scroll to find
                // the outage in is a list that is read too late.
                fn (array $a, array $b) => $b['status_weight'] <=> $a['status_weight'],
                fn (array $a, array $b) => strcasecmp($a['name'], $b['name']),
            ])
            ->values();
    }

    /**
     * @param  Collection<int, Monitor>  $monitors
     * @param  Collection<int, MonitorHeartbeat>  $heartbeats
     * @param  Collection<int, MonitorSiteException>  $errors
     * @return array<string, mixed>
     */
    protected function summarise(Model $site, Collection $monitors, Collection $heartbeats, Collection $errors): array
    {
        $enabled = $monitors->where('uptime_check_enabled', true);
        $critical = $enabled->where('critical', true);

        $isDown = $critical->contains(fn (Monitor $monitor) => $monitor->uptime_status === UptimeStatus::DOWN->value);
        $degraded = ! $isDown && $enabled->contains(fn (Monitor $monitor) => $monitor->uptime_status === UptimeStatus::DOWN->value);

        $missedJobs = $heartbeats
            ->where('enabled', true)
            ->filter(fn (MonitorHeartbeat $beat) => $beat->status->isAlerting())
            ->count();

        return [
            'site' => $site,
            'id' => $site->getKey(),
            'name' => (string) ($site->name ?? "Site {$site->getKey()}"),
            'hibernates' => (bool) $site->hibernates,
            'monitors' => $monitors,
            'monitor_count' => $monitors->count(),
            'paused_count' => $monitors->count() - $enabled->count(),
            'critical_count' => $critical->count(),
            'down' => $isDown,

            // One monitor failing that nobody promised was load-bearing. Worth
            // showing, not worth calling the site down.
            'degraded' => $degraded,

            'missed_jobs' => $missedJobs,
            'heartbeat_count' => $heartbeats->where('enabled', true)->count(),
            'errors_today' => $errors->sum('occurrences'),
            'new_errors' => $errors->count(),
            'response_ms' => $enabled->map(fn (Monitor $monitor) => $monitor->checks()->latest('checked_at')->value('response_time_ms'))->filter()->avg(),
            'status_weight' => match (true) {
                $isDown => 3,
                $degraded || $missedJobs > 0 => 2,
                $errors->isNotEmpty() => 1,
                default => 0,
            },
        ];
    }

    /** @param  array<string, mixed>  $row */
    protected function matchesSearch(array $row): bool
    {
        if ($this->search === '') {
            return true;
        }

        $needle = mb_strtolower($this->search);

        return str_contains(mb_strtolower($row['name']), $needle)
            || $row['monitors']->contains(fn (Monitor $monitor) => str_contains(mb_strtolower((string) $monitor->url), $needle));
    }

    /** @param  array<string, mixed>  $row */
    protected function matchesFilter(array $row): bool
    {
        return match ($this->filter) {
            'down' => $row['down'],
            'quiet' => ! $row['down'] && $row['status_weight'] > 0,
            default => true,
        };
    }
}
