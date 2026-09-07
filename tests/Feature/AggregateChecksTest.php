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
