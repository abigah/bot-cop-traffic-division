<?php

namespace Abigah\BotCopTrafficDivision\Livewire\Concerns;

use Abigah\BotCopTrafficDivision\Enums\HeartbeatKind;
use Abigah\BotCopTrafficDivision\Models\MonitorHeartbeat;
use Abigah\BotCopTrafficDivision\Services\MonitorQuery;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Declaring the work a site expects to happen.
 *
 * A heartbeat is periodic — a job that should run hourly — and is missing when
 * nothing pings within its interval plus grace. An event is bounded: it starts,
 * it finishes, and it has timed out when a start is not followed by a finish.
 * Declaring one is the whole of the setup; the job then pings a URL from inside
 * `handle()` and stops being invisible when it stops running.
 */
trait ManagesHeartbeats
{
    public bool $showHeartbeatForm = false;

    public ?int $editingHeartbeatId = null;

    /** The token, shown once after it is minted or rotated. */
    public ?string $revealedToken = null;

    /** @var array<string, mixed> */
    public array $heartbeatForm = [];

    public function newHeartbeat(): void
    {
        $this->editingHeartbeatId = null;
        $this->revealedToken = null;
        $this->heartbeatForm = [
            'name' => '',
            'kind' => HeartbeatKind::HEARTBEAT->value,
            'interval_minutes' => 60,
            'grace_minutes' => (int) config('monitoring.heartbeats.default_grace_minutes', 10),
            'timeout_minutes' => (int) config('monitoring.heartbeats.default_timeout_minutes', 30),
            'job_class' => '',
            'enabled' => true,
            'tracks_deployments' => false,
        ];
        $this->resetValidation();
        $this->showHeartbeatForm = true;
    }

    public function editHeartbeat(int $heartbeat): void
    {
        $model = $this->findHeartbeat($heartbeat);

        if ($model === null) {
            return;
        }

        $this->editingHeartbeatId = $model->getKey();
        $this->revealedToken = null;
        $this->heartbeatForm = [
            'name' => (string) $model->name,
            'kind' => $model->kind->value,
            'interval_minutes' => $model->interval_minutes ?? 60,
            'grace_minutes' => $model->graceMinutes(),
            'timeout_minutes' => $model->timeoutMinutes(),
            'job_class' => (string) ($model->job_class ?? ''),
            'enabled' => (bool) $model->enabled,
            'tracks_deployments' => (bool) $model->tracks_deployments,
        ];
        $this->resetValidation();
        $this->showHeartbeatForm = true;
    }

    public function saveHeartbeat(): void
    {
        $data = $this->validate($this->heartbeatRules())['heartbeatForm'];

        $isEvent = $data['kind'] === HeartbeatKind::EVENT->value;

        $attributes = [
            'name' => $data['name'],
            'kind' => $data['kind'],
            'job_class' => $data['job_class'] ?: null,
            'enabled' => (bool) $data['enabled'],

            // Only an event can track a deploy, and a site has one deploy
            // target, so declaring a second one takes the flag off the first.
            'tracks_deployments' => $isEvent && (bool) ($data['tracks_deployments'] ?? false),

            // Each kind carries only its own fields; the manifest schema
            // refuses a payload that mixes them.
            'interval_minutes' => $isEvent ? null : (int) $data['interval_minutes'],
            'grace_minutes' => $isEvent ? null : (int) $data['grace_minutes'],
            'timeout_minutes' => $isEvent ? (int) $data['timeout_minutes'] : null,
        ];

        if ($attributes['tracks_deployments']) {
            $this->clearOtherDeploymentTrackers();
        }

        if ($this->editingHeartbeatId !== null) {
            $this->findHeartbeat($this->editingHeartbeatId)?->forceFill($attributes)->save();
        } else {
            $site = MonitorQuery::forCurrentOwner()->findSite($this->siteId);

            $heartbeat = MonitorHeartbeat::create([
                ...$attributes,
                'site_id' => $site->getKey(),
                'token' => Str::random(48),
            ]);

            // Shown once. It is in the manifest and in the database, so this is
            // a convenience rather than the only copy.
            $this->revealedToken = $heartbeat->token;
        }

        $this->showHeartbeatForm = false;
        $this->editingHeartbeatId = null;
    }

    /**
     * Mint a new token for a heartbeat.
     *
     * Pings using the old one fail from this moment until every prober has
     * pulled a fresh manifest and the job itself has been deployed with the new
     * token — which is why it says so on the button rather than in a doc nobody
     * reads.
     *
     * Nobody is paged for that gap. The heartbeat goes on recording that it is
     * missing, and stays quiet until the first ping proves the new token has
     * arrived where it needed to go.
     */
    public function rotateHeartbeatToken(int $heartbeat): void
    {
        $model = $this->findHeartbeat($heartbeat);

        if ($model === null) {
            return;
        }

        $model->forceFill([
            'token' => Str::random(48),

            // Until a ping arrives with this one, a missed ping is this
            // application's own doing rather than the job's.
            'token_rotated_at' => now(),
        ])->save();

        $this->revealedToken = $model->token;
    }

    public function deleteHeartbeat(int $heartbeat): void
    {
        $this->findHeartbeat($heartbeat)?->delete();
    }

    public function pingUrlFor(MonitorHeartbeat $heartbeat): string
    {
        return route('monitoring.ping', ['token' => $heartbeat->token]);
    }

    /** A site has one deploy target, so the flag moves rather than multiplying. */
    protected function clearOtherDeploymentTrackers(): void
    {
        MonitorHeartbeat::query()
            ->where('site_id', $this->siteId)
            ->when($this->editingHeartbeatId, fn ($query) => $query->whereKeyNot($this->editingHeartbeatId))
            ->update(['tracks_deployments' => false]);
    }

    protected function findHeartbeat(int $heartbeat): ?MonitorHeartbeat
    {
        return MonitorQuery::forCurrentOwner()
            ->heartbeats()
            ->whereKey($heartbeat)
            ->where('site_id', $this->siteId)
            ->first();
    }

    /** @return array<string, mixed> */
    protected function heartbeatRules(): array
    {
        $isEvent = ($this->heartbeatForm['kind'] ?? '') === HeartbeatKind::EVENT->value;

        return [
            'heartbeatForm.name' => ['required', 'string', 'max:255'],
            'heartbeatForm.kind' => ['required', Rule::in(['heartbeat', 'event'])],
            'heartbeatForm.interval_minutes' => [$isEvent ? 'nullable' : 'required', 'integer', 'min:1', 'max:10080'],
            'heartbeatForm.grace_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'heartbeatForm.timeout_minutes' => [$isEvent ? 'required' : 'nullable', 'integer', 'min:1', 'max:10080'],
            'heartbeatForm.job_class' => ['nullable', 'string', 'max:255'],
            'heartbeatForm.enabled' => ['boolean'],
            'heartbeatForm.tracks_deployments' => ['boolean'],
        ];
    }
}
