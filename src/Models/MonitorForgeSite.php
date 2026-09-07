<?php

namespace Abigah\BotCopTrafficDivision\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonitorForgeSite extends Model
{
    protected $fillable = [
        'monitor_id',
        'forge_server_id',
        'forge_site_id',
        'server_name',
        'site_name',
    ];

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }
}
