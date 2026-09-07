<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One recipient's overrides for one subject.
 *
 * The subject is a monitor or a site, exactly one of them. Uptime, certificate
 * and domain events belong to a monitor; a missed heartbeat and a server-error
 * storm belong to a site and have no monitor to hang from, so a table keyed by
 * monitor alone could not express "tell me when this site's jobs stop running".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitor_notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('notifiable_id');

            $table->foreignId('monitor_id')->nullable()->constrained('monitors')->cascadeOnDelete();
            $table->unsignedBigInteger('site_id')->nullable();

            $table->boolean('email_enabled')->default(true);
            $table->boolean('database_enabled')->default(true);
            $table->boolean('sms_enabled')->default(false);

            $table->boolean('uptime_failed')->default(true);
            $table->boolean('uptime_recovered')->default(true);
            $table->boolean('certificate_failed')->default(true);
            $table->boolean('certificate_expires_soon')->default(true);
            $table->boolean('domain_expires_soon')->default(true);
            $table->boolean('heartbeat_missing')->default(true);
            $table->boolean('heartbeat_recovered')->default(true);
            $table->boolean('exception_reported')->default(true);

            $table->timestamps();

            // Null compares as distinct, so each of these constrains only the
            // rows whose subject it names.
            $table->unique(['notifiable_id', 'monitor_id'], 'monitor_preferences_monitor_unique');
            $table->unique(['notifiable_id', 'site_id'], 'monitor_preferences_site_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_notification_preferences');
    }
};
