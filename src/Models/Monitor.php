<?php

namespace Abigah\BotCopTrafficDivision\Models;

use Abigah\BotCopTrafficDivision\Enums\CertificateStatus;
use Abigah\BotCopTrafficDivision\Enums\DomainExpiryStatus;
use Abigah\BotCopTrafficDivision\Enums\UptimeStatus;
use Abigah\BotCopTrafficDivision\Events\CertificateCheckFailed;
use Abigah\BotCopTrafficDivision\Events\CertificateExpiresSoon;
use Abigah\BotCopTrafficDivision\Events\DomainExpiresSoon;
use Abigah\BotCopTrafficDivision\Events\UptimeCheckFailed;
use Abigah\BotCopTrafficDivision\Events\UptimeCheckRecovered;
use Abigah\BotCopTrafficDivision\Services\DomainExpiryService;
use Abigah\BotCopTrafficDivision\Support\CheckResult;
use Abigah\BotCopTrafficDivision\Support\SslCertificate;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * One URL, checked on an interval.
 *
 * A monitor belongs to a site, and the `critical` ones are what "the site is
 * down" means. Whether the check was performed here or by a prober outside
 * this infrastructure makes no difference to anything on this class: results
 * enter through recordUptimeResult() either way.
 */
class Monitor extends Model
{
    protected $guarded = [];

    /** @var int|null Temporarily holds the response time captured during a check. */
    public ?int $lastResponseTimeMs = null;

    /**
     * The table's defaults, repeated here so a monitor that has not been
     * reloaded still answers for its own status.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'critical' => true,
        'uptime_check_enabled' => true,
        'uptime_status' => 'not yet checked',
        'uptime_check_times_failed_in_a_row' => 0,
        'uptime_check_interval_in_minutes' => 5,
        'uptime_check_method' => 'get',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'critical' => 'boolean',
            'uptime_check_enabled' => 'boolean',
            'uptime_check_additional_headers' => 'array',
            'uptime_check_interval_in_minutes' => 'integer',
            'uptime_last_check_date' => 'datetime',
            'uptime_status_last_change_date' => 'datetime',
            'uptime_check_failed_event_fired_on_date' => 'datetime',
            'served_from_cache_at' => 'datetime',
            'certificate_check_enabled' => 'boolean',
            'certificate_expiration_date' => 'datetime',
            'domain_expiry_check_enabled' => 'boolean',
            'domain_expiration_date' => 'datetime',
            'domain_expiry_notified_at' => 'datetime',
        ];
    }

    public function host(): string
    {
        return parse_url((string) $this->url, PHP_URL_HOST) ?: (string) $this->url;
    }

    /** @param  Builder<static>  $query */
    public function scopeForOwner(Builder $query, mixed $ownerId): Builder
    {
        return $query->where('owner_id', $ownerId);
    }

