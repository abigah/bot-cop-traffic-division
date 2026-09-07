<?php

namespace Abigah\BotCopTrafficDivision\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonitorDnsLookup extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'monitor_id',
        'domain',
        'records',
        'looked_up_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'records' => 'array',
            'looked_up_at' => 'datetime',
        ];
    }

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }
}
