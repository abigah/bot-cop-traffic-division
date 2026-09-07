<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitor_dns_lookups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitor_id')->constrained('monitors')->cascadeOnDelete();
            $table->string('domain');
            $table->json('records');
            $table->timestamp('looked_up_at');

            $table->index(['monitor_id', 'looked_up_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_dns_lookups');
    }
};
