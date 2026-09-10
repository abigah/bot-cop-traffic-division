<?php

namespace Abigah\BotCopTrafficDivision\Services;

use Abigah\BotCopTrafficDivision\Models\MonitorCheck;
use Abigah\BotCopTrafficDivision\Models\MonitorCheckAggregate;
use Abigah\BotCopTrafficDivision\Models\MonitorIncident;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class MonitorChartService
{
    /** @var array{labels: list<string>, response_times: list<int|null>, statuses: list<string>} */
    private const EMPTY_CHART = ['labels' => [], 'response_times' => [], 'statuses' => []];

    /**
     * A database-portable SQL expression that truncates a datetime column to
     * the given granularity, producing a "Y-m-d H:i:s" bucket string suitable
     * for grouping and for Carbon::parse().
     */
    protected function bucketExpression(string $column, string $granularity): string
    {
        $driver = DB::connection()->getDriverName();

        $patterns = [
            'strftime' => ['minute' => '%Y-%m-%d %H:%M:00', 'hour' => '%Y-%m-%d %H:00:00', 'day' => '%Y-%m-%d 00:00:00'],
            'to_char' => ['minute' => 'YYYY-MM-DD HH24:MI:00', 'hour' => 'YYYY-MM-DD HH24:00:00', 'day' => 'YYYY-MM-DD 00:00:00'],
            'date_format' => ['minute' => '%Y-%m-%d %H:%i:00', 'hour' => '%Y-%m-%d %H:00:00', 'day' => '%Y-%m-%d 00:00:00'],
        ];

        return match ($driver) {
            'sqlite' => "strftime('{$patterns['strftime'][$granularity]}', {$column})",
            'pgsql' => "to_char({$column}, '{$patterns['to_char'][$granularity]}')",
            default => "DATE_FORMAT({$column}, '{$patterns['date_format'][$granularity]}')",
        };
    }

    /**
     * Response time, but only where there was a response.
     *
     * A failed check still carries a duration and it is not a response time. A
     * challenge refused at the edge comes back in two milliseconds; a timeout
     * takes the whole timeout. Averaged in with real responses they report a
     * site at its fastest in the moment it stopped answering, and put "Average
     * 123ms" beside "Uptime 0%" — two numbers that cannot both be true.
     *
     * Uptime already says the check failed. These columns describe how the
     * checks that succeeded performed, and a window with none of those has no
     * answer to give rather than a misleading one.
     */
    protected function upResponseTime(string $column = 'response_time_ms'): string
    {
        return "CASE WHEN status = 'up' THEN {$column} END";
    }

    /**
     * Build chart data for the given period and monitors.
     *
     * @param  list<int>  $monitorIds
     * @param  bool  $rawIndividualChecks  When true, raw period shows individual checks instead of minute-grouped data
     * @return array{labels: list<string>, response_times: list<int|null>, statuses: list<string>}
     */
    public function chartData(array $monitorIds, string $period, CarbonInterface $since, string $tz, bool $use24hr = false, bool $rawIndividualChecks = false): array
    {
        if (empty($monitorIds)) {
            return self::EMPTY_CHART;
        }

        if ($period === '1h') {
            $format = $use24hr ? 'H:i' : 'g:i A';

            return $rawIndividualChecks
                ? $this->rawChartDataIndividual($monitorIds, $since, $format, $tz)
                : $this->rawChartDataGrouped($monitorIds, $since, $format, $tz);
        }

        if ($period === '24h') {
            return $this->hourlyChartData($monitorIds, $since, $use24hr ? 'H:00' : 'g A', $tz);
        }

        if ($period === '7d') {
            return $this->aggregateChartData($monitorIds, $since, 'hourly', $use24hr ? 'M j, H:00' : 'M j, gA', $tz);
        }

        return $this->aggregateChartData($monitorIds, $since, 'daily', 'M j', $tz);
    }

    /**
     * Build stats for the given period and monitors.
     *
     * @param  list<int>  $monitorIds
     * @return array{uptime: float, total: int, up: int, down: int, avg_ms: int, min_ms: int, max_ms: int, incidents?: int}
     */
    public function stats(array $monitorIds, string $period, CarbonInterface $since, bool $includeIncidents = false): array
    {
        if (empty($monitorIds)) {
            $result = ['uptime' => 100.0, 'total' => 0, 'up' => 0, 'down' => 0, 'avg_ms' => 0, 'min_ms' => 0, 'max_ms' => 0];

            return $includeIncidents ? array_merge($result, ['incidents' => 0]) : $result;
        }

        if ($period === '1h') {
            return $this->rawStats($monitorIds, $since, $includeIncidents);
        }

        return $this->combinedStats($monitorIds, $period, $since, $includeIncidents);
    }

    /**
     * @param  list<int>  $monitorIds
     * @return array{labels: list<string>, response_times: list<int|null>, statuses: list<string>}
     */
    private function rawChartDataGrouped(array $monitorIds, CarbonInterface $since, string $dateFormat, string $tz): array
    {
        $rows = MonitorCheck::whereIn('monitor_id', $monitorIds)
            ->where('checked_at', '>=', $since)
            ->selectRaw($this->bucketExpression('checked_at', 'minute').' as bucket')
            ->selectRaw("ROUND(AVG({$this->upResponseTime()})) as avg_ms")
            ->selectRaw("SUM(CASE WHEN status = 'down' THEN 1 ELSE 0 END) as down_count")
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get();

        if ($rows->isEmpty()) {
            return self::EMPTY_CHART;
        }

        return [
            'labels' => $rows->map(fn ($r) => Carbon::parse($r->bucket)->setTimezone($tz)->format($dateFormat))->values()->all(),
            'response_times' => $rows->map(fn ($r) => $r->avg_ms ? (int) $r->avg_ms : null)->values()->all(),
            'statuses' => $rows->map(fn ($r) => $r->down_count > 0 ? 'down' : 'up')->values()->all(),
        ];
    }

    /**
     * @param  list<int>  $monitorIds
     * @return array{labels: list<string>, response_times: list<int|null>, statuses: list<string>}
     */
    private function rawChartDataIndividual(array $monitorIds, CarbonInterface $since, string $dateFormat, string $tz): array
    {
        $rows = MonitorCheck::whereIn('monitor_id', $monitorIds)
            ->where('checked_at', '>=', $since)
            ->orderBy('checked_at')
            ->get(['checked_at', 'response_time_ms', 'status']);

        if ($rows->isEmpty()) {
            return self::EMPTY_CHART;
        }

        return [
            'labels' => $rows->map(fn ($r) => $r->checked_at->setTimezone($tz)->format($dateFormat))->values()->all(),
            'response_times' => $rows->pluck('response_time_ms')->values()->all(),
            'statuses' => $rows->pluck('status')->values()->all(),
        ];
    }

    /**
     * @param  list<int>  $monitorIds
     * @return array{labels: list<string>, response_times: list<int|null>, statuses: list<string>}
     */
    private function hourlyChartData(array $monitorIds, CarbonInterface $since, string $displayFormat, string $tz): array
    {
        $aggregated = MonitorCheckAggregate::query()
            ->whereIn('monitor_id', $monitorIds)
            ->where('bucket_type', 'hourly')
            ->where('bucket_start', '>=', $since)
            ->selectRaw($this->bucketExpression('bucket_start', 'hour').' as bucket')
            ->selectRaw('ROUND(AVG(avg_response_time_ms)) as avg_ms')
            ->selectRaw('SUM(down_checks) as down_count')
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get()
            ->keyBy('bucket');

        $rawBuckets = MonitorCheck::query()
            ->whereIn('monitor_id', $monitorIds)
            ->where('checked_at', '>=', $since)
            ->selectRaw($this->bucketExpression('checked_at', 'hour').' as bucket')
            ->selectRaw("ROUND(AVG({$this->upResponseTime()})) as avg_ms")
            ->selectRaw("SUM(CASE WHEN status = 'down' THEN 1 ELSE 0 END) as down_count")
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get()
            ->keyBy('bucket');

        $merged = $aggregated->map(fn ($r) => [
            'bucket' => $r->bucket,
            'avg_ms' => (int) $r->avg_ms,
            'down' => $r->down_count > 0,
        ]);

        foreach ($rawBuckets as $key => $bucket) {
            $merged[$key] = [
                'bucket' => $bucket->bucket,
                'avg_ms' => $bucket->avg_ms ? (int) $bucket->avg_ms : null,
                'down' => $bucket->down_count > 0,
            ];
        }

        $merged = $merged->sortBy('bucket')->values();

        if ($merged->isEmpty()) {
            return self::EMPTY_CHART;
        }

        return [
            'labels' => $merged->map(fn ($r) => Carbon::parse($r['bucket'])->setTimezone($tz)->format($displayFormat))->all(),
            'response_times' => $merged->map(fn ($r) => $r['avg_ms'])->all(),
            'statuses' => $merged->map(fn ($r) => $r['down'] ? 'down' : 'up')->all(),
        ];
    }

    /**
     * @param  list<int>  $monitorIds
     * @return array{labels: list<string>, response_times: list<int|null>, statuses: list<string>}
     */
    private function aggregateChartData(array $monitorIds, CarbonInterface $since, string $bucketType, string $displayFormat, string $tz): array
    {
        $granularity = $bucketType === 'daily' ? 'day' : 'hour';

        $aggregated = MonitorCheckAggregate::query()
            ->whereIn('monitor_id', $monitorIds)
            ->where('bucket_type', $bucketType)
            ->where('bucket_start', '>=', $since)
            ->selectRaw($this->bucketExpression('bucket_start', $granularity).' as bucket')
            ->selectRaw('ROUND(AVG(avg_response_time_ms)) as avg_ms')
            ->selectRaw('SUM(down_checks) as down_count')
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get()
            ->keyBy('bucket');

        $rawBuckets = MonitorCheck::query()
            ->whereIn('monitor_id', $monitorIds)
            ->where('checked_at', '>=', $since)
            ->selectRaw($this->bucketExpression('checked_at', $granularity).' as bucket')
            ->selectRaw("ROUND(AVG({$this->upResponseTime()})) as avg_ms")
            ->selectRaw("SUM(CASE WHEN status = 'down' THEN 1 ELSE 0 END) as down_count")
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get()
            ->keyBy('bucket');

        $merged = $aggregated->map(fn ($r) => [
            'bucket' => $r->bucket,
            'avg_ms' => (int) $r->avg_ms,
            'down' => $r->down_count > 0,
        ]);

        foreach ($rawBuckets as $key => $bucket) {
            $merged[$key] = [
                'bucket' => $bucket->bucket,
                'avg_ms' => $bucket->avg_ms ? (int) $bucket->avg_ms : null,
                'down' => $bucket->down_count > 0,
            ];
        }

        $merged = $merged->sortBy('bucket')->values();

        if ($merged->isEmpty()) {
            return self::EMPTY_CHART;
        }

        return [
            'labels' => $merged->map(fn ($r) => Carbon::parse($r['bucket'])->setTimezone($tz)->format($displayFormat))->all(),
            'response_times' => $merged->map(fn ($r) => $r['avg_ms'])->all(),
            'statuses' => $merged->map(fn ($r) => $r['down'] ? 'down' : 'up')->all(),
        ];
    }

    /**
     * @param  list<int>  $monitorIds
     * @return array{uptime: float, total: int, up: int, down: int, avg_ms: int, min_ms: int, max_ms: int, incidents?: int}
     */
    private function rawStats(array $monitorIds, CarbonInterface $since, bool $includeIncidents): array
    {
        $row = MonitorCheck::whereIn('monitor_id', $monitorIds)
            ->where('checked_at', '>=', $since)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status = 'up' THEN 1 ELSE 0 END) as up")
            ->selectRaw("SUM(CASE WHEN status = 'down' THEN 1 ELSE 0 END) as down")
            ->selectRaw("ROUND(AVG({$this->upResponseTime()})) as avg_ms")
            ->selectRaw("MIN({$this->upResponseTime()}) as min_ms")
            ->selectRaw("MAX({$this->upResponseTime()}) as max_ms")
            ->first();

        $result = [
            'uptime' => $row->total > 0 ? round(($row->up / $row->total) * 100, 2) : 100.0,
            'total' => (int) $row->total,
            'up' => (int) $row->up,
            'down' => (int) $row->down,
            'avg_ms' => (int) ($row->avg_ms ?? 0),
            'min_ms' => (int) ($row->min_ms ?? 0),
            'max_ms' => (int) ($row->max_ms ?? 0),
        ];

        if ($includeIncidents) {
            $result['incidents'] = MonitorIncident::whereIn('monitor_id', $monitorIds)
                ->where('started_at', '>=', $since)
                ->count();
        }

        return $result;
    }

    /**
     * @param  list<int>  $monitorIds
     * @return array{uptime: float, total: int, up: int, down: int, avg_ms: int, min_ms: int, max_ms: int, incidents?: int}
     */
    private function combinedStats(array $monitorIds, string $period, CarbonInterface $since, bool $includeIncidents): array
    {
        $bucketType = in_array($period, ['30d', '365d']) ? 'daily' : 'hourly';

        $aggStats = MonitorCheckAggregate::query()
            ->whereIn('monitor_id', $monitorIds)
            ->where('bucket_type', $bucketType)
            ->where('bucket_start', '>=', $since)
            ->selectRaw('SUM(total_checks) as total')
            ->selectRaw('SUM(up_checks) as up')
            ->selectRaw('SUM(down_checks) as down')
            ->selectRaw('MIN(min_response_time_ms) as min_ms')
            ->selectRaw('MAX(max_response_time_ms) as max_ms')
            ->selectRaw('SUM(avg_response_time_ms * up_checks) as weighted_sum')
            ->first();

        $rawStats = MonitorCheck::query()
            ->whereIn('monitor_id', $monitorIds)
            ->where('checked_at', '>=', $since)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status = 'up' THEN 1 ELSE 0 END) as up")
            ->selectRaw("SUM(CASE WHEN status = 'down' THEN 1 ELSE 0 END) as down")
            ->selectRaw("MIN({$this->upResponseTime()}) as min_ms")
            ->selectRaw("MAX({$this->upResponseTime()}) as max_ms")
            ->selectRaw("SUM(COALESCE({$this->upResponseTime()}, 0)) as sum_ms")
            ->first();

        $totalChecks = ($aggStats->total ?? 0) + ($rawStats->total ?? 0);
        $totalUp = ($aggStats->up ?? 0) + ($rawStats->up ?? 0);
        $totalDown = ($aggStats->down ?? 0) + ($rawStats->down ?? 0);
        $weightedSum = ($aggStats->weighted_sum ?? 0) + ($rawStats->sum_ms ?? 0);

        $minMs = min(
            $aggStats->min_ms ?? PHP_INT_MAX,
            $rawStats->min_ms ?? PHP_INT_MAX,
        );
        $maxMs = max($aggStats->max_ms ?? 0, $rawStats->max_ms ?? 0);

        $result = [
            'uptime' => $totalChecks > 0 ? round(($totalUp / $totalChecks) * 100, 2) : 100.0,
            'total' => $totalChecks,
            'up' => $totalUp,
            'down' => $totalDown,
            'avg_ms' => $totalUp > 0 ? (int) round($weightedSum / $totalUp) : 0,
            'min_ms' => $minMs === PHP_INT_MAX ? 0 : (int) $minMs,
            'max_ms' => (int) $maxMs,
        ];

        if ($includeIncidents) {
            $result['incidents'] = MonitorIncident::whereIn('monitor_id', $monitorIds)
                ->where('started_at', '>=', $since)
                ->count();
        }

        return $result;
    }
}
