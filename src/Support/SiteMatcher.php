<?php

namespace Abigah\BotCopTrafficDivision\Support;

use Abigah\BotCopTrafficDivision\Facades\Monitoring;
use Abigah\BotCopTrafficDivision\Models\Monitor;
use Abigah\BotCopTrafficDivision\Models\MonitoredSite;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Finds the site a monitor belongs to.
 *
 * Both the backfill and the legacy import need this and neither can guess it.
 * A host with its own site model knows things the package does not — its sites
 * may belong to a Client, or to a Project, and which one a URL belongs to is
 * not something a hostname reveals — so the host answers through
 * `resolveSiteForMonitorUsing()`.
 *
 * The package answers for itself only when the site model is its own, where
 * grouping by host is the whole of the question.
 */
class SiteMatcher
{
    /** @var array<string, Model> */
    protected array $cache = [];

    /**
     * The site for this monitor.
     *
     * A wired resolver is the only answer, including when it declines: the host
     * knows which of its sites a URL belongs to and the package does not, so
     * "none" means a monitor it does not want attached yet, and the caller
     * reports that rather than inventing something.
     *
     * With no resolver and the package's own site model, grouping by host is
     * the whole of the question.
     *
     * `$create` false looks without writing, so a dry run can show what would
     * happen without doing any of it.
     */
    public function for(Monitor $monitor, bool $create = true): ?Model
    {
        if (Monitoring::hasSiteForMonitorResolver()) {
            return Monitoring::siteForMonitor($monitor);
        }

        if (! $this->ownsSiteCreation()) {
            return null;
        }

        $host = $monitor->host();

        if (isset($this->cache[$host])) {
            return $this->cache[$host];
        }

        $existing = MonitoredSite::query()->where('name', $host)->first();

        if ($existing !== null) {
            return $this->cache[$host] = $existing;
        }

        if (! $create) {
            // Unsaved, purely so a dry run has a name to print.
            return new MonitoredSite(['name' => $host, 'owner_id' => $monitor->owner_id]);
        }

        return $this->cache[$host] = MonitoredSite::create([
            'name' => $host,
            'owner_id' => $monitor->owner_id,
        ]);
    }

    /**
     * Whether this matcher may create sites on its own. It may when the site
     * model is the package's, and must not otherwise: a host's table has
     * columns and meanings the package knows nothing about.
     */
    public function ownsSiteCreation(): bool
    {
        return Monitoring::siteModel() === MonitoredSite::class;
    }

    /**
     * Fail loudly rather than silently attaching nothing. A host that supplies
     * its own site model and no resolver has an install that is half wired, and
     * finding that out from an empty manifest later is worse.
     *
     * Whether a resolver is wired is the whole of the question. This used to
     * ask one about a made-up URL and treat null as "not wired", but null is
     * the documented way to decline a monitor — so a resolver that only matches
     * sites the host already has failed here before importing anything, and
     * one that creates sites made a site for the made-up URL on every run.
     */
    public function guardResolverIsWired(): void
    {
        if ($this->ownsSiteCreation() || Monitoring::hasSiteForMonitorResolver()) {
            return;
        }

        throw new RuntimeException(
            'monitoring.site_model is '.Monitoring::siteModel().', so this package cannot create sites itself. '
            .'Wire Monitoring::resolveSiteForMonitorUsing() in a service provider so it can say which site a monitor belongs to.'
        );
    }
}
