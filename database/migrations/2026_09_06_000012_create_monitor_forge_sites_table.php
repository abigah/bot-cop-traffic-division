<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitor_forge_sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitor_id')->constrained('monitors')->cascadeOnDelete();
            $table->unsignedBigInteger('forge_server_id');
            $table->unsignedBigInteger('forge_site_id');
            $table->string('server_name');
            $table->string('site_name');
            $table->timestamps();

            $table->unique(['monitor_id', 'forge_server_id', 'forge_site_id'], 'monitor_forge_sites_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_forge_sites');
    }
};
