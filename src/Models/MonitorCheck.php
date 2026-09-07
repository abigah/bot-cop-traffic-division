<?php

namespace Abigah\BotCopTrafficDivision\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonitorCheck extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'check_id',
        'monitor_id',
        'status',
        'response_time_ms',
        'status_code',
        'failure_reason',
        'served_from_cache',
        'prober_id',
        'location',
        'disagreed',
        'checked_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'response_time_ms' => 'integer',
            'status_code' => 'integer',
            'served_from_cache' => 'boolean',
            'disagreed' => 'boolean',
            'checked_at' => 'datetime',
        ];
    }

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }

    /** Whether this check was performed by a prober rather than locally. */
    public function isRemote(): bool
    {
        return $this->prober_id !== null;
    }
}
