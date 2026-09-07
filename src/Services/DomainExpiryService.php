<?php

namespace Abigah\BotCopTrafficDivision\Services;

use Abigah\BotCopTrafficDivision\Enums\DomainExpiryStatus;
use Abigah\BotCopTrafficDivision\Exceptions\PublicSuffixListUnavailableException;
use Abigah\BotCopTrafficDivision\Support\DomainExpiryResult;
use Carbon\Carbon;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Iodev\Whois\Loaders\SocketLoader;
use Iodev\Whois\Whois;
use Pdp\Rules;
use Throwable;

class DomainExpiryService
{
    /** Socket timeout in seconds for WHOIS lookups. */
    private const SOCKET_TIMEOUT = 10;

    /**
     * TLDs without a working public WHOIS server — use RDAP instead.
     *
     * @var array<string, string>
     */
    private const RDAP_ENDPOINTS = [
        '.team' => 'https://rdap.identitydigital.services/rdap/domain/',
    ];

    /** Local path (on the default disk) where the Public Suffix List is cached. */
    private const PSL_PATH = 'public_suffix_list.dat';

    /** How often the cached Public Suffix List should be refreshed. */
    private const PSL_REFRESH_DAYS = 7;

    private const PSL_SOURCE_URL = 'https://publicsuffix.org/list/public_suffix_list.dat';

    private ?Rules $publicSuffixRules = null;

    public function check(string $url): DomainExpiryResult
    {
        try {
            $domain = $this->extractDomain($url);
        } catch (PublicSuffixListUnavailableException $e) {
            return new DomainExpiryResult(
                status: DomainExpiryStatus::NOT_YET_CHECKED,
                failureReason: $e->getMessage(),
            );
        }

        if (! $domain) {
            return new DomainExpiryResult(
                status: DomainExpiryStatus::INVALID,
                failureReason: "Could not extract domain from URL: {$url}",
            );
        }

        if (str_ends_with($domain, '.gg')) {
            return $this->checkEvergreenGg($domain);
        }

        foreach (self::RDAP_ENDPOINTS as $tld => $endpoint) {
            if (str_ends_with($domain, $tld)) {
                return $this->checkViaRdap($domain, $endpoint);
            }
        }

        try {
            $loader = new SocketLoader(self::SOCKET_TIMEOUT);
            $info = Whois::create($loader)->loadDomainInfo($domain);

            if (! $info) {
                return new DomainExpiryResult(
                    status: DomainExpiryStatus::INVALID,
                    failureReason: "No WHOIS data returned for {$domain}",
                );
            }

            $expirationDate = $info->expirationDate > 0
                ? Carbon::createFromTimestamp($info->expirationDate)
                : null;

            if (! $expirationDate) {
                return new DomainExpiryResult(
                    status: DomainExpiryStatus::INVALID,
                    registrar: $info->registrar ?: null,
                    failureReason: "No expiration date found in WHOIS data for {$domain}",
                );
            }

            $status = $expirationDate->isPast()
                ? DomainExpiryStatus::EXPIRED
                : DomainExpiryStatus::VALID;

            return new DomainExpiryResult(
                status: $status,
                expirationDate: $expirationDate,
                registrar: $info->registrar ?: null,
            );
        } catch (Throwable $e) {
            return new DomainExpiryResult(
                status: DomainExpiryStatus::INVALID,
                failureReason: $e->getMessage(),
            );
        }
    }

    /**
     * Handle .gg domains, which are evergreen ("Registered until cancelled")
     * with an annual registry-fee date rather than a real expiration.
     * The iodev/whois parser mis-reads "Registered on X" as the expiry, so we
     * query whois.gg directly and use the next occurrence of the fee date.
     */
    protected function checkEvergreenGg(string $domain): DomainExpiryResult
    {
        try {
            $raw = $this->rawWhoisQuery('whois.gg', $domain);
        } catch (Throwable $e) {
            return new DomainExpiryResult(
                status: DomainExpiryStatus::INVALID,
                failureReason: $e->getMessage(),
            );
        }

        if ($raw === '' || stripos($raw, 'Domain not found') !== false || stripos($raw, 'NOT FOUND') !== false) {
            return new DomainExpiryResult(
                status: DomainExpiryStatus::INVALID,
                failureReason: "No WHOIS data returned for {$domain}",
            );
        }

        $registrar = null;
        if (preg_match('/Registrar:\s*\n\s*(.+)/i', $raw, $m)) {
            $registrar = trim($m[1]);
        }

        if (! preg_match('/Registry fee due on\s+(\d{1,2})(?:st|nd|rd|th)?\s+([A-Za-z]+)\s+each year/i', $raw, $m)) {
            return new DomainExpiryResult(
                status: DomainExpiryStatus::INVALID,
                registrar: $registrar,
                failureReason: "No registry fee date found in WHOIS data for {$domain}",
            );
        }

        $feeDate = Carbon::createFromFormat('j F Y', sprintf('%d %s %d', (int) $m[1], $m[2], now()->year));

        if (! $feeDate) {
            return new DomainExpiryResult(
                status: DomainExpiryStatus::INVALID,
                registrar: $registrar,
                failureReason: "Could not parse registry fee date for {$domain}",
            );
        }

        if ($feeDate->isPast()) {
            $feeDate->addYear();
        }

        return new DomainExpiryResult(
            status: DomainExpiryStatus::VALID,
            expirationDate: $feeDate->startOfDay(),
            registrar: $registrar,
        );
    }

