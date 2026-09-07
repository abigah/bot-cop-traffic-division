<?php

use Abigah\BotCopTrafficDivision\Enums\HeartbeatStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Work that pings in rather than being checked from outside.
 *
 * A `heartbeat` is periodic — a job that should run every hour — and is overdue
 * when nothing has pinged within its interval plus grace. An `event` is
 * bounded: a deploy signals start and finish, and has timed out when a start
 * has no finish inside its timeout. Both ride on the real jobs rather than a
 * synthetic one, so a site that already runs work hourly pays nothing extra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitor_heartbeats', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('site_id');

            $table->string('name');
            $table->string('kind')->default('heartbeat'); // heartbeat, event

            // Travels in a URL path from arbitrary site code, so it grants
            // nothing but the ability to say that this one heartbeat fired.
            $table->string('token', 64)->unique();

            /*
             | The job this heartbeat expects, when a JobProcessed listener is
             | doing the pinging rather than the job's own handle(). Null when
             | the ping is explicit.
             */
            $table->string('job_class')->nullable();

            $table->boolean('enabled')->default(true);

            /*
             | The event a deploy signals. A site has at most one, and the
             | deployment start/finish URLs ping it — so a deploy that opens a
             | suppression window and never closes it is noticed as work that
             | started and never finished, rather than only as alerting quietly
             | resuming half an hour later.
             */
            $table->boolean('tracks_deployments')->default(false);

            // A periodic heartbeat carries interval + grace; an event carries a
            // timeout. Neither needs the other's.
            $table->unsignedSmallInteger('interval_minutes')->nullable();
            $table->unsignedSmallInteger('grace_minutes')->nullable();
            $table->unsignedSmallInteger('timeout_minutes')->nullable();

            $table->string('status')->default(HeartbeatStatus::PENDING->value);
            $table->timestamp('last_ping_at')->nullable();
            $table->timestamp('last_start_at')->nullable();
            $table->timestamp('last_finish_at')->nullable();
            $table->text('last_message')->nullable();

            /*
             | When this heartbeat's verdicts started being swallowed by an
             | outage. A missed job during a site outage is the outage, not a
             | second page — it is attached to the incident as context and this
             | is the "since" that goes with it.
             */
            $table->timestamp('suppressed_since')->nullable();

            /*
             | When this heartbeat's token last changed.
             |
             | Rotating a token breaks every ping the job is still making with
             | the old one, and the job has done nothing wrong — it is waiting
             | for a deploy that carries the new token. Alerting on that would
             | be this application paging somebody about a thing it did itself,
             | so while this is set the verdict is still recorded and nobody is
             | told. The first ping to arrive clears it.
             */
            $table->timestamp('token_rotated_at')->nullable();

            $table->timestamps();

            $table->index(['site_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_heartbeats');
    }
};
