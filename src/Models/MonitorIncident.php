<?php

namespace Abigah\BotCopTrafficDivision\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MonitorIncident extends Model
{
    use SoftDeletes;

    public $timestamps = false;

    protected $fillable = [
        'monitor_id',
        'site_id',
        'started_at',
        'resolved_at',
        'duration_seconds',
        'failure_reason',
        'dismissal_reason',
        'dismissal_note',
        'dismissed_by',
        'archived_at',
        'archived_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'resolved_at' => 'datetime',
            'archived_at' => 'datetime',
            'deleted_at' => 'datetime',
            'duration_seconds' => 'integer',
        ];
    }

    /** @param  Builder<static>  $query */
    public function scopeOngoing(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    /** @param  Builder<static>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(config('monitoring.site_model'), 'site_id');
    }

    /**
     * Recipients who have silenced this outage. A mute covers every channel the
     * package notifies through, not mail alone — an outage that stopped mailing
     * but kept sending SMS would not be muted in any sense the recipient meant.
     *
     * @return BelongsToMany<Model, $this>
     */
    public function mutedBy(): BelongsToMany
    {
        return $this->belongsToMany(
            config('monitoring.notifiable_model'),
            'monitor_incident_notification_mutes',
            'monitor_incident_id',
            'notifiable_id',
        )->withTimestamps();
    }

    public function isMutedBy(Model $notifiable): bool
    {
        return $this->mutedBy()->whereKey($notifiable->getKey())->exists();
    }

    public function dismissedByUser(): BelongsTo
    {
        return $this->belongsTo(config('monitoring.notifiable_model'), 'dismissed_by');
    }

    public function archivedByUser(): BelongsTo
    {
        return $this->belongsTo(config('monitoring.notifiable_model'), 'archived_by');
    }

    public function isOngoing(): bool
    {
        return $this->resolved_at === null;
    }

    public function getDurationForHumansAttribute(): string
    {
        return $this->started_at->diffForHumans(
            $this->resolved_at ?? now(),
            CarbonInterface::DIFF_ABSOLUTE,
            true,
        );
    }

    /**
     * Parse a Guzzle-style failure reason into a status code and description.
     *
     * @return array{code: string|null, description: string}
     */
    public static function parseFailureReason(?string $reason): array
    {
        if (! $reason) {
            return ['code' => null, 'description' => ''];
        }

        if (preg_match('/`(\d{3})\s+([^`]+)`/', $reason, $matches)) {
            return ['code' => $matches[1], 'description' => trim($matches[2])];
        }

        return ['code' => null, 'description' => $reason];
    }
}
