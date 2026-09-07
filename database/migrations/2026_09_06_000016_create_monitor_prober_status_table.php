<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What has actually been heard from each prober.
 *
 * The list of probers is configuration — adding one is a deploy, not a
 * registration flow — but whether a configured prober is still talking is an
 * observation, and observations belong in a table.
 *
 * This matters the moment there are two. One prober going quiet while the other
 * keeps delivering looks exactly like everything being fine: the checks still
 * arrive, the dashboard stays green, and half the redundancy that was paid for
 * is gone with nothing to say so.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitor_prober_status', function (Blueprint $table) {
            $table->id();
            $table->string('prober_id')->unique();

            // A label the prober declares and this side only stores and
            // displays. Nothing infers anything from it.
            $table->string('location')->nullable();

            // Any authenticated request at all: this is reachability, not
            // productivity. A prober with nothing due still pulls a manifest.
            $table->timestamp('last_seen_at')->nullable();

            $table->timestamp('last_results_at')->nullable();
            $table->timestamp('last_heartbeats_at')->nullable();
            $table->timestamp('last_exceptions_at')->nullable();
            $table->timestamp('last_manifest_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_prober_status');
    }
};
