<?php

namespace Abigah\BotCopTrafficDivision\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonitorCheckGap extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'monitor_id',
        'prober_id',
        'dropped_count',
        'oldest_at',
        'newest_at',
        'reported_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'dropped_count' => 'integer',
            'oldest_at' => 'datetime',
            'newest_at' => 'datetime',
            'reported_at' => 'datetime',
        ];
    }

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }
}
