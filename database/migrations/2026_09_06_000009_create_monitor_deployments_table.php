<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A deployment window. While one is underway every monitor on the site still
 * records its checks, but the consecutive-failure counter is held at zero and
 * no incident opens, so a restart-induced blip does not page anyone.
 *
 * The window belongs to the site — one pair of start/finish URLs per deploy
 * target, rather than one pair per monitor. `monitor_id` is kept so the
 * per-monitor URLs an existing deploy script already holds keep working while
 * it is being moved over.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitor_deployments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('site_id')->nullable();
            $table->foreignId('monitor_id')->nullable()->constrained('monitors')->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'started_at']);
            $table->index(['monitor_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_deployments');
    }
};
