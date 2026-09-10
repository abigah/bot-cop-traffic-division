<?php

use Abigah\BotCopTrafficDivision\Models\Monitor;
use Abigah\BotCopTrafficDivision\Models\MonitoredSite;
use Abigah\BotCopTrafficDivision\Services\MonitorChartService;
use Illuminate\Support\Str;

/*
| A failed check still carries a duration, and the two are not the same thing.
| A Cloudflare challenge refused at the edge comes back in two milliseconds —
| faster than any real page — so a monitor being blocked reads as a monitor at
| its best unless the response-time columns describe only the checks that
| answered.
|
| Drawn from a real incident: twelve 403s at 2-22ms and three 429s at 422-940ms
| produced "Uptime 0%" beside "Average 123ms", and reported a 2ms rejection as
| the site's fastest response.
*/

function monitorWith(array $checks): Monitor
{
    $site = MonitoredSite::create(['name' => 'Site', 'owner_id' => 1]);
    $monitor = $site->monitors()->create(['url' => 'https://site.test/']);

    foreach ($checks as $index => [$status, $ms]) {
        $monitor->checks()->create([
            'check_id' => (string) Str::ulid(),
            'status' => $status,
            'response_time_ms' => $ms,
            'checked_at' => now()->subMinutes(30 - $index),
        ]);
    }

    return $monitor;
}

it('describes only the checks that answered', function () {
    $monitor = monitorWith([
        ['up', 400],
        ['up', 600],
        ['down', 2],    // a challenge refused at the edge
        ['down', 940],  // a rate limiter, after a real round trip
    ]);

    $stats = app(MonitorChartService::class)->stats([$monitor->id], '1h', now()->subHour());

    expect($stats['avg_ms'])->toBe(500)
        ->and($stats['min_ms'])->toBe(400)
        ->and($stats['max_ms'])->toBe(600);
});

it('still counts a failed check against uptime', function () {
    $monitor = monitorWith([
        ['up', 400],
        ['down', 2],
    ]);

    $stats = app(MonitorChartService::class)->stats([$monitor->id], '1h', now()->subHour());

    expect($stats['uptime'])->toBe(50.0)
        ->and($stats['total'])->toBe(2)
        ->and($stats['up'])->toBe(1)
        ->and($stats['down'])->toBe(1);
});

it('has no response time to report when nothing answered', function () {
    // The case that made this visible: every check blocked, so there is no
    // response to describe. Zero says that; 123ms would not.
    $monitor = monitorWith([
        ['down', 2],
        ['down', 3],
        ['down', 940],
    ]);

    $stats = app(MonitorChartService::class)->stats([$monitor->id], '1h', now()->subHour());

    expect($stats['uptime'])->toBe(0.0)
        ->and($stats['avg_ms'])->toBe(0)
        ->and($stats['min_ms'])->toBe(0)
        ->and($stats['max_ms'])->toBe(0);
});

it('weights a rolled-up average by the checks it describes', function () {
    // A mean of means. Weighting by total_checks counts buckets full of
    // failures as though they had contributed response times, which drags the
    // average toward whichever hour was worst.
    $site = MonitoredSite::create(['name' => 'Site', 'owner_id' => 1]);
    $monitor = $site->monitors()->create(['url' => 'https://site.test/']);

    $monitor->checkAggregates()->create([
        'bucket_type' => 'hourly',
        'bucket_start' => now()->subHours(5)->startOfHour(),
        'avg_response_time_ms' => 100,
        'min_response_time_ms' => 100,
        'max_response_time_ms' => 100,
        'total_checks' => 10,
        'up_checks' => 10,
        'down_checks' => 0,
    ]);

    $monitor->checkAggregates()->create([
        'bucket_type' => 'hourly',
        'bucket_start' => now()->subHours(4)->startOfHour(),
        'avg_response_time_ms' => 200,
        'min_response_time_ms' => 200,
        'max_response_time_ms' => 200,
        'total_checks' => 30,
        'up_checks' => 10,
        'down_checks' => 20,
    ]);

    $stats = app(MonitorChartService::class)->stats([$monitor->id], '24h', now()->subDay());

    // Ten checks at 100ms and ten at 200ms: 150. Weighting by the 40 total
    // checks instead would give 175.
    expect($stats['avg_ms'])->toBe(150);
});
