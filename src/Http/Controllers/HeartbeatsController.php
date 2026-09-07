<?php

namespace Abigah\BotCopTrafficDivision\Http\Controllers;

use Abigah\BotCopTrafficDivision\Enums\HeartbeatStatus;
use Abigah\BotCopTrafficDivision\Events\HeartbeatMissed;
use Abigah\BotCopTrafficDivision\Events\HeartbeatRecovered;
use Abigah\BotCopTrafficDivision\Facades\Monitoring;
use Abigah\BotCopTrafficDivision\Models\MonitorHeartbeat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * `POST /monitoring/heartbeats`
 *
 * Verdicts about work that pings in rather than being checked from outside.
 *
 * The judging happens on the prober because that is where the last ping time
 * lives, but the judgement is narrow: it says a job is late, not that anybody
 * should be woken. Whether a late job is worth a notification is decided here,
 * through the same subscriber and the same preferences as an outage, so a mute
 * covers both.
 */
class HeartbeatsController
{
    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'schema' => ['required', 'integer'],
            'prober.id' => ['required', 'string'],
            'prober.location' => ['required', 'string'],
            'verdicts' => ['present', 'array'],
            'verdicts.*.heartbeat_id' => ['required'],
            'verdicts.*.site_id' => ['required'],
            'verdicts.*.status' => ['required', 'in:missing,failed,timed_out,recovered'],
            'verdicts.*.observed_at' => ['required', 'date'],
            'verdicts.*.last_ping_at' => ['nullable', 'date'],
            'verdicts.*.message' => ['nullable', 'string'],
            'suppressed' => ['sometimes', 'array'],
            'suppressed.*.heartbeat_id' => ['required'],
            'suppressed.*.since' => ['required', 'date'],
        ]);

        $applied = 0;

        foreach ($payload['verdicts'] as $verdict) {
            $heartbeat = MonitorHeartbeat::find($verdict['heartbeat_id']);

            if ($heartbeat === null) {
                continue;
            }

            $verdict['status'] === 'recovered'
                ? $this->recover($heartbeat, $verdict)
                : $this->fail($heartbeat, $verdict);

            $applied++;
        }

        $this->recordSuppressed($payload['suppressed'] ?? []);

        return response()->json([
            'schema' => Monitoring::schemaVersion(),
            'applied' => $applied,
        ]);
    }

    /** @param  array<string, mixed>  $verdict */
    protected function fail(MonitorHeartbeat $heartbeat, array $verdict): void
    {
        $status = HeartbeatStatus::from($verdict['status']);

        $alreadyAlerting = $heartbeat->status->isAlerting();

        $heartbeat->forceFill([
            'status' => $status,
            'last_message' => $verdict['message'] ?? null,
            'last_ping_at' => isset($verdict['last_ping_at'])
                ? Carbon::parse($verdict['last_ping_at'])
                : $heartbeat->last_ping_at,
        ])->save();

        /*
         | A verdict is repeated on every cycle while the job stays missing, so
         | only the transition into that state is news — and not even that while
         | the heartbeat is waiting for its first ping since its token changed.
         | A prober cannot know that happened; this side can, because it is the
         | side that did it.
         */
        if (! $alreadyAlerting && ! $heartbeat->awaitingFirstPingSinceRotation()) {
            event(new HeartbeatMissed($heartbeat, $status, $verdict['message'] ?? null));
        }
    }

    /** @param  array<string, mixed>  $verdict */
    protected function recover(MonitorHeartbeat $heartbeat, array $verdict): void
    {
        $wasAlerting = $heartbeat->status->isAlerting();

        $heartbeat->forceFill([
            'status' => HeartbeatStatus::OK,
            'last_ping_at' => isset($verdict['last_ping_at'])
                ? Carbon::parse($verdict['last_ping_at'])
                : Carbon::parse($verdict['observed_at']),
            'last_message' => $verdict['message'] ?? null,
            'suppressed_since' => null,
        ])->save();

        if ($wasAlerting) {
            event(new HeartbeatRecovered($heartbeat));
        }
    }

    /**
     * Heartbeats that went missing while their site was already down.
     *
     * These are not verdicts and nobody is paged for them: the outage is the
     * story, and these are what was also affected by it. Recording when the
     * swallowing started is what lets the incident show them as context.
     *
     * @param  array<int, array<string, mixed>>  $suppressed
     */
    protected function recordSuppressed(array $suppressed): void
    {
        foreach ($suppressed as $entry) {
            MonitorHeartbeat::query()
                ->whereKey($entry['heartbeat_id'])
                ->whereNull('suppressed_since')
                ->update(['suppressed_since' => Carbon::parse($entry['since'])]);
        }
    }
}
