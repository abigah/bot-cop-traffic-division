<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitor_check_aggregates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitor_id')->constrained('monitors')->cascadeOnDelete();
            $table->string('bucket_type', 10); // hourly, daily
            $table->timestamp('bucket_start');
            $table->unsignedInteger('avg_response_time_ms')->nullable();
            $table->unsignedInteger('min_response_time_ms')->nullable();
            $table->unsignedInteger('max_response_time_ms')->nullable();
            $table->unsignedInteger('total_checks')->default(0);
            $table->unsignedInteger('up_checks')->default(0);
            $table->unsignedInteger('down_checks')->default(0);

            $table->unique(['monitor_id', 'bucket_type', 'bucket_start'], 'mca_monitor_bucket_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_check_aggregates');
    }
};
