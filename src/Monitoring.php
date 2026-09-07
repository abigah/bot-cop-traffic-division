<?php

namespace Abigah\BotCopTrafficDivision;

use Abigah\BotCopTrafficDivision\Contracts\ForgeSiteProvider;
use Abigah\BotCopTrafficDivision\Jobs\CheckCertificateJob;
use Abigah\BotCopTrafficDivision\Jobs\CheckDomainExpiryJob;
use Abigah\BotCopTrafficDivision\Jobs\CheckUptimeJob;
use Abigah\BotCopTrafficDivision\Models\Monitor;
use Abigah\BotCopTrafficDivision\Models\MonitorNotificationPreference;
use Closure;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Support\Arrayable;
use RuntimeException;

/**
 * The host's side of the package: the resolvers an application wires in its
 * service provider, plus the handful of configuration questions the rest of the
 * package asks rather than reading config directly.
 */
class Monitoring
{
    protected ?Closure $currentOwnerResolver = null;

    protected ?Closure $recipientsResolver = null;

    protected ?Closure $channelsResolver = null;

    protected ?Closure $sitesResolver = null;

    protected ?Closure $ownerForSiteResolver = null;

    protected ?Closure $siteForMonitorResolver = null;

    protected ?Closure $moveTargetsResolver = null;

    protected ?Closure $timezoneResolver = null;

    protected ?Closure $uses24HourTimeResolver = null;

    protected ?Closure $defaultPreferencesResolver = null;

    protected ?Closure $urlResolver = null;

    public function resolveCurrentOwnerUsing(Closure $resolver): void
    {
        $this->currentOwnerResolver = $resolver;
    }

    public function resolveRecipientsUsing(Closure $resolver): void
    {
        $this->recipientsResolver = $resolver;
    }

    public function resolveChannelsUsing(Closure $resolver): void
    {
        $this->channelsResolver = $resolver;
    }

    /**
     * The sites belonging to an owner. Required when the host supplies its own
     * site model; the package's own model answers this itself.
     */
    public function resolveSitesUsing(Closure $resolver): void
    {
        $this->sitesResolver = $resolver;
    }

    /**
     * The owner an event on a site should notify. Required alongside
     * resolveSitesUsing() so an incident reaches the right recipients.
     */
    public function resolveOwnerForSiteUsing(Closure $resolver): void
    {
        $this->ownerForSiteResolver = $resolver;
    }

    /**
     * The site a monitor belongs to, used by `monitoring:sites:backfill` to
     * attach existing monitors. The host decides how its own site model maps
     * onto a URL; returning null leaves the monitor for the default grouping.
     */
    public function resolveSiteForMonitorUsing(Closure $resolver): void
    {
        $this->siteForMonitorResolver = $resolver;
    }

    public function resolveMoveTargetsUsing(Closure $resolver): void
    {
        $this->moveTargetsResolver = $resolver;
    }

    public function resolveTimezoneUsing(Closure $resolver): void
    {
        $this->timezoneResolver = $resolver;
    }

    public function resolveUses24HourTimeUsing(Closure $resolver): void
    {
        $this->uses24HourTimeResolver = $resolver;
    }

    public function resolveDefaultPreferencesUsing(Closure $resolver): void
    {
        $this->defaultPreferencesResolver = $resolver;
    }

    /**
     * Where a notification should send someone to look.
     *
     * The package's own screens are one answer and not the only one: an
     * application may show monitoring on a page it already has, and one that
     * has not installed the UI at all has no answer — in which case a
     * notification simply carries no link rather than failing to render.
     */
    public function resolveUrlUsing(Closure $resolver): void
    {
        $this->urlResolver = $resolver;
    }

    public function urlFor(mixed $subject): ?string
    {
        if ($this->urlResolver !== null) {
            return ($this->urlResolver)($subject);
        }

        /*
         | Fall back to this package's own screens when they are registered. An
         | install that turned the routes off, or that shows monitoring
         | somewhere of its own, gets null and a notification with no link
         | rather than a route-not-found while sending mail at 3am.
         */
        $routes = app('router')->getRoutes();

        if ($subject instanceof Monitor
            && $routes->hasNamedRoute('monitoring.monitor.history')) {
            return route('monitoring.monitor.history', ['monitor' => $subject->getKey()]);
        }

        $siteId = $subject->site_id ?? null;

        if ($siteId !== null && $routes->hasNamedRoute('monitoring.site')) {
            return route('monitoring.site', ['site' => $siteId]);
        }

        return null;
    }

