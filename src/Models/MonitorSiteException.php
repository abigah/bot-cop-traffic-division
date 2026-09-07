<?php

namespace Abigah\BotCopTrafficDivision\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One fingerprint of server error on one site, with a count.
 *
 * A fingerprint is new the first time it is seen and after it is resolved; new
 * is what notifies. Everything else is a number going up, reported on the
 * delivery cadence.
 */
class MonitorSiteException extends Model
{
    protected $fillable = [
        'site_id',
        'fingerprint',
        'exception_class',
        'message',
        'file',
        'line',
        'trace',
        'first_seen_at',
        'last_seen_at',
        'occurrences',
        'notified_at',
        'resolved_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'line' => 'integer',
            'occurrences' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'notified_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /** @param  Builder<static>  $query */
    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(config('monitoring.site_model'), 'site_id');
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }

    /**
     * Mark this fingerprint dealt with, so a recurrence after the fix counts as
     * new rather than disappearing into a count that has been climbing for a
     * month. A deploy finishing does the same thing.
     */
    public function resolve(): void
    {
        $this->update(['resolved_at' => now()]);
    }
}
