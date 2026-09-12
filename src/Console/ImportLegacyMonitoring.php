<?php

namespace Abigah\BotCopTrafficDivision\Console;

use Abigah\BotCopTrafficDivision\Models\Monitor;
use Abigah\BotCopTrafficDivision\Models\MonitorCheck;
use Abigah\BotCopTrafficDivision\Models\MonitorCheckAggregate;
use Abigah\BotCopTrafficDivision\Models\MonitorDnsLookup;
use Abigah\BotCopTrafficDivision\Models\MonitorForgeSite;
use Abigah\BotCopTrafficDivision\Models\MonitorIncident;
use Abigah\BotCopTrafficDivision\Models\MonitorNotificationPreference;
use Abigah\BotCopTrafficDivision\Support\SiteMatcher;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\Ulid;

/**
 * Brings monitoring across from an installation that predates this package.
 *
 * It reads the table layout this package was extracted from — one `monitors`
 * table with the uptime, certificate and domain columns on it, and checks,
 * aggregates, incidents and preferences hanging off it. That shape predates
 * sites, so this serves both moving a client's history onto a new extranet and
 * an existing installation adopting this package.
 *
 * Two things it does not do. It never writes to the source, which is read with
 * a separate connection and left exactly as it was — a migration you can run
 * twice and abandon is worth more than one that is faster. And it does not
 * invent sites when the site model is the host's: `resolveSiteForMonitorUsing`
 * is how an application says which of its own sites a URL belongs to.
 */
class ImportLegacyMonitoring extends Command
{
    protected $signature = 'monitoring:import:legacy
        {--connection= : The database connection holding the old tables}
        {--owner=* : Only import monitors with these legacy owner_id values}
        {--as-owner= : Write this owner_id instead of the legacy one}
        {--checks-days=30 : Days of raw checks to bring. The hourly rollup keeps only the last two hours of raw checks, so anything older is aggregated and swept on its next run — the aggregates are what survive}
        {--chunk=1000 : Rows to read per query. Lower it on a small box, or to exercise the paging}
        {--dry-run : Report what would be imported without writing anything}';

    protected $description = 'Import monitors, history and incidents from a pre-sites monitoring install';

    protected SiteMatcher $matcher;

    /** @var array<int, int> legacy monitor id => new monitor id */
    protected array $monitorMap = [];

    /**
     * Rows the source held and this did not take.
     *
     * A non-zero count fails the command. An importer that cannot prove it was
     * complete is one bug away from being silently wrong again, and the last
     * time that happened it reported success while dropping nine per cent of a
     * client's history.
     */
    protected int $shortfall = 0;

