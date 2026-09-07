<?php

namespace Abigah\BotCopTrafficDivision\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What the package knows about a site that the host's own table does not store:
 * whether it hibernates, and the token its code pings with.
 */
class MonitorSiteSetting extends Model
{
    protected $fillable = [
        'site_id',
        'hibernates',
        'ingest_token',
    ];

    protected $hidden = [
        'ingest_token',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'hibernates' => 'boolean',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(config('monitoring.site_model'), 'site_id');
    }
}
