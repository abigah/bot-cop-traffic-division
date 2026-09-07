<?php

namespace Abigah\BotCopTrafficDivision\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

class MonitorProberStatus extends Model
{
    protected $table = 'monitor_prober_status';

    protected $fillable = [
        'prober_id',
        'location',
        'last_seen_at',
        'last_results_at',
        'last_heartbeats_at',
        'last_exceptions_at',
        'last_manifest_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'last_results_at' => 'datetime',
            'last_heartbeats_at' => 'datetime',
            'last_exceptions_at' => 'datetime',
            'last_manifest_at' => 'datetime',
        ];
    }

    /**
     * Whether this prober has gone quiet.
     *
     * A prober reconciles on its own schedule whether or not anything is due,
     * so silence is not "nothing to report" — it is a prober that has stopped,
     * or one that can no longer reach here. Either is worth knowing.
     */
    public function isSilent(?CarbonInterface $now = null): bool
    {
        if ($this->last_seen_at === null) {
            return true;
        }

        $after = (int) config('monitoring.prober_silence_after_minutes', 90);

        return $this->last_seen_at->diffInMinutes($now ?? now(), absolute: true) >= $after;
    }
}
