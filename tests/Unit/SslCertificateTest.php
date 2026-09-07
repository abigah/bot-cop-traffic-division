<?php

use Abigah\BotCopTrafficDivision\Support\SslCertificate;

function certificate(array $overrides = []): SslCertificate
{
    return new SslCertificate([
        'subject' => ['CN' => 'example.com'],
        'issuer' => ['O' => "Let's Encrypt", 'CN' => 'R3'],
        'validTo_time_t' => now()->addDays(60)->timestamp,
        'extensions' => ['subjectAltName' => 'DNS:example.com, DNS:www.example.com, DNS:*.api.example.com'],
        ...$overrides,
    ]);
}

it('reads the expiration date and issuer', function () {
    $cert = certificate(['validTo_time_t' => now()->addDays(30)->timestamp]);

    expect($cert->expirationDate()->isToday())->toBeFalse()
        ->and($cert->expirationDate()->diffInDays(now(), true))->toEqualWithDelta(30, 1)
        ->and($cert->getIssuer())->toBe("Let's Encrypt")
        ->and($cert->isExpired())->toBeFalse();
});

it('detects an expired certificate', function () {
    expect(certificate(['validTo_time_t' => now()->subDay()->timestamp])->isExpired())->toBeTrue();
});

it('collects the common name and subjectAltName domains', function () {
    expect(certificate()->getAdditionalDomains())
        ->toBe(['example.com', 'www.example.com', '*.api.example.com']);
});

it('matches exact and wildcard hosts but not the wrong depth', function () {
    $cert = certificate();

    expect($cert->appliesToHost('example.com'))->toBeTrue()
        ->and($cert->appliesToHost('www.example.com'))->toBeTrue()
        ->and($cert->appliesToHost('v1.api.example.com'))->toBeTrue()   // matches *.api.example.com
        ->and($cert->appliesToHost('a.b.api.example.com'))->toBeFalse() // wildcard is one label only
        ->and($cert->appliesToHost('other.com'))->toBeFalse();
});

it('is valid only when it applies to the host and is not expired', function () {
    expect(certificate()->isValid('example.com'))->toBeTrue()
        ->and(certificate(['validTo_time_t' => now()->subDay()->timestamp])->isValid('example.com'))->toBeFalse()
        ->and(certificate()->isValid('other.com'))->toBeFalse();
});