    /** @param  Builder<static>  $query */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('uptime_check_enabled', true);
    }

    /** @param  Builder<static>  $query */
    public function scopeCritical(Builder $query): Builder
    {
        return $query->where('critical', true);
    }

    /** @param  Builder<static>  $query */
    public function scopeDown(Builder $query): Builder
    {
        return $query->where('uptime_status', UptimeStatus::DOWN->value);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(config('monitoring.site_model'), 'site_id');
    }

    public function checks(): HasMany
    {
        return $this->hasMany(MonitorCheck::class);
    }

    public function checkAggregates(): HasMany
    {
        return $this->hasMany(MonitorCheckAggregate::class);
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(MonitorIncident::class);
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(MonitorDeployment::class);
    }

    public function forgeSites(): HasMany
    {
        return $this->hasMany(MonitorForgeSite::class);
    }

    public function dnsLookups(): HasMany
    {
        return $this->hasMany(MonitorDnsLookup::class);
    }

    /**
     * Stretches of checking that were performed but never arrived. A gap that
     * is visible is a gap nobody mistakes for uptime.
     */
    public function checkGaps(): HasMany
    {
        return $this->hasMany(MonitorCheckGap::class);
    }

    public function notificationPreferences(): HasMany
    {
        return $this->hasMany(MonitorNotificationPreference::class);
    }

    public function isUp(): bool
    {
        return $this->uptime_status === UptimeStatus::UP->value;
    }

    public function isDown(): bool
    {
        return $this->uptime_status === UptimeStatus::DOWN->value;
    }

    /**
     * The interval this monitor is actually checked on.
     *
     * Two floors apply. Every monitor is held at the tenant minimum, mirrored
     * from the prober's config so this application's forms do not offer
     * intervals that will be clamped anyway. A monitor on a hibernating site is
     * held much further back, because every check there costs a cold start —
     * heartbeats are what cover the gap.
     */
    public function effectiveIntervalMinutes(): int
    {
        $requested = (int) $this->uptime_check_interval_in_minutes;

        $floor = $this->site?->hibernates
            ? (int) config('monitoring.uptime.hibernating_interval_minutes', 60)
            : (int) config('monitoring.uptime.minimum_interval_minutes', 5);

        return max($requested, $floor);
    }

    /**
     * Whether this monitor is due. A monitor that has never been checked, or is
     * currently down, is always due: an outage is re-checked at every
     * opportunity so recovery is noticed as soon as it happens.
     */
    public function shouldCheckUptime(): bool
    {
        if (! $this->uptime_check_enabled) {
            return false;
        }

        if ($this->uptime_status === UptimeStatus::NOT_YET_CHECKED->value || $this->isDown()) {
            return true;
        }

        if ($this->uptime_last_check_date === null) {
            return true;
        }

        return (int) $this->uptime_last_check_date->diffInMinutes(absolute: true) >= $this->effectiveIntervalMinutes();
    }

    /**
     * Whether a deployment window is open over this monitor.
     *
     * The window belongs to the site — one deploy restarts everything on it —
     * but a per-monitor window is still honoured so a deploy script holding the
     * older URLs keeps working while it is moved over.
     */
    public function isDeploying(): bool
    {
        if ($this->deployments()->ongoing()->exists()) {
            return true;
        }

        return $this->site_id !== null
            && MonitorDeployment::query()->where('site_id', $this->site_id)->ongoing()->exists();
    }

    /**
     * A permanent signed URL that opens a deployment window for this monitor
     * alone. New deploy scripts should use the site's pair instead.
     */
    public function deploymentStartUrl(): string
    {
        return URL::signedRoute('monitoring.deployment.monitor.start', ['monitor' => $this->getKey()]);
    }

    public function deploymentFinishUrl(): string
    {
        return URL::signedRoute('monitoring.deployment.monitor.finish', ['monitor' => $this->getKey()]);
    }

    public function getLatestResponseTimeMsAttribute(): ?int
    {
        return $this->checks->first()?->response_time_ms;
    }

    /**
     * The seam.
     *
     * Every uptime result enters here — checked by this application or
     * delivered by a prober — and everything downstream is the same either way:
     * status, history, the consecutive-failure threshold, the resend interval,
     * incidents and the deployment window. A prober performs checks and
     * delivers what it saw; the conclusions are drawn here, with this
     * application's own thresholds, which is what keeps the alerting rules in
     * one place.
     *
     * Idempotent on `check_id`: a delivery retried after a timeout replays as
     * nothing, so the prober can retry freely.
     */
    public function recordUptimeResult(CheckResult $result): MonitorCheck
    {
        $existing = MonitorCheck::query()->where('check_id', $result->checkId)->first();

        if ($existing !== null) {
            return $existing;
        }

        /*
         | Paused. The result is kept — a prober went to the trouble of
         | gathering it and it is genuine — but nothing is concluded from it:
         | no status change, no incident, nobody woken.
         |
         | The guard belongs here rather than at either checker, because a
         | prober runs off a cached plan and keeps delivering for a monitor
         | disabled after that plan was built. Pausing has to stop the alerting
         | now, not at the next reconcile, or the button does not mean what it
         | says.
         */
        if (! $this->uptime_check_enabled) {
            return $this->recordWithoutJudging($result);
        }

        if ($result->up) {
            $this->markUptimeUp($result->checkedAt);
        } else {
            $this->markUptimeDown($result->failureReason ?? '', $result->checkedAt);
        }

        if ($result->servedFromCache) {
            $this->forceFill(['served_from_cache_at' => $result->checkedAt])->save();
        }

        $check = $this->checks()->create($result->toCheckAttributes());

        if ($result->up) {
            $this->resolveOpenIncident($result->checkedAt);
        } else {
            $this->openIncident($result->failureReason, $result->checkedAt);
        }

        return $check;
    }

    /**
     * Keep the check and conclude nothing from it.
     *
     * Two situations reach this: a monitor nobody asked to be watched, and a
     * failure another prober contradicted. In both the result is real and the
     * conclusion is not this application's to draw.
     */
    protected function recordWithoutJudging(CheckResult $result, bool $disagreed = false): MonitorCheck
    {
        return $this->checks()->create([
            ...$result->toCheckAttributes(),
            'disagreed' => $disagreed,
        ]);
    }

    /**
     * Keep a check that this application has decided not to believe.
     *
     * A failure only one prober saw, while another said up in the same window,
     * is history worth having and evidence of nothing on its own. It is
     * recorded with its location tag and shown as degraded; the status, the
     * failure counter and the incidents are left exactly as they were.
     */
    public function recordDisagreement(CheckResult $result): MonitorCheck
    {
        $existing = MonitorCheck::query()->where('check_id', $result->checkId)->first();

        if ($existing !== null) {
            return $existing;
        }

        return $this->recordWithoutJudging($result, disagreed: true);
    }

    protected function markUptimeUp(CarbonInterface $checkedAt): void
    {
        $wasAlerting = $this->uptime_check_failed_event_fired_on_date !== null;

        if ($this->uptime_status !== UptimeStatus::UP->value || $this->uptime_status_last_change_date === null) {
            $this->uptime_status_last_change_date = $checkedAt;
        }

        $this->uptime_status = UptimeStatus::UP->value;
        $this->uptime_check_failure_reason = '';
        $this->uptime_check_times_failed_in_a_row = 0;
        $this->uptime_check_failed_event_fired_on_date = null;
        $this->touchLastCheckDate($checkedAt);
        $this->save();

        if ($wasAlerting) {
            event(new UptimeCheckRecovered($this));
        }
    }

    /**
     * Record a failed check. While a deployment window is open the failure is
     * still recorded, but the consecutive-failure counter is held at zero and no
     * event fires, so alerting starts fresh once the window closes rather than
     * paging about a restart.
     */
    protected function markUptimeDown(string $reason, CarbonInterface $checkedAt): void
    {
        if ($this->uptime_status !== UptimeStatus::DOWN->value || $this->uptime_status_last_change_date === null) {
            $this->uptime_status_last_change_date = $checkedAt;
        }

        $this->uptime_status = UptimeStatus::DOWN->value;
        $this->uptime_check_failure_reason = $reason;
        $this->touchLastCheckDate($checkedAt);

        if ($this->isDeploying()) {
            $this->uptime_check_times_failed_in_a_row = 0;
            $this->save();

            return;
        }

        $this->uptime_check_times_failed_in_a_row++;
        $this->save();

        if ($this->shouldFireUptimeFailedEvent($checkedAt)) {
            $this->uptime_check_failed_event_fired_on_date = $checkedAt;
            $this->save();

            event(new UptimeCheckFailed($this));
        }
    }

    /**
     * A result older than what is already recorded is still kept as history,
     * but it does not wind the clock backwards. Deliveries can arrive late and
     * out of order; the last check date has to mean the most recent check, or a
     * monitor would look overdue every time a stale batch landed.
     */
    protected function touchLastCheckDate(CarbonInterface $checkedAt): void
    {
        if ($this->uptime_last_check_date === null || $checkedAt->greaterThan($this->uptime_last_check_date)) {
            $this->uptime_last_check_date = $checkedAt;
        }
    }

    protected function shouldFireUptimeFailedEvent(CarbonInterface $checkedAt): bool
    {
        if ($this->uptime_check_times_failed_in_a_row === (int) config('monitoring.uptime.fire_failed_event_after_consecutive_failures')) {
            return true;
        }

        if ($this->uptime_check_failed_event_fired_on_date === null) {
            return false;
        }

        $resendEveryMinutes = (int) config('monitoring.uptime.resend_failed_notification_every_minutes');

        if ($resendEveryMinutes === 0) {
            return false;
        }

        return $this->uptime_check_failed_event_fired_on_date->diffInMinutes($checkedAt, absolute: true) >= $resendEveryMinutes;
    }

    protected function openIncident(?string $failureReason, CarbonInterface $startedAt): void
    {
        if ($this->isDeploying()) {
            return;
        }

        if ($this->incidents()->ongoing()->exists()) {
            return;
        }

        $this->incidents()->create([
            'site_id' => $this->site_id,
            'started_at' => $startedAt,
            'failure_reason' => $failureReason,
        ]);
    }

    protected function resolveOpenIncident(CarbonInterface $resolvedAt): void
    {
        $incident = $this->incidents()->ongoing()->latest('started_at')->first();

        if ($incident === null) {
            return;
        }

        $incident->update([
            'resolved_at' => $resolvedAt,
            'duration_seconds' => $incident->started_at->diffInSeconds($resolvedAt, absolute: true),
        ]);
    }

    public function checkCertificate(): void
    {
        try {
            $this->applyCertificate(SslCertificate::createForHostName($this->host()));
        } catch (Throwable $exception) {
            $this->certificate_status = CertificateStatus::INVALID->value;
            $this->certificate_expiration_date = null;
            $this->certificate_issuer = '';
            $this->certificate_check_failure_reason = $exception->getMessage();
            $this->save();

            event(new CertificateCheckFailed($this, $exception->getMessage()));
        }
    }

    protected function applyCertificate(SslCertificate $certificate): void
    {
        $host = $this->host();
        $valid = $certificate->isValid($host);

        $this->certificate_status = $valid ? CertificateStatus::VALID->value : CertificateStatus::INVALID->value;
        $this->certificate_expiration_date = $certificate->expirationDate();
        $this->certificate_issuer = $certificate->getIssuer();

        if ($valid) {
            $this->certificate_check_failure_reason = '';
            $this->save();

            if ($certificate->expirationDate()->diffInDays(absolute: true) <= (int) config('monitoring.certificate.expiring_soon_days')) {
                event(new CertificateExpiresSoon($this));
            }

            return;
        }

        $reason = 'Unknown';

        if (! $certificate->appliesToHost($host)) {
            $reason = "Certificate does not apply to {$this->url} but only to these domains: ".implode(',', $certificate->getAdditionalDomains());
        }

        if ($certificate->isExpired()) {
            $reason = 'The certificate has expired';
        }

        $this->certificate_check_failure_reason = $reason;
        $this->save();

        event(new CertificateCheckFailed($this, $reason));
    }

    public function checkDomainExpiry(): void
    {
        $result = app(DomainExpiryService::class)->check((string) $this->url);

        $this->update([
            'domain_expiry_status' => $result->status->value,
            'domain_expiration_date' => $result->expirationDate,
            'domain_registrar' => $result->registrar,
            'domain_expiry_check_failure_reason' => $result->failureReason ?? '',
        ]);

        if ($result->status === DomainExpiryStatus::VALID && now()->diffInDays($result->expirationDate, false) <= 30) {
            $this->fireDomainExpiresSoonIfDue();
        }
    }

    /**
     * Fire a DomainExpiresSoon event if enough time has passed since the last
     * one: weekly beyond a week from expiry, daily inside it.
     */
    protected function fireDomainExpiresSoonIfDue(): void
    {
        $daysUntilExpiry = (int) now()->diffInDays($this->domain_expiration_date, false);
        $throttleHours = $daysUntilExpiry <= 7 ? 24 : 168;

        if ($this->domain_expiry_notified_at && $this->domain_expiry_notified_at->diffInHours(now()) < $throttleHours) {
            return;
        }

        $this->update(['domain_expiry_notified_at' => now()]);

        event(new DomainExpiresSoon($this));
    }
}