    public function currentOwner(): mixed
    {
        return $this->guard($this->currentOwnerResolver, 'resolveCurrentOwnerUsing')();
    }

    /**
     * @return iterable<int, mixed>
     */
    public function recipientsFor(mixed $owner, mixed $subject): iterable
    {
        return $this->guard($this->recipientsResolver, 'resolveRecipientsUsing')($owner, $subject);
    }

    /**
     * @return array<int, string>
     */
    public function channelsFor(mixed $notifiable, mixed $subject, string $event): array
    {
        return $this->guard($this->channelsResolver, 'resolveChannelsUsing')($notifiable, $subject, $event);
    }

    /**
     * @return iterable<int, mixed>
     */
    public function sitesFor(mixed $owner): iterable
    {
        if ($this->sitesResolver === null) {
            return $this->siteModel()::query()->where('owner_id', $owner?->getKey())->get();
        }

        return ($this->sitesResolver)($owner);
    }

    public function ownerForSite(mixed $site): mixed
    {
        if ($this->ownerForSiteResolver === null) {
            return $this->ownerModel()::find($site->owner_id);
        }

        return ($this->ownerForSiteResolver)($site);
    }

    /**
     * Whether the host has said how monitors map onto its sites. When it has,
     * its answer is the only one — including "none", which means a monitor it
     * does not want attached yet.
     */
    public function hasSiteForMonitorResolver(): bool
    {
        return $this->siteForMonitorResolver !== null;
    }

    public function siteForMonitor(mixed $monitor): mixed
    {
        if ($this->siteForMonitorResolver === null) {
            return null;
        }

        return ($this->siteForMonitorResolver)($monitor);
    }

    /**
     * Owners the given user may move a monitor to. Returns an empty list when
     * no resolver is configured (the move feature is optional).
     *
     * @return iterable<int, mixed>
     */
    public function moveTargetsFor(mixed $user): iterable
    {
        if ($this->moveTargetsResolver === null) {
            return [];
        }

        return ($this->moveTargetsResolver)($user);
    }

    /**
     * The display timezone for the given user. Defaults to UTC when no
     * resolver is configured.
     */
    public function timezoneFor(mixed $user): string
    {
        if ($this->timezoneResolver === null) {
            return 'UTC';
        }

        return ($this->timezoneResolver)($user) ?: 'UTC';
    }

    /**
     * Whether the given user prefers 24-hour time. Defaults to false when no
     * resolver is configured.
     */
    public function uses24HourTimeFor(mixed $user): bool
    {
        if ($this->uses24HourTimeResolver === null) {
            return false;
        }

        return (bool) ($this->uses24HourTimeResolver)($user);
    }

    /**
     * The notifiable's own default notification settings, used to seed the
     * per-monitor override form. Anything exposing the preference columns will
     * do: a model, an array, or an Arrayable. Returns null when no resolver is
     * configured, in which case the form opens on the package's defaults.
     */
    public function defaultPreferencesFor(mixed $notifiable): mixed
    {
        if ($this->defaultPreferencesResolver === null) {
            return null;
        }

        return ($this->defaultPreferencesResolver)($notifiable);
    }

    /**
     * The host's Forge integration, or null when none is configured — in which
     * case the monitor history screen leaves the Forge panel out entirely.
     */
    public function forgeSiteProvider(): ?ForgeSiteProvider
    {
        $provider = config('monitoring.forge_provider');

        return $provider === null ? null : app($provider);
    }

    /** @return class-string */
    public function ownerModel(): string
    {
        return config('monitoring.owner_model');
    }

    /** @return class-string */
    public function siteModel(): string
    {
        return config('monitoring.site_model');
    }

    /** @return class-string */
    public function notifiableModel(): string
    {
        return config('monitoring.notifiable_model');
    }

    /**
     * Whether uptime checks are performed by a prober outside this
     * infrastructure rather than by this application's own scheduler.
     */
    public function checksRemotely(): bool
    {
        return config('monitoring.checker') === 'remote';
    }

    /**
     * Every configured prober, keyed by id.
     *
     * @return array<string, array{base_url: string, secrets: array<int, string>}>
     */
    public function probers(): array
    {
        return config('monitoring.probers', []);
    }

