<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A recipient silencing one outage they already know about.
 *
 * A mute covers every channel this package notifies through, not email alone:
 * heartbeat verdicts and exception reports route through the same subscriber as
 * uptime events, so a mute that only stopped mail would let the same outage
 * page someone by SMS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitor_incident_notification_mutes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitor_incident_id')->constrained('monitor_incidents')->cascadeOnDelete();
            $table->unsignedBigInteger('notifiable_id');
            $table->timestamps();

            $table->unique(['monitor_incident_id', 'notifiable_id'], 'monitor_incident_mutes_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_incident_notification_mutes');
    }
};
