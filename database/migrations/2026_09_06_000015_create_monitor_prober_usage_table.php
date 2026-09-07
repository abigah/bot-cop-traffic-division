<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The prober's month-to-date counters, kept here so there is a durable copy.
 *
 * The prober's own state is rebuildable and deliberately forgettable; a bill is
 * not. Each delivery carries the figures and this is where they land.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitor_prober_usage', function (Blueprint $table) {
            $table->id();
            $table->string('prober_id');
            $table->char('month', 7); // YYYY-MM
            $table->unsignedBigInteger('checks')->default(0);
            $table->unsignedBigInteger('pings')->default(0);
            $table->timestamps();

            $table->unique(['prober_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_prober_usage');
    }
};
