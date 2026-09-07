<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitor_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitor_id')->constrained('monitors')->cascadeOnDelete();

            /*
             | Denormalised from the monitor so an outage can be found by site
             | without a join. An incident still belongs to one monitor — "the
             | site's open incident", which a suppressed heartbeat or a folded
             | exception attaches to, is the earliest ongoing incident among the
             | site's critical monitors.
             */
            $table->unsignedBigInteger('site_id')->nullable();

            $table->timestamp('started_at');
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->text('failure_reason')->nullable();

            $table->string('dismissal_reason')->nullable();
            $table->text('dismissal_note')->nullable();

            // The notifiable model is the host's; these are plain columns for
            // the same reason site_id and owner_id are.
            $table->unsignedBigInteger('dismissed_by')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->unsignedBigInteger('archived_by')->nullable();

            $table->softDeletes();

            $table->index(['monitor_id', 'started_at']);
            $table->index(['site_id', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_incidents');
    }
};
