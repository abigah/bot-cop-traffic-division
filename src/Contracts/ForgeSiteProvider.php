<?php

namespace Abigah\BotCopTrafficDivision\Contracts;

/**
 * The host's access to Laravel Forge.
 *
 * The package stores which Forge sites a monitor is linked to, but never talks
 * to Forge itself: where the credentials live and how they are scoped to an
 * owner is the host application's business. Implement this, point
 * `config('monitoring.forge_provider')` at the implementation, and the monitor
 * history screen grows a Forge panel; leave that config null and it doesn't.
 *
 * Throw a ForgeAccessException from any method to put a message in front of the
 * user — a token missing the scopes an endpoint needs, say. Any other exception
 * is reported as a generic failure.
 */
interface ForgeSiteProvider
{
    /**
     * Whether this owner has usable Forge credentials. False shows the panel's
     * "add a token" hint instead of its controls.
     */
    public function isConfiguredFor(mixed $owner): bool;

    /**
     * Every server the owner can see, each with its sites.
     *
     * @return array<int, array{id: int, name: string, ip_address: string, sites: array<int, array{id: int, name: string}>}>
     */
    public function serversWithSites(mixed $owner): array;

    /**
     * The primary domain and aliases configured on one Forge site.
     *
     * @return array{primary: string, aliases: array<int, string>}
     */
    public function siteDomains(mixed $owner, int $serverId, int $siteId): array;
}
