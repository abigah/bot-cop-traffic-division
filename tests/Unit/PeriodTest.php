<?php

use Abigah\BotCopTrafficDivision\Support\Period;

it('reaches back the right distance for each period', function () {
    expect(Period::Hour->hours())->toBe(1)
        ->and(Period::Day->hours())->toBe(24)
        ->and(Period::Week->hours())->toBe(24 * 7)
        ->and(Period::Month->hours())->toBe(24 * 30);
});

it('keeps its defaults when the config predates a period', function () {
    // An application that published its config before 7d and 30d existed would
    // otherwise report a week and a month over the last 24 hours.
    config()->set('monitoring.period_hours', ['1h' => 1, '24h' => 24]);

    expect(Period::Week->hours())->toBe(24 * 7)
        ->and(Period::Month->hours())->toBe(24 * 30);
});

it('lets an application tune a period', function () {
    config()->set('monitoring.period_hours', ['30d' => 24 * 45]);

    expect(Period::Month->hours())->toBe(24 * 45);
});

it('falls back to a day for an unrecognised value', function () {
    expect(Period::fromValue('nonsense'))->toBe(Period::Day)
        ->and(Period::fromValue(null))->toBe(Period::Day)
        ->and(Period::fromValue('7d'))->toBe(Period::Week);
});
