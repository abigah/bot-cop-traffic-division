<?php

use Abigah\BotCopTrafficDivision\Enums\CertificateStatus;
use Abigah\BotCopTrafficDivision\Enums\DomainExpiryStatus;
use Abigah\BotCopTrafficDivision\Enums\UptimeStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitors', function (Blueprint $table) {
            $table->id();

            /*
             | Both the site and the owner are the host's models, so both are
             | plain columns. owner_id is denormalised from the site rather than
             | joined for it: every dashboard query scopes by owner, and the
             | backfill command is what keeps the two in step.
             */
            $table->unsignedBigInteger('site_id')->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();

            $table->string('url');
            $table->string('name')->nullable();

            /*
             | A critical monitor is what "the site is down" means. Non-critical
             | monitors record checks and open their own incidents, but they
             | never suppress a heartbeat verdict and never count towards the
             | prober's emergency rule.
             */
            $table->boolean('critical')->default(true);

            $table->boolean('uptime_check_enabled')->default(true);
            $table->string('look_for_string')->default('');
            $table->string('fail_for_string')->default('');
            $table->unsignedSmallInteger('uptime_check_interval_in_minutes')->default(5);
            $table->string('uptime_status')->default(UptimeStatus::NOT_YET_CHECKED->value);
            $table->text('uptime_check_failure_reason')->nullable();
            $table->unsignedInteger('uptime_check_times_failed_in_a_row')->default(0);
            $table->timestamp('uptime_status_last_change_date')->nullable();
            $table->timestamp('uptime_last_check_date')->nullable();
            $table->timestamp('uptime_check_failed_event_fired_on_date')->nullable();
            $table->string('uptime_check_method')->default('get');
            $table->text('uptime_check_payload')->nullable();
            $table->text('uptime_check_additional_headers')->nullable();
            $table->string('uptime_check_response_checker')->nullable();

            /*
             | Recorded when a check comes back with cf-cache-status: HIT or a
             | non-zero Age. A cached 200 is a check that never reached the
             | origin, so it is a misconfiguration to surface rather than a
             | success to believe — see the /up rule in the setup guide.
             */
            $table->timestamp('served_from_cache_at')->nullable();

            /*
             | What the prober is actually checking this monitor on, when that
             | differs from what was asked for. The prober is authoritative
             | about its own tiers, so a request for faster checking than the
             | tenant pays for comes back as a clamp rather than being silently
             | ignored.
             */
            $table->unsignedSmallInteger('clamped_interval_minutes')->nullable();
            $table->string('clamped_reason')->nullable();

            // Certificate, domain-expiry and DNS checks stay on this side in
            // both checker modes: a prober performs uptime checks and nothing else.
            $table->boolean('certificate_check_enabled')->default(false);
            $table->string('certificate_status')->default(CertificateStatus::NOT_YET_CHECKED->value);
            $table->timestamp('certificate_expiration_date')->nullable();
            $table->string('certificate_issuer')->nullable();
            $table->string('certificate_check_failure_reason')->default('');

            $table->boolean('domain_expiry_check_enabled')->default(true);
            $table->string('domain_expiry_status')->default(DomainExpiryStatus::NOT_YET_CHECKED->value);
            $table->timestamp('domain_expiration_date')->nullable();
            $table->string('domain_registrar')->nullable();
            $table->string('domain_expiry_check_failure_reason')->default('');
            $table->timestamp('domain_expiry_notified_at')->nullable();

            $table->timestamps();

            /*
             | Unique per site rather than globally: one extranet can hold two
             | clients whose sites legitimately watch the same public URL, and a
             | global unique would let whichever was created first block the
             | other.
             */
            $table->unique(['site_id', 'url']);
            $table->index('owner_id');
            $table->index(['site_id', 'critical']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitors');
    }
};
