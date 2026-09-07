<?php

use Abigah\BotCopTrafficDivision\Support\Signature;
use Abigah\BotCopTrafficDivision\Tests\Support\Kit;

/**
 * Signing is the one place a hub and a prober cannot afford to differ, so none
 * of this is asserted against handwritten expectations: every case comes from
 * the conformance kit's vectors.
 */
it('reproduces every signature the kit states', function () {
    $vectors = Kit::vectors();

    expect($vectors['positive'])->not->toBeEmpty();

    foreach ($vectors['positive'] as $vector) {
        expect(Signature::compute($vector['secret'], $vector['timestamp'], $vector['body']))
            ->toBe($vector['signature'], "Vector: {$vector['name']}");

        expect(Signature::verify([$vector['secret']], $vector['timestamp'], $vector['body'], 'v1='.$vector['signature']))
            ->toBeTrue("Vector: {$vector['name']}");
    }
});

it('refuses every signature the kit says must be refused', function () {
    $vectors = Kit::vectors();

    expect($vectors['negative'])->not->toBeEmpty();

    foreach ($vectors['negative'] as $vector) {
        $signatureMatches = Signature::verify(
            [$vector['secret']],
            $vector['timestamp'],
            $vector['body'],
            'v1='.$vector['signature'],
        );

        $withinTolerance = Signature::withinTolerance(
            $vector['timestamp'],
            $vectors['skew_tolerance_seconds'],
            $vector['verifier_now'] ?? $vector['timestamp'],
        );

        expect($signatureMatches && $withinTolerance)
            ->toBeFalse("Expected to refuse: {$vector['name']} — {$vector['reason']}");
    }
});

it('accepts a body signed with the previous secret during rotation', function () {
    $current = 'the-new-secret';
    $previous = 'the-old-secret';
    $body = '{"schema":1}';

    $signedWithOld = Signature::header($previous, 1788660000, $body);

    expect(Signature::verify([$current, $previous], 1788660000, $body, $signedWithOld))->toBeTrue()
        ->and(Signature::verify([$current], 1788660000, $body, $signedWithOld))->toBeFalse();
});

it('signs an empty body as the timestamp, a dot, and nothing', function () {
    expect(Signature::compute('secret', 1788660000, ''))
        ->toBe(hash_hmac('sha256', '1788660000.', 'secret'));
});

it('holds the skew window at five minutes in both directions', function () {
    $now = 1788660000;

    expect(Signature::withinTolerance($now - 300, 300, $now))->toBeTrue()
        ->and(Signature::withinTolerance($now + 300, 300, $now))->toBeTrue()
        ->and(Signature::withinTolerance($now - 301, 300, $now))->toBeFalse()
        ->and(Signature::withinTolerance($now + 301, 300, $now))->toBeFalse();
});
