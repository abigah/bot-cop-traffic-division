<?php

namespace Abigah\BotCopTrafficDivision\Support;

use Abigah\BotCopTrafficDivision\Enums\HeartbeatKind;
use Abigah\BotCopTrafficDivision\Facades\Monitoring;
use Abigah\BotCopTrafficDivision\Models\Monitor;
use Abigah\BotCopTrafficDivision\Models\MonitorHeartbeat;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * This application's declaration of what it wants checked.
 *
 * Ownership and tenancy are invisible here: a manifest is a list of sites, and
 * the ids in it are this application's own — they come back on every result and
 * verdict, which is the only reason a prober needs them.
 *
 * Two shapes are load-bearing and easy to get wrong in PHP. Ids are strings on
 * the wire even though they are integers here, because an integer/string
 * mismatch between PHP and TypeScript surfaces as a silent lookup miss rather
 * than an error. And an empty header map has to serialise as `{}`, not `[]` —
 * PHP cannot tell the two apart, and the schema refuses the second.
 */
class ManifestBuilder
{
    /** @return array<string, mixed> */
    public function build(): array
    {
        return [
            'schema' => Monitoring::schemaVersion(),
            'tenant' => (string) config('monitoring.tenant'),
            'generated_at' => $this->timestamp(Carbon::now()),
            'sites' => $this->sites(),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    protected function sites(): array
    {
        $siteModel = Monitoring::siteModel();

        return $siteModel::query()
            ->get()
            ->map(fn (Model $site): array => [
                'id' => (string) $site->getKey(),
                'name' => (string) ($site->name ?? "Site {$site->getKey()}"),
                'hibernates' => (bool) $site->hibernates,
                'ingest_token' => $site->ingestToken(),
                'monitors' => $this->monitors($site),
                'heartbeats' => $this->heartbeats($site),
            ])
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    protected function monitors(Model $site): array
    {
        return $site->monitors()
            ->orderBy('id')
            ->get()
            ->map(fn (Monitor $monitor): array => [
                'id' => (string) $monitor->getKey(),
                'url' => (string) $monitor->url,
                'method' => strtoupper((string) ($monitor->uptime_check_method ?: 'get')),

                // Cast so an empty map is an object on the wire, not a list.
                'headers' => (object) ($monitor->uptime_check_additional_headers ?? []),

                'payload' => $monitor->uptime_check_payload ?: null,
                'look_for_string' => $monitor->look_for_string ?: null,
                'fail_for_string' => $monitor->fail_for_string ?: null,

                /*
                 | The interval this application asked for, not the one it
                 | expects to get. The prober clamps to its tenant minimum and
                 | to 60 minutes for a hibernating site, and reports the clamp
                 | back — which is how a request for faster checking than the
                 | tier allows becomes visible rather than silently ignored.
                 */
                'interval_minutes' => (int) $monitor->uptime_check_interval_in_minutes,

                'critical' => (bool) $monitor->critical,
                'enabled' => (bool) $monitor->uptime_check_enabled,
            ])
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    protected function heartbeats(Model $site): array
    {
        return $site->heartbeats()
            ->enabled()
            ->orderBy('id')
            ->get()
            ->map(function (MonitorHeartbeat $heartbeat): array {
                $common = [
                    'id' => (string) $heartbeat->getKey(),
                    'kind' => $heartbeat->kind->value,
                    'token' => $heartbeat->token,
                ];

                // The two kinds are judged differently and carry different
                // fields; the schema refuses a payload that mixes them.
                return $heartbeat->kind === HeartbeatKind::EVENT
                    ? [...$common, 'timeout_minutes' => $heartbeat->timeoutMinutes()]
                    : [
                        ...$common,
                        'interval_minutes' => (int) $heartbeat->interval_minutes,
                        'grace_minutes' => $heartbeat->graceMinutes(),
                    ];
            })
            ->values()
            ->all();
    }

    /** UTC with a Z suffix and no offset: one form, so two implementations that both "handle ISO-8601" cannot disagree. */
    protected function timestamp(Carbon $moment): string
    {
        return $moment->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
