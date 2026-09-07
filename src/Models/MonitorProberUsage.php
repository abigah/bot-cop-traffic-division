<?php

namespace Abigah\BotCopTrafficDivision\Models;

use Illuminate\Database\Eloquent\Model;

class MonitorProberUsage extends Model
{
    protected $table = 'monitor_prober_usage';

    protected $fillable = [
        'prober_id',
        'month',
        'checks',
        'pings',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'checks' => 'integer',
            'pings' => 'integer',
        ];
    }
}
