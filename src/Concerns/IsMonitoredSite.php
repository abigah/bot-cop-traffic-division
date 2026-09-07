<?php

namespace Abigah\BotCopTrafficDivision\Concerns;

use Abigah\BotCopTrafficDivision\Enums\HeartbeatKind;
use Abigah\BotCopTrafficDivision\Enums\HeartbeatStatus;
use Abigah\BotCopTrafficDivision\Enums\UptimeStatus;
use Abigah\BotCopTrafficDivision\Facades\Monitoring;
use Abigah\BotCopTrafficDivision\Jobs\PingHeartbeat;
use Abigah\BotCopTrafficDivision\Models\Monitor;
use Abigah\BotCopTrafficDivision\Models\MonitorDeployment;
use Abigah\BotCopTrafficDivision\Models\MonitorHeartbeat;
use Abigah\BotCopTrafficDivision\Models\MonitorIncident;
use Abigah\BotCopTrafficDivision\Models\MonitorSiteException;
use Abigah\BotCopTrafficDivision\Models\MonitorSiteSetting;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Makes a host's own model a monitored site.
 *
 * A site is the unit of incidents, deployment windows and heartbeat dependency.
 * The host supplies the model — one belongs to a Client, another to a
 * Project — and adds this trait, which brings the relations, the deployment
 * window and everything the package needs to know about a site that the host's
 * table has no reason to store.
 *
 * Nothing here alters the host's table. Package-owned per-site state lives in
 * `monitor_site_settings`, one row per site, created on demand.
 */
trait IsMonitoredSite
{
    public function monitors(): HasMany
    {
        return $this->hasMany(Monitor::class, 'site_id');
    }

    public function heartbeats(): HasMany
    {
        return $this->hasMany(MonitorHeartbeat::class, 'site_id');
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(MonitorIncident::class, 'site_id');
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(MonitorDeployment::class, 'site_id');
    }

    public function siteExceptions(): HasMany
    {
        return $this->hasMany(MonitorSiteException::class, 'site_id');
    }

    public function monitoringSettings(): HasOne
    {
        return $this->hasOne(MonitorSiteSetting::class, 'site_id');
    }

    /**
     * Whether this site is only awake when something wakes it. Every uptime
     * check on a hibernating site costs a cold start, so its monitors are
     * floored at a much longer interval and heartbeats cover the gap.
     *
     * A host model with its own `hibernates` column keeps it; otherwise the
     * package's settings row answers.
     */
    protected function hibernates(): Attribute
    {
        return Attribute::get(fn (mixed $value): bool => $value !== null
            ? (bool) $value
            : (bool) $this->monitoringSettings?->hibernates);
    }

    /**
     * The token this site's own code uses to ping heartbeats and report
     * exceptions, minted on first use.
     */
    public function ingestToken(): string
    {
        return $this->monitoringSettings()->firstOrCreate([], [
            'ingest_token' => Str::random(48),
        ])->ingest_token;
    }

    /**
     * The monitors that decide whether this site is up. Non-critical monitors
     * record their own checks and open their own incidents, but they never
     * suppress a heartbeat verdict.
     *
     * @return HasMany<Monitor, $this>
     */
    public function criticalMonitors(): HasMany
    {
        return $this->monitors()->where('critical', true);
    }

    /**
     * "The site is down" — one of its critical monitors is failing. This is the
     * question the heartbeat site rule asks before it judges a missed job, and
     * the one the prober's emergency rule asks before it wakes anybody.
     */
    public function isDown(): bool
    {
        return $this->criticalMonitors()
            ->where('uptime_check_enabled', true)
            ->where('uptime_status', UptimeStatus::DOWN->value)
            ->exists();
    }

    /**
     * The outage a missed heartbeat or a folded exception attaches itself to:
     * the earliest ongoing incident among this site's critical monitors.
     */
    public function openIncident(): ?MonitorIncident
    {
        return MonitorIncident::query()
            ->whereIn('monitor_id', $this->criticalMonitors()->select('id'))
            ->ongoing()
            ->oldest('started_at')
            ->first();
    }

    /**
     * The site's status as the heartbeat site rule wants it: up, down, or too
     * old to be evidence about now.
     *
     * A site with no critical monitor to speak for it is stale rather than up —
     * nothing has established anything, and guessing "up" here is how a rule
     * that exists to prevent false pages starts causing them.
     */
    public function criticalStatus(): string
    {
        $monitors = $this->criticalMonitors()->where('uptime_check_enabled', true)->get();

        if ($monitors->isEmpty()) {
            return 'stale';
        }

        if ($monitors->contains(fn (Monitor $monitor) => $monitor->isDown())) {
            return 'down';
        }

        if ($monitors->contains(fn (Monitor $monitor) => ! $monitor->isUp() || $monitor->uptime_last_check_date === null)) {
            return 'stale';
        }

        return 'up';
    }