    protected function checkViaRdap(string $domain, string $endpoint): DomainExpiryResult
    {
        try {
            $response = Http::timeout(self::SOCKET_TIMEOUT)
                ->acceptJson()
                ->get($endpoint.$domain);
        } catch (Throwable $e) {
            return new DomainExpiryResult(
                status: DomainExpiryStatus::INVALID,
                failureReason: $e->getMessage(),
            );
        }

        if ($response->status() === 404) {
            return new DomainExpiryResult(
                status: DomainExpiryStatus::INVALID,
                failureReason: "Domain {$domain} not found in RDAP",
            );
        }

        if (! $response->successful()) {
            return new DomainExpiryResult(
                status: DomainExpiryStatus::INVALID,
                failureReason: "RDAP lookup failed for {$domain}: HTTP {$response->status()}",
            );
        }

        $data = $response->json();
        $registrar = $this->extractRdapRegistrar($data);

        $expirationDate = null;
        foreach ($data['events'] ?? [] as $event) {
            if (($event['eventAction'] ?? null) === 'expiration' && ! empty($event['eventDate'])) {
                $expirationDate = Carbon::parse($event['eventDate']);
                break;
            }
        }

        if (! $expirationDate) {
            return new DomainExpiryResult(
                status: DomainExpiryStatus::INVALID,
                registrar: $registrar,
                failureReason: "No expiration event in RDAP data for {$domain}",
            );
        }

        $status = $expirationDate->isPast()
            ? DomainExpiryStatus::EXPIRED
            : DomainExpiryStatus::VALID;

        return new DomainExpiryResult(
            status: $status,
            expirationDate: $expirationDate,
            registrar: $registrar,
        );
    }

    /**
     * Extract the registrar's formatted name from an RDAP response's jCard entities.
     *
     * @param  array<string, mixed>  $data
     */
    protected function extractRdapRegistrar(array $data): ?string
    {
        foreach ($data['entities'] ?? [] as $entity) {
            if (! in_array('registrar', $entity['roles'] ?? [], true)) {
                continue;
            }

            foreach ($entity['vcardArray'][1] ?? [] as $field) {
                if (($field[0] ?? null) === 'fn' && ! empty($field[3])) {
                    return $field[3];
                }
            }
        }

        return null;
    }

    protected function rawWhoisQuery(string $server, string $domain): string
    {
        $socket = @stream_socket_client(
            "tcp://{$server}:43",
            $errno,
            $errstr,
            self::SOCKET_TIMEOUT,
        );

        if (! $socket) {
            throw new \RuntimeException("Could not connect to {$server}: {$errstr}");
        }

        stream_set_timeout($socket, self::SOCKET_TIMEOUT);
        fwrite($socket, "{$domain}\r\n");

        $response = '';
        while (! feof($socket)) {
            $response .= fread($socket, 8192);
        }
        fclose($socket);

        return $response;
    }

    protected function extractDomain(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! $host) {
            return null;
        }

        $host = preg_replace('/^www\./i', '', $host);

        $rules = $this->publicSuffixRules();

        try {
            $registrable = $rules->resolve($host)->registrableDomain()->toString();
        } catch (Throwable $e) {
            throw new PublicSuffixListUnavailableException(
                "Could not resolve registrable domain for {$host}: {$e->getMessage()}",
                previous: $e,
            );
        }

        return $registrable !== '' ? $registrable : null;
    }

    protected function publicSuffixRules(): Rules
    {
        if ($this->publicSuffixRules !== null) {
            return $this->publicSuffixRules;
        }

        $disk = Storage::disk('local');

        $stale = ! $disk->exists(self::PSL_PATH)
            || Carbon::createFromTimestamp($disk->lastModified(self::PSL_PATH))
                ->addDays(self::PSL_REFRESH_DAYS)
                ->isPast();

        if ($stale) {
            $this->refreshPublicSuffixList($disk);
        }

        if (! $disk->exists(self::PSL_PATH)) {
            throw new PublicSuffixListUnavailableException(
                'Public Suffix List is unavailable — could not fetch from '.self::PSL_SOURCE_URL,
            );
        }

        try {
            return $this->publicSuffixRules = Rules::fromString($disk->get(self::PSL_PATH));
        } catch (Throwable $e) {
            throw new PublicSuffixListUnavailableException(
                'Cached Public Suffix List could not be parsed: '.$e->getMessage(),
                previous: $e,
            );
        }
    }

    protected function refreshPublicSuffixList(Filesystem $disk): void
    {
        try {
            $response = Http::timeout(self::SOCKET_TIMEOUT)->get(self::PSL_SOURCE_URL);

            if ($response->successful() && $response->body() !== '') {
                $disk->put(self::PSL_PATH, $response->body());
            }
        } catch (Throwable $e) {
            Log::warning('Failed to refresh Public Suffix List', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
