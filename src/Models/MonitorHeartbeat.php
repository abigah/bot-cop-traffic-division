<?php

namespace Abigah\BotCopTrafficDivision\Models;

use Abigah\BotCopTrafficDivision\Enums\HeartbeatKind;
use Abigah\BotCopTrafficDivision\Enums\HeartbeatStatus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonitorHeartbeat extends Model
{
    protected $fillable = [
        'site_id',
        'name',
        'kind',
        'token',
        'job_class',
        'enabled',
        'tracks_deployments',
        'interval_minutes',
        'grace_minutes',
        'timeout_minutes',
        'status',
        'last_ping_at',
        'last_start_at',
        'last_finish_at',
        'last_message',
        'suppressed_since',
        'token_rotated_at',
    ];

    protected $hidden = [
        'token',
    ];

    /**
     * The table's defaults, repeated here so a heartbeat that has not been
     * reloaded still answers for its own kind and status.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'kind' => 'heartbeat',
        'status' => 'pending',
        'enabled' => true,
        'tracks_deployments' => false,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => HeartbeatKind::class,
            'status' => HeartbeatStatus::class,
            'enabled' => 'boolean',
            'tracks_deployments' => 'boolean',
            'interval_minutes' => 'integer',
            'grace_minutes' => 'integer',
            'timeout_minutes' => 'integer',
            'last_ping_at' => 'datetime',
            'last_start_at' => 'datetime',
            'last_finish_at' => 'datetime',
            'suppressed_since' => 'datetime',
            'token_rotated_at' => 'datetime',
        ];
    }

    /** @param  Builder<static>  $query */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(config('monitoring.site_model'), 'site_id');
    }

    public function isEvent(): bool
    {
        return $this->kind === HeartbeatKind::EVENT;
    }

    public function graceMinutes(): int
    {
        return $this->grace_minutes ?? (int) config('monitoring.heartbeats.default_grace_minutes', 10);
    }

    public function timeoutMinutes(): int
    {
        return $this->timeout_minutes ?? (int) config('monitoring.heartbeats.default_timeout_minutes', 30);
    }

    /**
     * When this heartbeat stops being merely late and starts being missing.
     *
     * A periodic heartbeat is overdue once its interval plus grace has passed
     * since the last ping; an event is overdue once its timeout has passed
     * since a start that never reported a finish. An event that is not running
     * is never overdue — nothing was expected of it.
     */
    public function overdueAt(): ?Carbon
    {
        if ($this->isEvent()) {
            if ($this->last_start_at === null || $this->status !== HeartbeatStatus::RUNNING) {
                return null;
            }

            return $this->last_start_at->copy()->addMinutes($this->timeoutMinutes());
        }

        if ($this->interval_minutes === null) {
            return null;
        }

        // Nothing has ever pinged, so the clock runs from when it was declared.
        $from = $this->last_ping_at ?? $this->created_at;

        return $from?->copy()->addMinutes($this->interval_minutes + $this->graceMinutes());
    }

    /**
     * Whether this heartbeat is waiting for its first ping since the token
     * changed.
     *
     * Until one arrives, a missed ping is this application's own doing — the
     * job is still sending the old token and cannot know better until it is
     * deployed again. The verdict is recorded either way, because a heartbeat
     * that is genuinely broken should still be visible; it just does not wake
     * anybody.
     */
    public function awaitingFirstPingSinceRotation(): bool
    {
        if ($this->token_rotated_at === null) {
            return false;
        }

        return $this->last_ping_at === null
            || $this->last_ping_at->lessThan($this->token_rotated_at);
    }

    public function isOverdue(?Carbon $now = null): bool
    {
        $overdueAt = $this->overdueAt();

        return $overdueAt !== null && ($now ?? Carbon::now())->greaterThan($overdueAt);
    }
}
