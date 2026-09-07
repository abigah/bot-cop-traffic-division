<?php

namespace Abigah\BotCopTrafficDivision\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonitorCheckAggregate extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'monitor_id',
        'bucket_type',
        'bucket_start',
        'avg_response_time_ms',
        'min_response_time_ms',
        'max_response_time_ms',
        'total_checks',
        'up_checks',
        'down_checks',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'bucket_start' => 'datetime',
            'avg_response_time_ms' => 'integer',
            'min_response_time_ms' => 'integer',
            'max_response_time_ms' => 'integer',
            'total_checks' => 'integer',
            'up_checks' => 'integer',
            'down_checks' => 'integer',
        ];
    }

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }
}
