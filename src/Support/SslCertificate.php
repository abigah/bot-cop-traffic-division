<?php

namespace Abigah\BotCopTrafficDivision\Support;

use Carbon\Carbon;
use RuntimeException;

class SslCertificate
{
    /**
     * @param  array<string, mixed>  $fields  The output of openssl_x509_parse().
     */
    public function __construct(protected array $fields) {}

    public static function createForHostName(string $hostName, int $timeout = 30): self
    {
        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'SNI_enabled' => true,
                'peer_name' => $hostName,
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);

        $client = @stream_socket_client(
            "ssl://{$hostName}:443",
            $errorNumber,
            $errorDescription,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($client === false) {
            throw new RuntimeException("Could not connect to `{$hostName}`: {$errorDescription}");
        }

        $params = stream_context_get_params($client);
        $certificateResource = $params['options']['ssl']['peer_certificate'] ?? null;

        if ($certificateResource === null) {
            throw new RuntimeException("Could not read a certificate for `{$hostName}`.");
        }

        $fields = openssl_x509_parse($certificateResource);

        if ($fields === false) {
            throw new RuntimeException("Could not parse the certificate for `{$hostName}`.");
        }

        return new self($fields);
    }

    public function expirationDate(): Carbon
    {
        return Carbon::createFromTimestampUTC($this->fields['validTo_time_t']);
    }

    public function isExpired(): bool
    {
        return $this->expirationDate()->isPast();
    }

    public function getIssuer(): string
    {
        return $this->fields['issuer']['O'] ?? $this->fields['issuer']['CN'] ?? '';
    }

    /**
     * The common name plus any subjectAltName DNS entries.
     *
     * @return array<int, string>
     */
    public function getAdditionalDomains(): array
    {
        $domains = [];

        if ($commonName = $this->fields['subject']['CN'] ?? null) {
            $domains[] = $commonName;
        }

        $subjectAltName = $this->fields['extensions']['subjectAltName'] ?? '';

        foreach (array_map('trim', explode(',', $subjectAltName)) as $entry) {
            if (str_starts_with($entry, 'DNS:')) {
                $domains[] = substr($entry, 4);
            }
        }

        return array_values(array_unique(array_filter($domains)));
    }

    public function appliesToHost(string $host): bool
    {
        foreach ($this->getAdditionalDomains() as $domain) {
            if ($this->hostMatchesPattern($host, $domain)) {
                return true;
            }
        }

        return false;
    }

    public function isValid(string $host): bool
    {
        return $this->appliesToHost($host) && ! $this->isExpired();
    }

    protected function hostMatchesPattern(string $host, string $pattern): bool
    {
        $host = strtolower(rtrim($host, '.'));
        $pattern = strtolower(rtrim($pattern, '.'));

        if ($pattern === $host) {
            return true;
        }

        if (str_starts_with($pattern, '*.')) {
            $patternBase = substr($pattern, 2);
            $hostBase = preg_replace('/^[^.]+\./', '', $host);

            return $hostBase === $patternBase
                && substr_count($host, '.') === substr_count($pattern, '.');
        }

        return false;
    }
}