    /**
     * When that status was established — the *oldest* of the critical monitors,
     * since the site is only as freshly known as its least recently checked
     * part.
     */
    public function criticalCheckedAt(): ?Carbon
    {
        $oldest = $this->criticalMonitors()
            ->where('uptime_check_enabled', true)
            ->min('uptime_last_check_date');

        return $oldest === null ? null : Carbon::parse($oldest);
    }

    /** When the site's critical monitors first failed, if they are failing now. */
    public function downSince(): ?Carbon
    {
        return $this->openIncident()?->started_at;
    }

    /**
     * When they last came back. Null when the site has never been down, which
     * is what tells the rule there is no recovery to wait out.
     */
    public function recoveredAt(): ?Carbon
    {
        if ($this->criticalStatus() === 'down') {
            return null;
        }

        return MonitorIncident::query()
            ->whereIn('monitor_id', $this->criticalMonitors()->select('id'))
            ->whereNotNull('resolved_at')
            ->latest('resolved_at')
            ->value('resolved_at');
    }

    /**
     * The site as the rule reads it. The shape is the conformance kit's, so the
     * fixture cases and the live sweep are answering the same question.
     *
     * @return array{critical_status: string, critical_checked_at: string|null, down_since: string|null, recovered_at: string|null}
     */
    public function siteRuleState(): array
    {
        return [
            'critical_status' => $this->criticalStatus(),
            'critical_checked_at' => $this->criticalCheckedAt()?->toIso8601ZuluString(),
            'down_since' => $this->downSince()?->toIso8601ZuluString(),
            'recovered_at' => $this->recoveredAt()?->toIso8601ZuluString(),
        ];
    }

    public function isDeploying(): bool
    {
        return $this->deployments()->ongoing()->exists();
    }

    /**
     * The event a deploy signals, if this site has declared one. At most one,
     * because a site has one deploy target as far as this is concerned.
     */
    public function deploymentHeartbeat(): ?MonitorHeartbeat
    {
        return $this->heartbeats()
            ->where('tracks_deployments', true)
            ->where('kind', HeartbeatKind::EVENT->value)
            ->enabled()
            ->first();
    }

    public function startDeployment(): MonitorDeployment
    {
        $deployment = $this->deployments()->create([
            'started_at' => Carbon::now(),
        ]);

        $this->signalDeployment('start');

        return $deployment;
    }

    public function finishDeployment(): void
    {
        $this->deployments()
            ->ongoing()
            ->latest('started_at')
            ->first()
            ?->update(['finished_at' => Carbon::now()]);

        $this->signalDeployment(null);
    }

    /**
     * Tell whoever is judging that the deploy started, or finished.
     *
     * The deploy script only ever talks to this application — that is the point
     * of the signed URLs — but in `remote` mode the thing that notices a deploy
     * that never finished lives on the prober, so the signal has to travel. In
     * `local` mode this application is doing the judging and applies it
     * directly.
     *
     * A site that has not declared a deployment event gets the suppression
     * window and nothing else, which is what every install had before this
     * existed.
     */
    protected function signalDeployment(?string $action): void
    {
        $heartbeat = $this->deploymentHeartbeat();

        if ($heartbeat === null) {
            return;
        }

        if (Monitoring::checksRemotely()) {
            PingHeartbeat::dispatch($heartbeat->token, $action, 'Deployment on '.($this->name ?? 'this site'));

            return;
        }

        $heartbeat->forceFill($action === 'start'
            ? [
                'status' => HeartbeatStatus::RUNNING,
                'last_start_at' => Carbon::now(),
                'last_ping_at' => Carbon::now(),
            ]
            : [
                'status' => HeartbeatStatus::OK,
                'last_finish_at' => Carbon::now(),
                'last_ping_at' => Carbon::now(),
                'suppressed_since' => null,
            ])->save();
    }

    /**
     * A permanent signed URL that opens a deployment window for every monitor
     * on this site. Drop it into a deploy script (GET) to stop a restart-induced
     * blip paging anyone.
     */
    public function deploymentStartUrl(): string
    {
        return URL::signedRoute('monitoring.deployment.start', ['site' => $this->getKey()]);
    }

    /** The other half of the pair: alerting resumes on the next check. */
    public function deploymentFinishUrl(): string
    {
        return URL::signedRoute('monitoring.deployment.finish', ['site' => $this->getKey()]);
    }
}
