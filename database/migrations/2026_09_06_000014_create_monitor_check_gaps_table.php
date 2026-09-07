<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Checks that were performed but never arrived.
 *
 * A prober buffers results while a monitor is up and its buffer is capped, so a
 * long delivery outage drops the oldest and says so. The counts are all that
 * survives — the prober is not a history store and what was in the gap is
 * genuinely gone — but a gap that is visible is a gap nobody mistakes for
 * uptime.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitor_check_gaps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitor_id')->constrained('monitors')->cascadeOnDelete();
            $table->string('prober_id')->nullable();
            $table->unsignedInteger('dropped_count');
            $table->timestamp('oldest_at');
            $table->timestamp('newest_at');
            $table->timestamp('reported_at');

            $table->index(['monitor_id', 'oldest_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_check_gaps');
    }
};
