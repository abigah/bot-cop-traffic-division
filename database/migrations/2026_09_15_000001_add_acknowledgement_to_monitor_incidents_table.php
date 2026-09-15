<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Someone on the team saying "I have this one".
 *
 * The first acknowledgement wins and is kept: a later or retried one must not
 * replace who answered or when, so the model writes these columns only while
 * they are still empty. An acknowledgement that arrives after recovery is still
 * recorded, since someone did answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitor_incidents', function (Blueprint $table) {
            $table->timestamp('acknowledged_at')->nullable();

            // The notifiable model is the host's, so this is a plain column
            // like dismissed_by and archived_by rather than a foreign key.
            $table->unsignedBigInteger('acknowledged_by')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('monitor_incidents', function (Blueprint $table) {
            $table->dropIndex(['acknowledged_by']);
            $table->dropColumn(['acknowledged_at', 'acknowledged_by']);
        });
    }
};
