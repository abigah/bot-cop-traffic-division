<?php

use Abigah\BotCopTrafficDivision\Models\MonitorCheck;
use Abigah\BotCopTrafficDivision\Models\MonitorCheckAggregate;
use Abigah\BotCopTrafficDivision\Models\MonitoredSite;
use Illuminate\Support\Str;

it('rolls raw checks into hourly buckets and prunes them', function () {
    $site = MonitoredSite::create(['name' => 'Site', 'owner_id' => 1]);
    $monitor = $site->monitors()->create(['url' => 'https://site.test/']);

    $hour = now()->subHours(6)->startOfHour();

    foreach ([100, 200, 300] as $index => $ms) {
        $monitor->checks()->create([
            'check_id' => (string) Str::ulid(),
            'status' => 'up',
            'response_time_ms' => $ms,
            'checked_at' => $hour->copy()->addMinutes($index * 5),
        ]);
    }

    $monitor->checks()->create([
        'check_id' => (string) Str::ulid(),
        'status' => 'down',
        'response_time_ms' => null,
        'checked_at' => $hour->copy()->addMinutes(20),
    ]);

    // Recent enough to be left alone.
    $monitor->checks()->create([
        'check_id' => (string) Str::ulid(),
        'status' => 'up',
        'response_time_ms' => 150,
        'checked_at' => now(),
    ]);

    $this->artisan('monitor-checks:aggregate')->assertSuccessful();

    $bucket = MonitorCheckAggregate::where('bucket_type', 'hourly')->first();

    expect($bucket)->not->toBeNull()
        ->and($bucket->total_checks)->toBe(4)
        ->and($bucket->up_checks)->toBe(3)
        ->and($bucket->down_checks)->toBe(1)
        ->and($bucket->avg_response_time_ms)->toBe(200)
        ->and($bucket->min_response_time_ms)->toBe(100)
        ->and($bucket->max_response_time_ms)->toBe(300)
        ->and($bucket->bucket_start->format('H:i:s'))->toBe($hour->format('H:i:s'))
        ->and(MonitorCheck::count())->toBe(1);
});

it('rolls old hourly buckets into daily ones', function () {
    $site = MonitoredSite::create(['name' => 'Site', 'owner_id' => 1]);
    $monitor = $site->monitors()->create(['url' => 'https://site.test/']);

    $day = now()->subDays(10)->startOfDay();

    foreach ([0, 1] as $hour) {
        $monitor->checkAggregates()->create([
            'bucket_type' => 'hourly',
            'bucket_start' => $day->copy()->addHours($hour),
            'avg_response_time_ms' => 100,
            'min_response_time_ms' => 50,
            'max_response_time_ms' => 200,
            'total_checks' => 12,
            'up_checks' => 12,
            'down_checks' => 0,
        ]);
    }

    $this->artisan('monitor-checks:aggregate')->assertSuccessful();

    $daily = MonitorCheckAggregate::where('bucket_type', 'daily')->first();

    expect($daily)->not->toBeNull()
        ->and($daily->total_checks)->toBe(24)
        ->and($daily->avg_response_time_ms)->toBe(100)
        ->and(MonitorCheckAggregate::where('bucket_type', 'hourly')->count())->toBe(0);
});

it('rolls up only the checks that answered', function () {
    // The rollup is destructive — raw checks are pruned once it has run — so a
    // bucket that averaged in its failures is wrong for good. A blocked check
    // returns faster than any real page, which is what makes this dangerous
    // rather than merely imprecise.
    $site = MonitoredSite::create(['name' => 'Site', 'owner_id' => 1]);
    $monitor = $site->monitors()->create(['url' => 'https://site.test/']);

    $hour = now()->subHours(6)->startOfHour();

    foreach ([['up', 400], ['up', 600], ['down', 2], ['down', 940]] as $index => [$status, $ms]) {
        $monitor->checks()->create([
            'check_id' => (string) Str::ulid(),
            'status' => $status,
            'response_time_ms' => $ms,
            'checked_at' => $hour->copy()->addMinutes($index * 5),
        ]);
    }

    $this->artisan('monitor-checks:aggregate')->assertSuccessful();

    $bucket = MonitorCheckAggregate::where('bucket_type', 'hourly')->first();

    expect($bucket->total_checks)->toBe(4)
        ->and($bucket->up_checks)->toBe(2)
        ->and($bucket->down_checks)->toBe(2)
        ->and($bucket->avg_response_time_ms)->toBe(500)
        ->and($bucket->min_response_time_ms)->toBe(400)
        ->and($bucket->max_response_time_ms)->toBe(600);
});

it('records no response time for an hour when nothing answered', function () {
    $site = MonitoredSite::create(['name' => 'Site', 'owner_id' => 1]);
    $monitor = $site->monitors()->create(['url' => 'https://site.test/']);

    $hour = now()->subHours(6)->startOfHour();

    foreach ([2, 3, 940] as $index => $ms) {
        $monitor->checks()->create([
            'check_id' => (string) Str::ulid(),
            'status' => 'down',
            'response_time_ms' => $ms,
            'checked_at' => $hour->copy()->addMinutes($index * 5),
        ]);
    }

    $this->artisan('monitor-checks:aggregate')->assertSuccessful();

    $bucket = MonitorCheckAggregate::where('bucket_type', 'hourly')->first();

    // Null, not a number that reads like a fast response. down_checks already
    // says what happened here.
    expect($bucket->down_checks)->toBe(3)
        ->and($bucket->avg_response_time_ms)->toBeNull()
        ->and($bucket->min_response_time_ms)->toBeNull()
        ->and($bucket->max_response_time_ms)->toBeNull();
});

it('weights a daily average by the checks each hour describes', function () {
    $site = MonitoredSite::create(['name' => 'Site', 'owner_id' => 1]);
    $monitor = $site->monitors()->create(['url' => 'https://site.test/']);

    $day = now()->subDays(10)->startOfDay();

    $monitor->checkAggregates()->create([
        'bucket_type' => 'hourly',
        'bucket_start' => $day->copy(),
        'avg_response_time_ms' => 100,
        'min_response_time_ms' => 100,
        'max_response_time_ms' => 100,
        'total_checks' => 10,
        'up_checks' => 10,
        'down_checks' => 0,
    ]);

    $monitor->checkAggregates()->create([
        'bucket_type' => 'hourly',
        'bucket_start' => $day->copy()->addHour(),
        'avg_response_time_ms' => 200,
        'min_response_time_ms' => 200,
        'max_response_time_ms' => 200,
        'total_checks' => 30,
        'up_checks' => 10,
        'down_checks' => 20,
    ]);

    $this->artisan('monitor-checks:aggregate')->assertSuccessful();

    $daily = MonitorCheckAggregate::where('bucket_type', 'daily')->first();

    // Ten at 100ms and ten at 200ms is 150. Weighting by the 40 total checks
    // would give 175 — an average pulled about by an hour of failures.
    expect($daily->total_checks)->toBe(40)
        ->and($daily->avg_response_time_ms)->toBe(150);
});
