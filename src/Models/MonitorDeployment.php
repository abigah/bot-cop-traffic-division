<?php

namespace Abigah\BotCopTrafficDivision\Models;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonitorDeployment extends Model
{
    protected $fillable = [
        'site_id',
        'monitor_id',
        'started_at',
        'finished_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * Deployments still underway: not yet finished, and started within the
     * maximum window so a missing "finish" signal cannot suppress alerting
     * forever.
     *
     * @param  Builder<static>  $query
     */
    public function scopeOngoing(Builder $query): Builder
    {
        return $query->whereNull('finished_at')
            ->where('started_at', '>=', Carbon::now()->subMinutes(static::maxWindowMinutes()));
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(config('monitoring.site_model'), 'site_id');
    }

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }

    public function isOngoing(): bool
    {
        return $this->finished_at === null
            && $this->started_at->greaterThanOrEqualTo(Carbon::now()->subMinutes(static::maxWindowMinutes()));
    }

    public function getDurationForHumansAttribute(): string
    {
        return $this->started_at->diffForHumans(
            $this->finished_at ?? Carbon::now(),
            CarbonInterface::DIFF_ABSOLUTE,
            true,
        );
    }

    protected static function maxWindowMinutes(): int
    {
        return (int) config('monitoring.deployment.max_window_minutes', 30);
    }
}
