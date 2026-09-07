<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Everything the package needs to know about a site that the host's own site
 * model has no reason to store.
 *
 * It lives here rather than as columns on the site because the site model is
 * the host's: one belongs to a Client, another to a Project, and neither
 * table is the package's to alter. One row per site, created on demand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitor_site_settings', function (Blueprint $table) {
            $table->id();

            // The site model is the host's, so this is a plain column with no
            // foreign key — the same way a monitor carries an owner_id.
            $table->unsignedBigInteger('site_id');

            /*
             | A site that hibernates is only awake when something wakes it, so
             | every uptime check costs a cold start. Its monitors are floored
             | at `uptime.hibernating_interval_minutes` and heartbeats are what
             | cover the gap between them.
             */
            $table->boolean('hibernates')->default(false);

            /*
             | Authenticates this site's pings and exception reports. It travels
             | in a URL path from arbitrary site code — a queue worker, a deploy
             | script — which is why it grants nothing but the ability to say
             | that one heartbeat fired or that one site threw an error.
             */
            $table->string('ingest_token', 64)->unique();

            $table->timestamps();

            $table->unique('site_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_site_settings');
    }
};