    public function handle(SiteMatcher $matcher): int
    {
        $this->matcher = $matcher;

        $connection = $this->option('connection');

        if (! $connection) {
            $this->error('Name the source with --connection. It must be configured in config/database.php and is only ever read from.');

            return self::FAILURE;
        }

        $matcher->guardResolverIsWired();

        $source = DB::connection($connection);
        $legacyMonitors = $this->legacyMonitors($source);

        if ($legacyMonitors->isEmpty()) {
            $this->warn('No monitors matched. Check --connection and --owner.');

            return self::SUCCESS;
        }

        $this->info("Found {$legacyMonitors->count()} ".str('monitor')->plural($legacyMonitors->count())." on [{$connection}].");
        $this->newLine();

        $unattached = [];

        foreach ($legacyMonitors as $legacy) {
            $monitor = $this->importMonitor($legacy, $unattached);

            if ($monitor === null) {
                continue;
            }

            $this->monitorMap[$legacy->id] = $monitor->getKey();
        }

        $this->newLine();

        $incidents = $this->importIncidents($source);
        $checks = $this->importChecks($source);
        $aggregates = $this->importAggregates($source);
        $preferences = $this->importPreferences($source);
        $dnsLookups = $this->importDnsLookups($source);
        $forgeSites = $this->importForgeSites($source);

        $this->newLine();
        $this->info(($this->option('dry-run') ? 'Would import: ' : 'Imported: ')
            .count($this->monitorMap).' monitors, '
            ."{$incidents} incidents, {$checks} checks, {$aggregates} aggregates, "
            ."{$preferences} preferences, {$dnsLookups} DNS lookups, {$forgeSites} Forge sites.");

        if ($this->shortfall > 0) {
            $this->newLine();
            $this->error(sprintf(
                '%d source %s did not arrive. Re-running is safe and should report equal counts; '
                .'if it does not, something is dropping rows and the numbers above say where.',
                $this->shortfall,
                str('row')->plural($this->shortfall),
            ));
        }

        if ($unattached !== []) {
            $this->newLine();
            $this->warn(count($unattached).' monitor(s) had no site and were skipped:');

            foreach ($unattached as $url) {
                $this->line("  {$url}");
            }

            $this->line('Wire Monitoring::resolveSiteForMonitorUsing() to place them, then run this again.');
        }

        return $this->shortfall > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return Collection<int, object> */
    protected function legacyMonitors(Connection $source): Collection
    {
        $query = $source->table('monitors');

        if ($owners = array_filter((array) $this->option('owner'), fn ($value) => $value !== null && $value !== '')) {
            $query->whereIn('owner_id', $owners);
        }

        return $query->orderBy('id')->get();
    }

    /** @param  array<int, string>  $unattached */
    protected function importMonitor(object $legacy, array &$unattached): ?Monitor
    {
        $ownerId = $this->option('as-owner') ?: $legacy->owner_id;

        // A transient instance, so the site resolver sees a real monitor with a
        // real URL to reason about before anything is written.
        $candidate = new Monitor(['url' => $legacy->url, 'owner_id' => $ownerId]);

        $site = $this->matcher->for($candidate, create: ! $this->option('dry-run'));

        if ($site === null) {
            $unattached[] = (string) $legacy->url;

            return null;
        }

        $attributes = [
            'site_id' => $site->getKey(),
            'owner_id' => $ownerId,
            'uptime_check_enabled' => (bool) $legacy->uptime_check_enabled,
            'look_for_string' => $legacy->look_for_string ?? '',
            'fail_for_string' => $legacy->fail_for_string ?? '',
            'uptime_check_interval_in_minutes' => (int) $legacy->uptime_check_interval_in_minutes,
            'uptime_status' => $legacy->uptime_status,
            'uptime_check_failure_reason' => $legacy->uptime_check_failure_reason,
            'uptime_check_times_failed_in_a_row' => (int) $legacy->uptime_check_times_failed_in_a_row,
            'uptime_status_last_change_date' => $legacy->uptime_status_last_change_date,
            'uptime_last_check_date' => $legacy->uptime_last_check_date,
            'uptime_check_failed_event_fired_on_date' => $legacy->uptime_check_failed_event_fired_on_date,
            'uptime_check_method' => $legacy->uptime_check_method ?: 'get',
            'uptime_check_payload' => $legacy->uptime_check_payload,
            'uptime_check_additional_headers' => $legacy->uptime_check_additional_headers,
            'uptime_check_response_checker' => $legacy->uptime_check_response_checker,
            'certificate_check_enabled' => (bool) $legacy->certificate_check_enabled,
            'certificate_status' => $legacy->certificate_status,
            'certificate_expiration_date' => $legacy->certificate_expiration_date,
            'certificate_issuer' => $legacy->certificate_issuer,
            'certificate_check_failure_reason' => $legacy->certificate_check_failure_reason ?? '',
            'domain_expiry_check_enabled' => (bool) ($legacy->domain_expiry_check_enabled ?? true),
            'domain_expiry_status' => $legacy->domain_expiry_status ?? 'not yet checked',
            'domain_expiration_date' => $legacy->domain_expiration_date ?? null,
            'domain_registrar' => $legacy->domain_registrar ?? null,
            'domain_expiry_check_failure_reason' => $legacy->domain_expiry_check_failure_reason ?? '',
            'domain_expiry_notified_at' => $legacy->domain_expiry_notified_at ?? null,

            /*
             | Everything imported arrives critical. The old model had no such
             | flag, so every monitor was equally load-bearing — and "the site
             | is down" meaning less than it used to is the kind of change that
             | should be made deliberately, monitor by monitor, not inherited
             | from a default.
             */
            'critical' => true,
        ];

        $this->line(sprintf('  %-55s → %s', $legacy->url, $site->name ?? $site->getKey()));

        if ($this->option('dry-run')) {
            // Nothing is written, but the id has to be something the later
            // passes can count against.
            return (new Monitor(['url' => $legacy->url]))->forceFill(['id' => $legacy->id]);
        }

        return Monitor::updateOrCreate(
            ['site_id' => $site->getKey(), 'url' => $legacy->url],
            $attributes,
        );
    }

    protected function importIncidents(Connection $source): int
    {
        $imported = 0;

        foreach ($this->chunkedSource($source, 'monitor_incidents', 'started_at') as $rows) {
            foreach ($rows as $row) {
                $monitorId = $this->monitorMap[$row->monitor_id] ?? null;

                if ($monitorId === null || $this->option('dry-run')) {
                    $imported += $monitorId === null ? 0 : 1;

                    continue;
                }

                $monitor = Monitor::find($monitorId);

                /*
                 | Looked up with trashed rows included and `deleted_at` forced
                 | rather than filled. It is not fillable, so passed alongside
                 | the rest it was silently discarded and every incident deleted
                 | on the old install arrived undeleted; and without withTrashed()
                 | a second run would not find one that had arrived deleted, and
                 | would add a copy.
                 */
                MonitorIncident::withTrashed()
                    ->firstOrNew(['monitor_id' => $monitorId, 'started_at' => $row->started_at])
                    ->fill([
                        'site_id' => $monitor?->site_id,
                        'resolved_at' => $row->resolved_at,
                        'duration_seconds' => $row->duration_seconds,
                        'failure_reason' => $row->failure_reason,
                        'dismissal_reason' => $row->dismissal_reason ?? null,
                        'dismissal_note' => $row->dismissal_note ?? null,
                        'dismissed_by' => $row->dismissed_by ?? null,
                        'archived_at' => $row->archived_at ?? null,
                        'archived_by' => $row->archived_by ?? null,
                    ])
                    ->forceFill(['deleted_at' => $row->deleted_at ?? null])
                    ->save();

                $imported++;
            }
        }

        $this->reportPass('incidents', $imported, $this->sourceCount($source, 'monitor_incidents'));

        return $imported;
    }

    protected function importChecks(Connection $source): int
    {
        $days = (int) $this->option('checks-days');
        $cutoff = Carbon::now()->subDays($days);
        $imported = 0;

        /*
         | Said at the moment it would otherwise mislead. The hourly rollup
         | aggregates raw checks older than two hours and deletes them, so
         | asking for thirty days of raw history gets thirty days of rows that
         | the next scheduled run folds into buckets. The history is not lost —
         | the aggregates carry it, and those import too — but "680 imported,
         | 295 an hour later" reads like a fault unless you knew.
         */
        if ($days > 1 && ! $this->option('dry-run')) {
            $this->line(sprintf(
                '  note: raw checks older than the rollup window (2h) are aggregated and swept on its next run.'
            ));
        }

        foreach ($this->chunkedSource($source, 'monitor_checks', 'checked_at', $cutoff) as $rows) {
            $pending = [];

            foreach ($rows as $row) {
                $monitorId = $this->monitorMap[$row->monitor_id] ?? null;

                if ($monitorId === null) {
                    continue;
                }

                $imported++;

                if ($this->option('dry-run')) {
                    continue;
                }

                $checkedAt = Carbon::parse($row->checked_at);

                $pending[] = [
                    /*
                     | Minted from the moment the check was taken, not from now,
                     | so imported history sorts alongside everything that comes
                     | after it. A ULID's leading bits are its timestamp; that
                     | is the whole reason this column is one.
                     */
                    'check_id' => (string) new Ulid(Ulid::generate($checkedAt)),
                    'monitor_id' => $monitorId,
                    'status' => $row->status,
                    'response_time_ms' => $row->response_time_ms,
                    'failure_reason' => $row->failure_reason,
                    'checked_at' => $checkedAt,
                    'served_from_cache' => false,
                    'disagreed' => false,
                ];
            }

            $this->insertNewChecks($pending);
        }

        $this->reportPass(
            'checks',
            $imported,
            $this->sourceCount($source, 'monitor_checks', 'checked_at', $cutoff),
            " (last {$days} days)",
        );

        return $imported;
    }

    /**
     * DNS snapshots.
     *
     * Worth carrying because nothing recreates them. They are not derived and
     * never were — a lookup is taken on demand and kept, so a row is the record
     * of what DNS said on a particular day. A stale one is not useless; it is
     * the only evidence that anything was ever different.
     *
     * Keyed including `looked_up_at`, because the point of the table is the
     * history: keying on monitor and domain alone would collapse every snapshot
     * a domain ever had into its most recent one.
     */
    protected function importDnsLookups(Connection $source): int
    {
        if (! $this->sourceHas($source, 'dns_lookups')) {
            return 0;
        }

        $imported = 0;

        foreach ($this->chunkedSource($source, 'dns_lookups', 'looked_up_at') as $rows) {
            foreach ($rows as $row) {
                $monitorId = $this->monitorMap[$row->monitor_id] ?? null;

                if ($monitorId === null) {
                    continue;
                }

                $imported++;

                if ($this->option('dry-run')) {
                    continue;
                }

                MonitorDnsLookup::updateOrCreate(
                    [
                        'monitor_id' => $monitorId,
                        'domain' => $row->domain,
                        'looked_up_at' => $row->looked_up_at,
                    ],
                    [
                        // Decoded first: the column casts to array, and handing
                        // it the source's JSON string stores that string
                        // re-encoded rather than the records themselves.
                        'records' => is_string($row->records)
                            ? json_decode($row->records, true)
                            : $row->records,
                    ],
                );
            }
        }

        $this->reportPass('dns lookups', $imported, $this->sourceCount($source, 'dns_lookups'));

        return $imported;
    }

    /**
     * Forge links.
     *
     * Nothing regenerates one: it is a decision somebody made about which Forge
     * site a monitor covers, not an observation. Left behind, it has to be made
     * again by hand.
     *
     * They import whether or not this application has a Forge integration
     * configured — the panel that shows them renders nothing without one, but
     * the link belongs to the monitor either way, and configuring Forge later
     * should not mean rebuilding what was already known.
     */
    protected function importForgeSites(Connection $source): int
    {
        if (! $this->sourceHas($source, 'forge_sites')) {
            return 0;
        }

        $imported = 0;

        foreach ($this->chunkedSource($source, 'forge_sites', 'id') as $rows) {
            foreach ($rows as $row) {
                $monitorId = $this->monitorMap[$row->monitor_id] ?? null;

                if ($monitorId === null) {
                    continue;
                }

                $imported++;

                if ($this->option('dry-run')) {
                    continue;
                }

                MonitorForgeSite::updateOrCreate(
                    [
                        'monitor_id' => $monitorId,
                        'forge_server_id' => $row->forge_server_id,
                        'forge_site_id' => $row->forge_site_id,
                    ],
                    [
                        'server_name' => $row->server_name,
                        'site_name' => $row->site_name,
                    ],
                );
            }
        }

        $this->reportPass('forge sites', $imported, $this->sourceCount($source, 'forge_sites'));

        return $imported;
    }

    /**
     * Insert a chunk's worth of checks, skipping any already here.
     *
     * Two queries per chunk rather than one per row. The previous shape asked
     * the database whether each row existed, one row at a time, which over a
     * link to another region meant about four rows a second — 75 minutes for a
     * modest history, and the documented path for an extranet whose legacy
     * database is not co-located is exactly that one.
     *
     * `check_id` is this table's idempotency key and an imported row has no
     * natural one, so the pair that identifies a check is what is matched on.
     *
     * @param  array<int, array<string, mixed>>  $pending
     */
    protected function insertNewChecks(array $pending): void
    {
        if ($pending === []) {
            return;
        }

        $monitorIds = array_unique(array_column($pending, 'monitor_id'));
        $timestamps = array_map(fn (array $row) => $row['checked_at'], $pending);

        // One lookup for the whole chunk, narrowed by the range it covers so a
        // large existing history is not scanned.
        $existing = MonitorCheck::query()
            ->whereIn('monitor_id', $monitorIds)
            ->whereBetween('checked_at', [min($timestamps), max($timestamps)])
            ->get(['monitor_id', 'checked_at'])
            ->map(fn ($check) => $check->monitor_id.'@'.$check->checked_at->toDateTimeString())
            ->flip();

        $fresh = array_values(array_filter(
            $pending,
            fn (array $row) => ! $existing->has($row['monitor_id'].'@'.$row['checked_at']->toDateTimeString()),
        ));

        foreach (array_chunk($fresh, 500) as $batch) {
            MonitorCheck::insert($batch);
        }
    }

    protected function importAggregates(Connection $source): int
    {
        $imported = 0;

        foreach ($this->chunkedSource($source, 'monitor_check_aggregates', 'bucket_start') as $rows) {
            $pending = [];

            foreach ($rows as $row) {
                $monitorId = $this->monitorMap[$row->monitor_id] ?? null;

                if ($monitorId === null) {
                    continue;
                }

                $imported++;

                if ($this->option('dry-run')) {
                    continue;
                }

                $pending[] = [
                    'monitor_id' => $monitorId,
                    'bucket_type' => $row->bucket_type,
                    'bucket_start' => $row->bucket_start,
                    'avg_response_time_ms' => $row->avg_response_time_ms,
                    'min_response_time_ms' => $row->min_response_time_ms,
                    'max_response_time_ms' => $row->max_response_time_ms,
                    'total_checks' => $row->total_checks,
                    'up_checks' => $row->up_checks,
                    'down_checks' => $row->down_checks,
                ];
            }

            /*
             | One statement per chunk against the table's own unique key, in
             | place of a read and a write per row. This is the pass that
             | carries the volume — nine thousand rows on the first real import
             | — and the one where a round trip per row is felt.
             */
            foreach (array_chunk($pending, 500) as $batch) {
                MonitorCheckAggregate::upsert(
                    $batch,
                    ['monitor_id', 'bucket_type', 'bucket_start'],
                    ['avg_response_time_ms', 'min_response_time_ms', 'max_response_time_ms', 'total_checks', 'up_checks', 'down_checks'],
                );
            }
        }

        $this->reportPass('aggregates', $imported, $this->sourceCount($source, 'monitor_check_aggregates'));

        return $imported;
    }

    /**
     * Per-recipient preferences, which only make sense when the recipients
     * themselves came across with the same ids. When they did not, leave these
     * behind: the package's defaults are safer than preferences pointing at
     * whoever happens to hold that id now.
     */
    protected function importPreferences(Connection $source): int
    {
        $imported = 0;

        foreach ($this->chunkedSource($source, 'monitor_notification_preferences', 'id') as $rows) {
            foreach ($rows as $row) {
                $monitorId = $this->monitorMap[$row->monitor_id] ?? null;

                if ($monitorId === null) {
                    continue;
                }

                $imported++;

                if ($this->option('dry-run')) {
                    continue;
                }

                MonitorNotificationPreference::updateOrCreate(
                    ['notifiable_id' => $row->user_id, 'monitor_id' => $monitorId],
                    [
                        'email_enabled' => (bool) $row->email_enabled,
                        'database_enabled' => (bool) $row->database_enabled,
                        'sms_enabled' => (bool) $row->sms_enabled,
                        'uptime_failed' => (bool) $row->uptime_failed,
                        'uptime_recovered' => (bool) $row->uptime_recovered,
                        'certificate_failed' => (bool) $row->certificate_failed,
                        'certificate_expires_soon' => (bool) $row->certificate_expires_soon,
                        'domain_expires_soon' => (bool) ($row->domain_expires_soon ?? true),
                    ],
                );
            }
        }

        $this->reportPass('preferences', $imported, $this->sourceCount($source, 'monitor_notification_preferences'));

        return $imported;
    }

    /**
     * Read the source in chunks, restricted to the monitors being imported. A
     * client's history can be millions of rows and none of it needs to be in
     * memory at once.
     *
     * Keyset pagination on (sort column, id) rather than an offset. Every
     * column this is called with ties heavily — an hourly `bucket_start`
     * repeats once per monitor, so a 31-monitor import has 31 rows sharing each
     * value — and OFFSET has no defined order between two pages when the sort
     * key ties. A row can then land on both pages or on neither, and landing on
     * neither is silent.
     *
     * @return \Generator<int, Collection<int, object>>
     */
    protected function chunkedSource(
        Connection $source,
        string $table,
        string $orderBy,
        ?Carbon $since = null,
    ): \Generator {
        if ($this->monitorMap === []) {
            return;
        }

        $size = max(1, (int) $this->option('chunk'));

        $lastSort = null;
        $lastId = null;

        do {
            $query = $source->table($table)
                ->whereIn('monitor_id', array_keys($this->monitorMap))
                ->orderBy($orderBy)
                ->orderBy('id')
                ->limit($size);

            if ($since !== null) {
                $query->where($orderBy, '>=', $since);
            }

            // Strictly after the last row of the previous page, with id
            // breaking the tie the sort column cannot.
            if ($lastSort !== null) {
                $query->where(function ($query) use ($orderBy, $lastSort, $lastId) {
                    $query->where($orderBy, '>', $lastSort)
                        ->orWhere(function ($query) use ($orderBy, $lastSort, $lastId) {
                            $query->where($orderBy, '=', $lastSort)->where('id', '>', $lastId);
                        });
                });
            }

            $rows = $query->get();

            if ($rows->isEmpty()) {
                return;
            }

            $last = $rows->last();
            $lastSort = $last->{$orderBy};
            $lastId = $last->id;

            yield $rows;
        } while ($rows->count() === $size);
    }

    /**
     * Whether the source has this table at all.
     *
     * Not every installation this reads from has every table: an application
     * that never used the Forge integration has no `forge_sites`, and one that
     * came from a different package may have neither. A missing table is an
     * absence rather than a fault, so the pass says so once and moves on
     * instead of failing an import that is otherwise fine.
     */
    protected function sourceHas(Connection $source, string $table): bool
    {
        if ($source->getSchemaBuilder()->hasTable($table)) {
            return true;
        }

        $this->line("  {$table}: not present in the source, skipped");

        return false;
    }

    /**
     * How many rows the source holds for this pass, so a count that differs
     * from what arrived can be reported rather than assumed equal.
     */
    protected function sourceCount(
        Connection $source,
        string $table,
        ?string $sinceColumn = null,
        ?Carbon $since = null,
    ): int {
        if ($this->monitorMap === []) {
            return 0;
        }

        $query = $source->table($table)->whereIn('monitor_id', array_keys($this->monitorMap));

        if ($since !== null && $sinceColumn !== null) {
            $query->where($sinceColumn, '>=', $since);
        }

        return (int) $query->count();
    }

    /**
     * Say what was there beside what arrived.
     *
     * The old summary reported what it had iterated, which is the one number
     * guaranteed to agree with itself: an import that silently skipped four
     * hundred rows still reported success.
     */
    protected function reportPass(string $label, int $imported, int $available, string $note = ''): void
    {
        $this->line(sprintf('  %s: %d of %d%s', $label, $imported, $available, $note));

        if ($imported >= $available) {
            return;
        }

        $missing = $available - $imported;
        $this->shortfall += $missing;

        $this->warn(sprintf(
            '    %d source %s did not arrive.',
            $missing,
            str('row')->plural($missing),
        ));
    }
}
