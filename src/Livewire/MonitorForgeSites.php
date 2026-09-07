<?php

namespace Abigah\BotCopTrafficDivision\Livewire;

use Abigah\BotCopTrafficDivision\Contracts\ForgeSiteProvider;
use Abigah\BotCopTrafficDivision\Exceptions\ForgeAccessException;
use Abigah\BotCopTrafficDivision\Facades\Monitoring;
use Abigah\BotCopTrafficDivision\Jobs\CheckUptimeJob;
use Abigah\BotCopTrafficDivision\Models\Monitor;
use Abigah\BotCopTrafficDivision\Models\MonitorForgeSite;
use Abigah\BotCopTrafficDivision\Services\MonitorQuery;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;
use Livewire\Component;
use Throwable;

/**
 * Which Forge sites a monitor covers, and which of their domains are watched.
 *
 * The package never talks to Forge itself. Where the credentials live and how
 * they are scoped to an owner is the host's business, so every call goes
 * through its `ForgeSiteProvider`; with none configured this renders nothing at
 * all and the history screen is unchanged.
 *
 * What it is for is the gap between "deployed" and "monitored". A Forge site
 * usually answers on several domains — the primary and its aliases — and the
 * ones nobody remembered to add are exactly the ones that go down unnoticed.
 */
class MonitorForgeSites extends Component
{
    public int $monitorId;

    public bool $hasForgeAccess = false;

    public bool $showLinkModal = false;

    /** @var array<int, array{id: int, name: string, ip_address: string, sites: array<int, array{id: int, name: string}>}> */
    public array $forgeServers = [];

    public string $selectedSite = '';

    public bool $loadingServers = false;

    public string $error = '';

    public bool $showDomainsModal = false;

    public ?int $domainsForgeSiteId = null;

    public string $domainsPrimary = '';

    /** @var array<int, string> */
    public array $domainsAliases = [];

    public bool $loadingDomains = false;

    public string $domainsError = '';

    public function mount(int $monitorId): void
    {
        // monitorId is public, so every action below re-resolves it too.
        $this->monitorQuery()->findMonitorOrFail($monitorId);

        $this->monitorId = $monitorId;

        $provider = $this->forgeProvider();

        $this->hasForgeAccess = $provider !== null
            && $provider->isConfiguredFor(Monitoring::currentOwner());
    }

    public function openLinkModal(): void
    {
        $this->error = '';
        $this->selectedSite = '';
        $this->loadingServers = true;
        $this->showLinkModal = true;

        try {
            $this->forgeServers = $this->forgeProviderOrFail()->serversWithSites(Monitoring::currentOwner());
        } catch (ForgeAccessException $exception) {
            // Written as guidance by the host, so it is shown as it is.
            $this->error = $exception->getMessage();
        } catch (Throwable $exception) {
            $this->error = 'Could not reach Forge: '.$exception->getMessage();
        } finally {
            $this->loadingServers = false;
        }
    }

    public function linkSite(): void
    {
        if ($this->selectedSite === '') {
            return;
        }

        [$serverId, $siteId] = array_pad(explode(':', $this->selectedSite), 2, null);

        $server = collect($this->forgeServers)->firstWhere('id', (int) $serverId);
        $site = collect($server['sites'] ?? [])->firstWhere('id', (int) $siteId);

        if (! $server || ! $site) {
            $this->error = 'That server and site are no longer in the list.';

            return;
        }

        $this->monitorQuery()->findMonitorOrFail($this->monitorId)
            ->forgeSites()
            ->firstOrCreate(
                ['forge_server_id' => $serverId, 'forge_site_id' => $siteId],
                ['server_name' => $server['name'], 'site_name' => $site['name']],
            );

        $this->showLinkModal = false;
        $this->selectedSite = '';
    }

    public function openDomainsModal(int $forgeSiteId): void
    {
        $monitor = $this->monitorQuery()->findMonitorOrFail($this->monitorId);

        $forgeSite = MonitorForgeSite::query()
            ->whereKey($forgeSiteId)
            ->where('monitor_id', $monitor->getKey())
            ->firstOrFail();

        $this->domainsError = '';
        $this->domainsForgeSiteId = $forgeSiteId;
        $this->domainsPrimary = '';
        $this->domainsAliases = [];
        $this->loadingDomains = true;
        $this->showDomainsModal = true;

        try {
            $domains = $this->forgeProviderOrFail()->siteDomains(
                Monitoring::currentOwner(),
                $forgeSite->forge_server_id,
                $forgeSite->forge_site_id,
            );

            $this->domainsPrimary = $domains['primary'];
            $this->domainsAliases = $domains['aliases'];
        } catch (ForgeAccessException $exception) {
            $this->domainsError = $exception->getMessage();
        } catch (Throwable $exception) {
            $this->domainsError = 'Could not reach Forge: '.$exception->getMessage();
        } finally {
            $this->loadingDomains = false;
        }
    }

