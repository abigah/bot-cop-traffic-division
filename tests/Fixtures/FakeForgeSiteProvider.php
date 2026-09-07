<?php

namespace Abigah\BotCopTrafficDivision\Tests\Fixtures;

use Abigah\BotCopTrafficDivision\Contracts\ForgeSiteProvider;
use Throwable;

/**
 * A ForgeSiteProvider standing in for a host's real Forge integration. Bind an
 * instance into the container and point config('monitoring.forge_provider') at
 * this class to drive the panel from a test.
 */
class FakeForgeSiteProvider implements ForgeSiteProvider
{
    public bool $configured = true;

    public ?Throwable $failure = null;

    /** @var array<int, array{id: int, name: string, ip_address: string, sites: array<int, array{id: int, name: string}>}> */
    public array $servers = [
        [
            'id' => 7,
            'name' => 'web-1',
            'ip_address' => '10.0.0.1',
            'sites' => [
                ['id' => 42, 'name' => 'example.test'],
            ],
        ],
    ];

    /** @var array{primary: string, aliases: array<int, string>} */
    public array $domains = [
        'primary' => 'example.test',
        'aliases' => ['www.example.test'],
    ];

    public function isConfiguredFor(mixed $owner): bool
    {
        return $this->configured;
    }

    public function serversWithSites(mixed $owner): array
    {
        if ($this->failure) {
            throw $this->failure;
        }

        return $this->servers;
    }

    public function siteDomains(mixed $owner, int $serverId, int $siteId): array
    {
        if ($this->failure) {
            throw $this->failure;
        }

        return $this->domains;
    }
}
