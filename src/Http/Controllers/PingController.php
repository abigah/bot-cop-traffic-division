<?php

namespace Abigah\BotCopTrafficDivision\Http\Controllers;

use Abigah\BotCopTrafficDivision\Enums\HeartbeatKind;
use Abigah\BotCopTrafficDivision\Enums\HeartbeatStatus;
use Abigah\BotCopTrafficDivision\Events\HeartbeatMissed;
use Abigah\BotCopTrafficDivision\Events\HeartbeatRecovered;
use Abigah\BotCopTrafficDivision\Models\MonitorHeartbeat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * `GET|POST /monitoring/ping/{token}[/start|/fail]`
 *
 * Where a job says it ran.
 *
 * Not signed. These come from arbitrary site code — a queue worker, a deploy
 * script — which cannot be trusted to hold a tenant secret, and the token
 * grants nothing but the ability to say that this one heartbeat fired. They are
 * throttled instead.
 *
 * In `remote` mode a prober owns this endpoint and the client pings that. This
 * is the same endpoint on the hub, for `local` mode, so heartbeats work on an
 * extranet that has no prober yet — and so the client package has somewhere to
 * land before one exists.
 *
 * Everything here answers immediately and never throws. A monitoring ping must
 * never be the reason a job, a deploy or a request fails.
 */
class PingController
{
    public function __invoke(Request $request, string $token, ?string $action = null): JsonResponse
    {
        $heartbeat = MonitorHeartbeat::query()->where('token', $token)->first();

        if ($heartbeat === null || ! $heartbeat->enabled) {
            return response()->json(['status' => 'ignored'], 404);
        }

        $now = Carbon::now();
        $message = $request->input('message');

        match ($action) {
            'start' => $this->start($heartbeat, $now, $message),
            'fail' => $this->fail($heartbeat, $now, $message),
            default => $this->pinged($heartbeat, $now, $message),
        };

        return response()->json(['status' => 'ok'], 202);
    }

    protected function pinged(MonitorHeartbeat $heartbeat, Carbon $now, ?string $message): void
    {
        $wasAlerting = $heartbeat->status->isAlerting();

        $heartbeat->forceFill([
            'status' => HeartbeatStatus::OK,
            'last_ping_at' => $now,
            'last_message' => $message,

            // A bare ping on an event is it reporting that it finished.
            'last_finish_at' => $heartbeat->kind === HeartbeatKind::EVENT ? $now : $heartbeat->last_finish_at,

            // Whatever an outage was swallowing, it is not swallowing it now.
            'suppressed_since' => null,

            // The new token reached the job. Alerting resumes.
            'token_rotated_at' => null,
        ])->save();

        if ($wasAlerting) {
            event(new HeartbeatRecovered($heartbeat));
        }
    }

    protected function start(MonitorHeartbeat $heartbeat, Carbon $now, ?string $message): void
    {
        $heartbeat->forceFill([
            'status' => HeartbeatStatus::RUNNING,
            'last_start_at' => $now,
            'last_ping_at' => $now,
            'last_message' => $message,
            'token_rotated_at' => null,
        ])->save();
    }

    /**
     * An explicit failure needs no probe and no grace: the site plainly
     * answered, because it just told us something broke.
     */
    protected function fail(MonitorHeartbeat $heartbeat, Carbon $now, ?string $message): void
    {
        $alreadyAlerting = $heartbeat->status->isAlerting();

        $heartbeat->forceFill([
            'status' => HeartbeatStatus::FAILED,
            'last_ping_at' => $now,
            'last_message' => $message,

            // An explicit failure proves the token works as well as a success
            // does: something reached here carrying it.
            'token_rotated_at' => null,
        ])->save();

        if (! $alreadyAlerting) {
            event(new HeartbeatMissed($heartbeat, HeartbeatStatus::FAILED, $message));
        }
    }
}
