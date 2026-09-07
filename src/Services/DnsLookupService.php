<?php

namespace Abigah\BotCopTrafficDivision\Services;

use Abigah\BotCopTrafficDivision\Support\DnsLookupResult;
use Illuminate\Support\Facades\Process;

class DnsLookupService
{
    /** DNS resolver to query, bypassing local cache. */
    private const RESOLVER = '1.1.1.1';

    /** Record types to query by default. */
    private const DEFAULT_TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'NS', 'TXT'];

    /** Maximum seconds to wait for a dig response. */
    private const TIMEOUT = 10;

    /**
     * Look up DNS records for a domain, bypassing local cache.
     *
     * @param  array<int, string>|null  $types
     */
    public function lookup(string $domain, ?array $types = null): DnsLookupResult
    {
        $domain = $this->sanitizeDomain($domain);

        if (! $domain) {
            return new DnsLookupResult(
                success: false,
                failureReason: 'Invalid domain provided.',
            );
        }

        $types = $types ?? self::DEFAULT_TYPES;
        $records = [];

        foreach ($types as $type) {
            $result = Process::timeout(self::TIMEOUT)
                ->run(['dig', '+noall', '+answer', '+nocmd', '@'.self::RESOLVER, $domain, $type]);

            if (! $result->successful()) {
                continue;
            }

            $parsed = $this->parseDigOutput($result->output());
            $records = array_merge($records, $parsed);
        }

        if (empty($records)) {
            return new DnsLookupResult(
                success: false,
                failureReason: "No DNS records found for {$domain}.",
            );
        }

        return new DnsLookupResult(
            success: true,
            records: $records,
        );
    }

    /**
     * Parse dig answer-section output into structured records.
     *
     * @return array<int, array{type: string, name: string, value: string, ttl: int}>
     */
    protected function parseDigOutput(string $output): array
    {
        $records = [];

        foreach (explode("\n", trim($output)) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, ';')) {
                continue;
            }

            // dig output format: name ttl class type value
            $parts = preg_split('/\s+/', $line, 5);

            if (count($parts) < 5) {
                continue;
            }

            $records[] = [
                'name' => rtrim($parts[0], '.'),
                'ttl' => (int) $parts[1],
                'type' => $parts[3],
                'value' => rtrim($parts[4], '.'),
            ];
        }

        return $records;
    }

    protected function sanitizeDomain(string $domain): ?string
    {
        // Strip protocol and path if a full URL was passed.
        $host = parse_url($domain, PHP_URL_HOST) ?: $domain;
        $host = preg_replace('/^www\./i', '', trim($host));

        if (! preg_match('/^[a-zA-Z0-9._-]+\.[a-zA-Z]{2,}$/', $host)) {
            return null;
        }

        return $host;
    }
}
