<?php

namespace Abigah\BotCopTrafficDivision\Http\Controllers;

use Abigah\BotCopTrafficDivision\Facades\Monitoring;
use Abigah\BotCopTrafficDivision\Models\Monitor;
use Abigah\BotCopTrafficDivision\Models\MonitorCheckGap;
use Abigah\BotCopTrafficDivision\Models\MonitorProberUsage;
use Abigah\BotCopTrafficDivision\Support\AgreementRule;
use Abigah\BotCopTrafficDivision\Support\CheckResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * `POST /monitoring/results`
 *
 * Raw results in, conclusions drawn here. The prober performed the checks and
 * knows nothing about thresholds, deployments, mutes or incidents; this replays
 * what it saw, in the order it saw it, through the same seam a local check
 * would have used.
 *
 * The response carries the highest check id accepted so the prober can advance
 * its cursor and stop resending what has landed.
 */
class ResultsController
{
    public function __invoke(Request $request, AgreementRule $agreement): JsonResponse
    {
        $payload = $request->validate([
            'schema' => ['required', 'integer'],
            'prober.id' => ['required', 'string'],
            'prober.location' => ['required', 'string'],
            'results' => ['present', 'array'],
            'results.*.check_id' => ['required', 'string', 'size:26'],
            'results.*.monitor_id' => ['required'],
            'results.*.checked_at' => ['required', 'date'],
            'results.*.up' => ['required', 'boolean'],
            'results.*.response_time_ms' => ['nullable', 'integer'],
            'results.*.status_code' => ['nullable', 'integer'],
            'results.*.failure_reason' => ['nullable', 'string'],
            'results.*.served_from_cache' => ['nullable', 'boolean'],
            'dropped' => ['sometimes', 'array'],
            'clamped' => ['sometimes', 'array'],
            'usage' => ['sometimes', 'array'],
        ]);

        $prober = $payload['prober'];
        $monitors = $this->monitorsFor($payload['results']);

        /*
         | Ordered by when the check was taken, not by when it arrived. A batch
         | is history: replayed out of order, a recovery could land before the
         | failure it recovered from and the threshold would count the outage
         | backwards.
         |
         | check_id breaks ties. It is a ULID for exactly this reason: two
         | checks in the same second still have one true order.
         */
        $results = collect($payload['results'])
            ->sortBy([
                fn (array $row) => Carbon::parse($row['checked_at'])->getTimestamp(),
                fn (array $row) => $row['check_id'],
            ])
            ->values();

        $accepted = 0;
        $acceptedThrough = null;

        foreach ($results as $row) {
            $monitor = $monitors->get((string) $row['monitor_id']);

            if ($monitor === null) {
                // A monitor deleted since the manifest was pulled. Nothing to
                // replay it into, and the next manifest closes the gap.
                continue;
            }

            $result = CheckResult::fromResultsPayload($row, $prober);

            if ($agreement->isCorroborated($monitor, $result)) {
                $monitor->recordUptimeResult($result);
            } else {
                $monitor->recordDisagreement($result);
            }

            $accepted++;

            // The highest id accepted, which with ULIDs is the last one in
            // order — the cursor the prober resumes from.
            if ($acceptedThrough === null || strcmp($result->checkId, $acceptedThrough) > 0) {
                $acceptedThrough = $result->checkId;
            }
        }

        $this->recordGaps($payload['dropped'] ?? [], $monitors, $prober['id']);
        $this->recordClamps($payload['clamped'] ?? [], $monitors);
        $this->recordUsage($payload['usage'] ?? null, $prober['id']);

        return response()->json([
            'schema' => Monitoring::schemaVersion(),
            'accepted' => $accepted,
            'accepted_through' => $acceptedThrough,
        ]);
    }

    /**
     * Every monitor named in the batch, fetched once and keyed by the string
     * form of its id — ids travel as strings on the wire, and comparing those
     * to integers is how a lookup misses silently.
     *
     * @param  array<int, array<string, mixed>>  $results
     * @return Collection<string, Monitor>
     */
    protected function monitorsFor(array $results): Collection
    {
        return Monitor::query()
            ->whereIn('id', collect($results)->pluck('monitor_id')->unique()->all())
            ->get()
            ->keyBy(fn (Monitor $monitor) => (string) $monitor->getKey());
    }

    /**
     * @param  array<int, array<string, mixed>>  $dropped
     * @param  Collection<string, Monitor>  $monitors
     */
    protected function recordGaps(array $dropped, Collection $monitors, string $proberId): void
    {
        foreach ($dropped as $gap) {
            $monitor = $monitors->get((string) ($gap['monitor_id'] ?? ''))
                ?? Monitor::find($gap['monitor_id'] ?? null);

            if ($monitor === null) {
                continue;
            }

            MonitorCheckGap::create([
                'monitor_id' => $monitor->getKey(),
                'prober_id' => $proberId,
                'dropped_count' => (int) $gap['count'],
                'oldest_at' => Carbon::parse($gap['oldest']),
                'newest_at' => Carbon::parse($gap['newest']),
                'reported_at' => Carbon::now(),
            ]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $clamped
     * @param  Collection<string, Monitor>  $monitors
     */
    protected function recordClamps(array $clamped, Collection $monitors): void
    {
        foreach ($clamped as $clamp) {
            $monitor = $monitors->get((string) ($clamp['monitor_id'] ?? ''))
                ?? Monitor::find($clamp['monitor_id'] ?? null);

            $monitor?->forceFill([
                'clamped_interval_minutes' => (int) $clamp['effective_interval_minutes'],
                'clamped_reason' => $clamp['reason'] ?? null,
            ])->save();
        }
    }

    /** @param  array<string, mixed>|null  $usage */
    protected function recordUsage(?array $usage, string $proberId): void
    {
        if ($usage === null) {
            return;
        }

        MonitorProberUsage::updateOrCreate(
            ['prober_id' => $proberId, 'month' => $usage['month']],
            ['checks' => (int) $usage['checks'], 'pings' => (int) $usage['pings']],
        );
    }
}