    /**
     * The secrets accepted from, and used when signing to, one prober. Current
     * first; a verifier tries them all so a secret can rotate without a gap.
     *
     * @return array<int, string>
     */
    public function secretsFor(string $proberId): array
    {
        return array_values(array_filter($this->probers()[$proberId]['secrets'] ?? []));
    }

    /**
     * The channels a recipient wants for one event about one subject.
     *
     * A host can hand this straight to `resolveChannelsUsing()` and get
     * per-recipient preferences with no code of its own, which is what the
     * preferences table and its screen exist for. A host wanting something else
     * — a rota, an escalation policy, quiet hours — writes its own resolver and
     * never calls this.
     *
     * The answer is looked for in the order of how specifically it was given:
     * this monitor, then the site the monitor belongs to, then whatever the
     * host offers as the recipient's own defaults, then the package's. The
     * first row found wins outright rather than merging, because a preference
     * set on a monitor is someone saying "this one is different" and merging
     * would take that back.
     *
     * @return array<int, string>
     */
    public function preferenceChannelsFor(mixed $notifiable, mixed $subject, string $event): array
    {
        $preference = $this->preferenceFor($notifiable, $subject);

        if ($preference !== null) {
            return $preference->channelsForEventAndUser($event, $notifiable);
        }

        $defaults = $this->defaultPreferencesFor($notifiable);

        if ($defaults instanceof MonitorNotificationPreference) {
            return $defaults->channelsForEventAndUser($event, $notifiable);
        }

        if ($defaults !== null) {
            return (new MonitorNotificationPreference($this->toPreferenceAttributes($defaults)))
                ->channelsForEventAndUser($event, $notifiable);
        }

        return ['mail', 'database'];
    }

    /**
     * The most specific preference row this recipient has for this subject: the
     * monitor's own, or the one covering the site it belongs to.
     */
    public function preferenceFor(mixed $notifiable, mixed $subject): ?MonitorNotificationPreference
    {
        $notifiableId = $notifiable?->getKey();

        if ($notifiableId === null) {
            return null;
        }

        if ($subject instanceof Monitor) {
            $own = MonitorNotificationPreference::query()
                ->where('notifiable_id', $notifiableId)
                ->where('monitor_id', $subject->getKey())
                ->first();

            if ($own !== null) {
                return $own;
            }
        }

        $siteId = $subject->site_id ?? null;

        if ($siteId === null) {
            return null;
        }

        return MonitorNotificationPreference::query()
            ->where('notifiable_id', $notifiableId)
            ->where('site_id', $siteId)
            ->first();
    }

    /**
     * Accept anything shaped like a set of preferences — a model, an array, an
     * Arrayable — since a host's own defaults rarely live in this package's
     * table.
     *
     * @return array<string, mixed>
     */
    protected function toPreferenceAttributes(mixed $defaults): array
    {
        if (is_array($defaults)) {
            return $defaults;
        }

        if ($defaults instanceof Arrayable) {
            return $defaults->toArray();
        }

        return (array) $defaults;
    }

    public function schemaVersion(): int
    {
        return (int) config('monitoring.schema_version', 1);
    }

    /**
     * Register the package's scheduled work. Call it from routes/console.php.
     *
     * In `remote` mode the every-minute uptime job is simply not registered: a
     * prober is performing those checks from outside and delivering the results
     * here. Everything else stays, because certificate, domain-expiry and DNS
     * checking never left this side — and on a hibernating extranet the
     * scheduler waking the app for a daily certificate check is exactly what is
     * wanted.
     */
    public function schedule(?Schedule $schedule = null): void
    {
        $schedule ??= app(Schedule::class);

        if (! $this->checksRemotely()) {
            $schedule->job(new CheckUptimeJob)->everyMinute();

            /*
             | A prober does this in remote mode and delivers verdicts. In local
             | mode nothing else would, and a heartbeat nobody judges is a job
             | that can stop running unnoticed.
             */
            $schedule->command('monitoring:heartbeats:sweep')->everyMinute();
        }

        $schedule->job(new CheckCertificateJob)->daily();
        $schedule->job(new CheckDomainExpiryJob)->weekly();
        $schedule->job(new CheckDomainExpiryJob(expiringSoonOnly: true))->daily();
        $schedule->command('monitor-checks:aggregate')->hourly();
    }

    protected function guard(?Closure $resolver, string $method): Closure
    {
        if ($resolver === null) {
            throw new RuntimeException(
                "Monitoring::{$method}() has not been configured. Set it in a service provider's boot() method."
            );
        }

        return $resolver;
    }
}
