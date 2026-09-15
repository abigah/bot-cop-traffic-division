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
        'acknowledged_at',
        'acknowledged_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'resolved_at' => 'datetime',
            'archived_at' => 'datetime',
            'acknowledged_at' => 'datetime',
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
     * Every recorded choice, including ones whose time has run out: replacing a
     * choice needs the row that is there. Ask isMutedBy() whether one is live.
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
        )->withPivot('muted_until')->withTimestamps();
    }

    /**
     * Silence this outage for one recipient until the given time, or until it
     * recovers when that is null. Choosing again replaces the earlier choice
     * rather than adding a second mute beside it.
     */
    public function muteFor(Model $notifiable, ?CarbonInterface $until): void
    {
        $this->mutedBy()->syncWithoutDetaching([
            $notifiable->getKey() => ['muted_until' => $until],
        ]);
    }

    public function unmuteFor(Model $notifiable): void
    {
        $this->mutedBy()->detach($notifiable->getKey());
    }

    /**
     * Whether the recipient's mute is live at the given moment — now, unless
     * told otherwise. A mute with no expiry lasts until recovery; one whose
     * expiry has passed is over even if its row has not been cleaned up.
     */
    public function isMutedBy(Model $notifiable, ?CarbonInterface $at = null): bool
    {
        $at ??= now();

        return $this->mutedBy()
            ->whereKey($notifiable->getKey())
            ->where(function (Builder $query) use ($at): void {
                $query->whereNull('monitor_incident_notification_mutes.muted_until')
                    ->orWhere('monitor_incident_notification_mutes.muted_until', '>', $at);
            })
            ->exists();
    }

    /**
     * Record that someone has this outage in hand. The first acknowledgement
     * wins: the write only lands while nobody has acknowledged, so two people
     * answering at once, or a client retrying, cannot replace who answered or
     * when. It still lands once the outage has resolved, because an answer that
     * arrived late was still an answer.
     *
     * Returns whether this call was the one recorded; either way the model is
     * refreshed to show the acknowledgement that stands.
     */
    public function acknowledge(Model $notifiable, ?CarbonInterface $at = null): bool
    {
        $written = self::query()
            ->whereKey($this->getKey())
            ->whereNull('acknowledged_at')
            ->update([
                'acknowledged_at' => $at ?? now(),
                'acknowledged_by' => $notifiable->getKey(),
            ]);

        $this->refresh();

        return $written === 1;
    }

    /** @return BelongsTo<Model, $this> */
    public function acknowledgedByUser(): BelongsTo
    {
        return $this->belongsTo(config('monitoring.notifiable_model'), 'acknowledged_by');
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