    /**
     * Start watching one of the Forge site's domains.
     *
     * The new monitor joins the same site as the one it was created from: a
     * Forge site's aliases are the same deployed application, so an outage on
     * one is an outage on the site, not on a site of its own.
     */
    public function createMonitorForDomain(string $domain): void
    {
        $domain = trim($domain);

        if ($domain === '') {
            return;
        }

        $url = 'https://'.ltrim(preg_replace('#^https?://#i', '', $domain), '/');

        // Resolved before anything is written: monitorId is public, and an id
        // that gets rejected must not leave a new monitor behind.
        $source = $this->monitorQuery()->findMonitorOrFail($this->monitorId);

        if ($source->site_id === null) {
            $this->domainsError = 'This monitor does not belong to a site yet, so there is nowhere to add another.';

            return;
        }

        if (Monitor::query()->where('site_id', $source->site_id)->where('url', $url)->exists()) {
            return;
        }

        $monitor = Monitor::create([
            'site_id' => $source->site_id,
            'owner_id' => $this->monitorQuery()->ownerKey(),
            'url' => $url,
            'uptime_check_enabled' => true,

            /*
             | Not critical. An alias that has never been checked failing should
             | not, on its first check, declare the whole site down — that is a
             | decision to make once it has a history.
             */
            'critical' => false,

            'look_for_string' => $this->extractLookForString($url),
            'uptime_check_interval_in_minutes' => (int) config('monitoring.uptime.minimum_interval_minutes', 5),
            'certificate_check_enabled' => true,
            'domain_expiry_check_enabled' => true,
        ]);

        $sourceForgeSite = $this->domainsForgeSiteId === null ? null : MonitorForgeSite::query()
            ->whereKey($this->domainsForgeSiteId)
            ->where('monitor_id', $source->getKey())
            ->first();

        if ($sourceForgeSite !== null) {
            $monitor->forgeSites()->create([
                'forge_server_id' => $sourceForgeSite->forge_server_id,
                'forge_site_id' => $sourceForgeSite->forge_site_id,
                'server_name' => $sourceForgeSite->server_name,
                'site_name' => $sourceForgeSite->site_name,
            ]);
        }

        // In remote mode the observer's change notice has already told the
        // probers to pull again; there is nothing here to dispatch.
        if (! Monitoring::checksRemotely()) {
            CheckUptimeJob::dispatch((string) $monitor->url);
        }
    }

    public function unlinkSite(int $forgeSiteId): void
    {
        $monitor = $this->monitorQuery()->findMonitor($this->monitorId);

        if ($monitor === null) {
            return;
        }

        MonitorForgeSite::query()
            ->whereKey($forgeSiteId)
            ->where('monitor_id', $monitor->getKey())
            ->delete();
    }

    public function render(): View
    {
        $monitor = $this->monitorQuery()->findMonitor($this->monitorId);

        $domains = array_values(array_filter([$this->domainsPrimary, ...$this->domainsAliases]));

        return view('monitoring::livewire.monitor-forge-sites', [
            'hasForgeProvider' => $this->forgeProvider() !== null,
            'linkedSites' => $monitor === null
                ? collect()
                : MonitorForgeSite::where('monitor_id', $monitor->getKey())->get(),
            'domains' => $domains,

            // Which of them are already watched, on this monitor's own site.
            'watched' => $monitor?->site_id === null || $domains === [] ? [] : Monitor::query()
                ->where('site_id', $monitor->site_id)
                ->whereIn('url', array_map(fn (string $domain): string => 'https://'.$domain, $domains))
                ->pluck('url')
                ->map(fn ($url): string => (string) $url)
                ->all(),
        ]);
    }

    /**
     * The first six characters of the page's title, which is what the check
     * then looks for. A status code alone says a server answered; a string from
     * the page says the application did.
     *
     * Empty when the page offers nothing usable, which leaves a status-code
     * check rather than one that fails on every future title change.
     */
    protected function extractLookForString(string $url): string
    {
        try {
            $response = Http::timeout(10)
                ->withUserAgent(config('monitoring.uptime.user_agent'))
                ->get($url);
        } catch (Throwable) {
            return '';
        }

        if (! $response->successful()) {
            return '';
        }

        if (! preg_match('/<title[^>]*>(.*?)<\/title>/is', $response->body(), $matches)) {
            return '';
        }

        $title = trim(html_entity_decode(strip_tags($matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return mb_strlen($title) < 6 ? '' : mb_substr($title, 0, 6);
    }

    protected function forgeProvider(): ?ForgeSiteProvider
    {
        return Monitoring::forgeSiteProvider();
    }

    protected function forgeProviderOrFail(): ForgeSiteProvider
    {
        $provider = $this->forgeProvider();

        if ($provider === null) {
            throw new ForgeAccessException(
                'No Forge integration is configured. Point config("monitoring.forge_provider") at a ForgeSiteProvider.'
            );
        }

        return $provider;
    }

    protected function monitorQuery(): MonitorQuery
    {
        return MonitorQuery::forCurrentOwner();
    }
}
