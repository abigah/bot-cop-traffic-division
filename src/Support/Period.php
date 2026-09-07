<?php

namespace Abigah\BotCopTrafficDivision\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;

/**
 * The windows the dashboard, history and stats are reported over.
 *
 * The hours behind each one can be tuned through `monitoring.period_hours`,
 * but the defaults live here rather than only in the config file: an
 * application that published its config before a period existed would
 * otherwise silently fall back to the wrong window.
 */
enum Period: string
{
    case Hour = '1h';
    case Day = '24h';
    case Week = '7d';
    case Month = '30d';

    /**
     * The period named by the given value, falling back to a day for anything
     * unrecognised — the value usually arrives from a query string.
     */
    public static function fromValue(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Day;
    }

    /**
     * How many hours back this period reaches.
     */
    public function hours(): int
    {
        $configured = config('monitoring.period_hours');

        if (is_array($configured) && isset($configured[$this->value]) && is_numeric($configured[$this->value])) {
            return (int) $configured[$this->value];
        }

        return $this->defaultHours();
    }

    /**
     * The moment this period starts.
     */
    public function since(): CarbonInterface
    {
        return Date::now()->subHours($this->hours());
    }

    private function defaultHours(): int
    {
        return match ($this) {
            self::Hour => 1,
            self::Day => 24,
            self::Week => 24 * 7,
            self::Month => 24 * 30,
        };
    }
}
