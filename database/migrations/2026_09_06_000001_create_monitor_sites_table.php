<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The package's own site model, used when the host has no site concept of its
 * own. A host that does have one points `monitoring.site_model` at it instead
 * and this table simply stays empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitor_sites', function (Blueprint $table) {
            $table->id();

            // The owner model is the host's, so this is a plain column with no
            // foreign key: the package cannot know what table it points at.
            $table->unsignedBigInteger('owner_id')->nullable();

            $table->string('name');
            $table->timestamps();

            $table->index('owner_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_sites');
    }
};
