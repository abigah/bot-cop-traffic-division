<?php

namespace Abigah\BotCopTrafficDivision\Models;

use Abigah\BotCopTrafficDivision\Concerns\HasNotificationChannels;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonitorNotificationPreference extends Model
{
    use HasNotificationChannels;

    protected $fillable = [
        'notifiable_id',
        'monitor_id',
        'site_id',
        'email_enabled',
        'database_enabled',
        'sms_enabled',
        'uptime_failed',
        'uptime_recovered',
        'certificate_failed',
        'certificate_expires_soon',
        'domain_expires_soon',
        'heartbeat_missing',
        'heartbeat_recovered',
        'exception_reported',
    ];

    /**
     * The table's defaults, repeated here so a row created by
     * `firstOrCreate()` answers for itself before it has been reloaded — which
     * is exactly when the first toggle reads it.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'email_enabled' => true,
        'database_enabled' => true,
        'sms_enabled' => false,
        'uptime_failed' => true,
        'uptime_recovered' => true,
        'certificate_failed' => true,
        'certificate_expires_soon' => true,
        'domain_expires_soon' => true,
        'heartbeat_missing' => true,
        'heartbeat_recovered' => true,
        'exception_reported' => true,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_enabled' => 'boolean',
            'database_enabled' => 'boolean',
            'sms_enabled' => 'boolean',
            'uptime_failed' => 'boolean',
            'uptime_recovered' => 'boolean',
            'certificate_failed' => 'boolean',
            'certificate_expires_soon' => 'boolean',
            'domain_expires_soon' => 'boolean',
            'heartbeat_missing' => 'boolean',
            'heartbeat_recovered' => 'boolean',
            'exception_reported' => 'boolean',
        ];
    }

    public function notifiable(): BelongsTo
    {
        return $this->belongsTo(config('monitoring.notifiable_model'), 'notifiable_id');
    }

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(config('monitoring.site_model'), 'site_id');
    }
}
