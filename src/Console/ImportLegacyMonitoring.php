<?php

namespace Abigah\BotCopTrafficDivision\Console;

use Abigah\BotCopTrafficDivision\Models\Monitor;
use Abigah\BotCopTrafficDivision\Models\MonitorCheck;
use Abigah\BotCopTrafficDivision\Models\MonitorCheckAggregate;
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
        {--checks-days=30 : How many days of raw check history to bring (aggregates carry the rest)}
        {--chunk=1000 : Rows to read per query. Lower it on a small box, or to exercise the paging}
        {--dry-run : Report what would be imported without writing anything}';

    protected $description = 'Import monitors, history and incidents from a pre-sites monitoring install';

    protected SiteMatcher $matcher;

    /** @var array<int, int> legacy monitor id => new monitor id */
    protected array $monitorMap = [];

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

        $this->newLine();
        $this->info(($this->option('dry-run') ? 'Would import: ' : 'Imported: ')
            .count($this->monitorMap).' monitors, '
            ."{$incidents} incidents, {$checks} checks, {$aggregates} aggregates, {$preferences} preferences.");

        if ($unattached !== []) {
            $this->newLine();
            $this->warn(count($unattached).' monitor(s) had no site and were skipped:');

            foreach ($unattached as $url) {
                $this->line("  {$url}");
            }

            $this->line('Wire Monitoring::resolveSiteForMonitorUsing() to place them, then run this again.');
        }

        return self::SUCCESS;
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

                MonitorIncident::updateOrCreate(
                    ['monitor_id' => $monitorId, 'started_at' => $row->started_at],
                    [
                        'site_id' => $monitor?->site_id,
                        'resolved_at' => $row->resolved_at,
                        'duration_seconds' => $row->duration_seconds,
                        'failure_reason' => $row->failure_reason,
                        'dismissal_reason' => $row->dismissal_reason ?? null,
                        'dismissal_note' => $row->dismissal_note ?? null,
                        'dismissed_by' => $row->dismissed_by ?? null,
                        'archived_at' => $row->archived_at ?? null,
                        'archived_by' => $row->archived_by ?? null,
                        'deleted_at' => $row->deleted_at ?? null,
                    ],
                );

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

        foreach ($this->chunkedSource($source, 'monitor_checks', 'checked_at', $cutoff) as $rows) {
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

                // Already here from an earlier run. check_id is this table's
                // idempotency key, and an imported row has no natural one, so
                // the pair that identifies a check is what is matched on.
                $exists = MonitorCheck::query()
                    ->where('monitor_id', $monitorId)
                    ->where('checked_at', $checkedAt)
                    ->exists();

                if ($exists) {
                    continue;
                }

                MonitorCheck::create([
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
                ]);
            }
        }

        $this->reportPass(
            'checks',
            $imported,
            $this->sourceCount($source, 'monitor_checks', 'checked_at', $cutoff),
            " (last {$days} days)",
        );

        return $imported;
    }

    protected function importAggregates(Connection $source): int
    {
        $imported = 0;

        foreach ($this->chunkedSource($source, 'monitor_check_aggregates', 'bucket_start') as $rows) {
            foreach ($rows as $row) {
                $monitorId = $this->monitorMap[$row->monitor_id] ?? null;

                if ($monitorId === null) {
                    continue;
                }

                $imported++;

                if ($this->option('dry-run')) {
                    continue;
                }

                MonitorCheckAggregate::updateOrCreate(
                    [
                        'monitor_id' => $monitorId,
                        'bucket_type' => $row->bucket_type,
                        'bucket_start' => $row->bucket_start,
                    ],
                    [
                        'avg_response_time_ms' => $row->avg_response_time_ms,
                        'min_response_time_ms' => $row->min_response_time_ms,
                        'max_response_time_ms' => $row->max_response_time_ms,
                        'total_checks' => $row->total_checks,
                        'up_checks' => $row->up_checks,
                        'down_checks' => $row->down_checks,
                    ],
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

        if ($imported < $available) {
            $missing = $available - $imported;

            $this->warn(sprintf(
                '    %d source %s did not arrive. Re-run; a clean import reports equal counts.',
                $missing,
                str('row')->plural($missing),
            ));
        }
    }
}
