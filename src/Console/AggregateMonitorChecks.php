<?php

namespace Abigah\BotCopTrafficDivision\Console;

use Abigah\BotCopTrafficDivision\Models\MonitorCheck;
use Abigah\BotCopTrafficDivision\Models\MonitorCheckAggregate;
use Illuminate\Console\Command;

class AggregateMonitorChecks extends Command
{
    protected $signature = 'monitor-checks:aggregate';

    protected $description = 'Aggregate old monitor checks into hourly/daily summaries and prune raw data';

    public function handle(): void
    {
        $this->hourlyRollup();
        $this->dailyRollup();
        $this->pruneOldAggregates();

        $this->info('Monitor check aggregation complete.');
    }

    /**
     * Response time, but only where there was a response.
     *
     * A failed check still carries a duration and it is not a response time. A
     * challenge refused at the edge comes back in two milliseconds; a timeout
     * takes the whole timeout. Rolled up together with real responses they are
     * indistinguishable afterwards, because the raw checks are pruned once this
     * has run — so a bucket that averaged in its failures is wrong for good.
     *
     * `up_checks` and `down_checks` already record what happened. These three
     * columns describe how the checks that succeeded performed, and a bucket
     * with none of those stores null rather than a number that reads like a
     * fast response.
     */
    protected function upResponseTime(): string
    {
        return "CASE WHEN status = 'up' THEN response_time_ms END";
    }

    protected function hourlyRollup(): void
    {
        $cutoff = now()->subHours(2);

        $buckets = MonitorCheck::query()
            ->where('checked_at', '<', $cutoff)
            ->select('monitor_id')
            ->selectRaw($this->hourlyBucketExpression().' as bucket_start')
            ->selectRaw("ROUND(AVG({$this->upResponseTime()})) as avg_response_time_ms")
            ->selectRaw("MIN({$this->upResponseTime()}) as min_response_time_ms")
            ->selectRaw("MAX({$this->upResponseTime()}) as max_response_time_ms")
            ->selectRaw('COUNT(*) as total_checks')
            ->selectRaw("SUM(CASE WHEN status = 'up' THEN 1 ELSE 0 END) as up_checks")
            ->selectRaw("SUM(CASE WHEN status = 'down' THEN 1 ELSE 0 END) as down_checks")
            ->groupBy('monitor_id', 'bucket_start')
            ->get();

        foreach ($buckets->chunk(100) as $chunk) {
            MonitorCheckAggregate::upsert(
                $chunk->map(fn ($bucket) => [
                    'monitor_id' => $bucket->monitor_id,
                    'bucket_type' => 'hourly',
                    'bucket_start' => $bucket->bucket_start,
                    'avg_response_time_ms' => $bucket->avg_response_time_ms,
                    'min_response_time_ms' => $bucket->min_response_time_ms,
                    'max_response_time_ms' => $bucket->max_response_time_ms,
                    'total_checks' => $bucket->total_checks,
                    'up_checks' => $bucket->up_checks,
                    'down_checks' => $bucket->down_checks,
                ])->all(),
                ['monitor_id', 'bucket_type', 'bucket_start'],
                ['avg_response_time_ms', 'min_response_time_ms', 'max_response_time_ms', 'total_checks', 'up_checks', 'down_checks'],
            );
        }

        MonitorCheck::where('checked_at', '<', $cutoff)->delete();

        $this->line('Hourly rollup complete.');
    }

    protected function dailyRollup(): void
    {
        $cutoff = now()->subDays(8);

        $buckets = MonitorCheckAggregate::query()
            ->where('bucket_type', 'hourly')
            ->where('bucket_start', '<', $cutoff)
            ->select('monitor_id')
            ->selectRaw($this->dayBucketExpression().' as bucket_day')
            ->selectRaw('ROUND(SUM(avg_response_time_ms * up_checks) / NULLIF(SUM(up_checks), 0)) as avg_response_time_ms')
            ->selectRaw('MIN(min_response_time_ms) as min_response_time_ms')
            ->selectRaw('MAX(max_response_time_ms) as max_response_time_ms')
            ->selectRaw('SUM(total_checks) as total_checks')
            ->selectRaw('SUM(up_checks) as up_checks')
            ->selectRaw('SUM(down_checks) as down_checks')
            ->groupBy('monitor_id', 'bucket_day')
            ->get();

        foreach ($buckets->chunk(100) as $chunk) {
            MonitorCheckAggregate::upsert(
                $chunk->map(fn ($bucket) => [
                    'monitor_id' => $bucket->monitor_id,
                    'bucket_type' => 'daily',
                    'bucket_start' => $bucket->bucket_day.' 00:00:00',
                    'avg_response_time_ms' => $bucket->avg_response_time_ms,
                    'min_response_time_ms' => $bucket->min_response_time_ms,
                    'max_response_time_ms' => $bucket->max_response_time_ms,
                    'total_checks' => $bucket->total_checks,
                    'up_checks' => $bucket->up_checks,
                    'down_checks' => $bucket->down_checks,
                ])->all(),
                ['monitor_id', 'bucket_type', 'bucket_start'],
                ['avg_response_time_ms', 'min_response_time_ms', 'max_response_time_ms', 'total_checks', 'up_checks', 'down_checks'],
            );
        }

        MonitorCheckAggregate::where('bucket_type', 'hourly')
            ->where('bucket_start', '<', $cutoff)
            ->delete();

        $this->line('Daily rollup complete.');
    }

    /**
     * Truncating a timestamp to its hour is spelled differently by every
     * driver. The extranets run MySQL; SQLite is what the tests run on, and a
     * rollup nobody can test is a rollup nobody trusts.
     */
    protected function hourlyBucketExpression(): string
    {
        return match (MonitorCheck::query()->getConnection()->getDriverName()) {
            'sqlite' => "strftime('%Y-%m-%d %H:00:00', checked_at)",
            'pgsql' => "to_char(date_trunc('hour', checked_at), 'YYYY-MM-DD HH24:MI:SS')",
            default => "DATE_FORMAT(checked_at, '%Y-%m-%d %H:00:00')",
        };
    }

    protected function dayBucketExpression(): string
    {
        return match (MonitorCheck::query()->getConnection()->getDriverName()) {
            'sqlite' => "strftime('%Y-%m-%d', bucket_start)",
            'pgsql' => "to_char(bucket_start, 'YYYY-MM-DD')",
            default => 'DATE(bucket_start)',
        };
    }

    protected function pruneOldAggregates(): void
    {
        $cutoff = now()->subDays(400);

        $deleted = MonitorCheckAggregate::where('bucket_type', 'daily')
            ->where('bucket_start', '<', $cutoff)
            ->delete();

        if ($deleted > 0) {
            $this->line("Pruned {$deleted} old daily aggregates.");
        }
    }
}
