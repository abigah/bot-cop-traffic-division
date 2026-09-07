<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Server errors a site reported about itself, folded by fingerprint.
 *
 * This is not an error tracker: no trace explorer, no source context, no
 * releases. It answers one question — is this site throwing server errors, and
 * is that new? A site that wants the rest runs Flare or Sentry as well.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitor_site_exceptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('site_id');

            // class + file + line, hashed by the reporting site.
            $table->char('fingerprint', 64);

            $table->string('exception_class');
            $table->text('message')->nullable();
            $table->string('file')->nullable();
            $table->unsignedInteger('line')->nullable();
            $table->longText('trace')->nullable();

            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->unsignedInteger('occurrences')->default(1);

            // Notified once when the fingerprint is new; a fingerprint that
            // keeps firing gets a digest on the delivery cadence, never a page
            // per occurrence.
            $table->timestamp('notified_at')->nullable();

            /*
             | Resolving a fingerprint, or a deploy finishing, resets it — so a
             | recurrence after a fix counts as new rather than disappearing
             | into a count that has been climbing for a month.
             */
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            $table->unique(['site_id', 'fingerprint'], 'monitor_site_exceptions_unique');
            $table->index(['site_id', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_site_exceptions');
    }
};
