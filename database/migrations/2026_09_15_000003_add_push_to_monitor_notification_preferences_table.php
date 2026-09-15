<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A fourth way to be reached, beside mail, the database and SMS.
 *
 * Push is delivered by a channel the host application supplies, so this is only
 * the switch. It starts off on every row, including every row written before it
 * existed: nobody is pushed to until they have said they want to be.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitor_notification_preferences', function (Blueprint $table) {
            $table->boolean('push_enabled')->default(false)->after('sms_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('monitor_notification_preferences', function (Blueprint $table) {
            $table->dropColumn('push_enabled');
        });
    }
};
