<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A mute for a while, rather than only until the outage ends.
 *
 * An empty expiry keeps the original meaning — quiet until recovery — so every
 * mute recorded before this column existed carries on unchanged. A mute whose
 * expiry has passed is inactive from that moment, whether or not anything has
 * deleted the row yet: readers compare against the clock rather than trusting
 * a cleanup to have run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitor_incident_notification_mutes', function (Blueprint $table) {
            $table->timestamp('muted_until')->nullable()->after('notifiable_id');

            // Active mutes are always read for one incident at a time, with
            // the expiry as the filter, so the expiry follows the incident in
            // the index. Named explicitly: the generated name is longer than
            // MySQL allows.
            $table->index(['monitor_incident_id', 'muted_until'], 'monitor_incident_mutes_active_index');
        });
    }

    public function down(): void
    {
        Schema::table('monitor_incident_notification_mutes', function (Blueprint $table) {
            $table->dropIndex('monitor_incident_mutes_active_index');
            $table->dropColumn('muted_until');
        });
    }
};
