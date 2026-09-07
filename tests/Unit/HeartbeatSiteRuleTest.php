<?php

use Abigah\BotCopTrafficDivision\Support\HeartbeatSiteRule;
use Abigah\BotCopTrafficDivision\Tests\Support\Kit;
use Illuminate\Support\Carbon;

/**
 * The rule is easy to describe in prose and easy to get subtly wrong in code,
 * which is why the kit carries it as data. Nothing here is asserted against a
 * hand-written expectation: the prober binds its own implementation to these
 * same cases, so both sides reach the same answer by construction rather than
 * by both having read the same paragraph.
 */
it('agrees with every case in the kit', function () {
    $fixture = Kit::fixture('heartbeat-site-rule');
    $rule = new HeartbeatSiteRule;

    expect($fixture['cases'])->not->toBeEmpty();

    foreach ($fixture['cases'] as $case) {
        $given = $case['given'];

        $result = $rule->judge(
            $given['signal'],
            $given['site'],
            Carbon::parse($given['now']),
            $given['extranet_reachable'],
        );

        expect($result['action'])->toBe($case['expect']['action'], "Case: {$case['name']}");

        if (isset($case['expect']['verdict_status'])) {
            expect($result['verdict_status'] ?? null)
                ->toBe($case['expect']['verdict_status'], "Case: {$case['name']}");
        }
    }
});

it('takes its freshness threshold from the kit', function () {
    expect(HeartbeatSiteRule::SITE_STATUS_FRESH_SECONDS)
        ->toBe(Kit::fixture('heartbeat-site-rule')['site_status_fresh_seconds']);
});
