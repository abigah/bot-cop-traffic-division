<?php

use Abigah\BotCopTrafficDivision\Models\Monitor;
use Abigah\BotCopTrafficDivision\Models\MonitorCheck;
use Abigah\BotCopTrafficDivision\Models\MonitorCheckAggregate;
use Abigah\BotCopTrafficDivision\Models\MonitorDnsLookup;
use Abigah\BotCopTrafficDivision\Models\MonitoredSite;
use Abigah\BotCopTrafficDivision\Models\MonitorForgeSite;
use Abigah\BotCopTrafficDivision\Models\MonitorIncident;
use Abigah\BotCopTrafficDivision\Models\MonitorNotificationPreference;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The source is the table layout this package was extracted from, so this
 * fixture is built to that shape rather than to the package's own.
 */
beforeEach(function () {
    config()->set('database.connections.legacy', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);

    $legacy = DB::connection('legacy');
    $builder = Schema::connection('legacy');

    $builder->create('monitors', function ($table) {
        $table->increments('id');
        $table->integer('owner_id');
        $table->string('url');
        $table->boolean('uptime_check_enabled')->default(true);
        $table->string('look_for_string')->default('');
        $table->string('fail_for_string')->default('');
        $table->string('uptime_check_interval_in_minutes')->default(5);
        $table->string('uptime_status')->default('not yet checked');
        $table->text('uptime_check_failure_reason')->nullable();
        $table->integer('uptime_check_times_failed_in_a_row')->default(0);
        $table->timestamp('uptime_status_last_change_date')->nullable();
        $table->timestamp('uptime_last_check_date')->nullable();
        $table->timestamp('uptime_check_failed_event_fired_on_date')->nullable();
        $table->string('uptime_check_method')->default('get');
        $table->text('uptime_check_payload')->nullable();
        $table->text('uptime_check_additional_headers')->nullable();
        $table->string('uptime_check_response_checker')->nullable();
        $table->boolean('certificate_check_enabled')->default(false);
        $table->string('certificate_status')->default('not yet checked');
        $table->timestamp('certificate_expiration_date')->nullable();
        $table->string('certificate_issuer')->nullable();
        $table->string('certificate_check_failure_reason')->default('');
        $table->boolean('domain_expiry_check_enabled')->default(true);
        $table->string('domain_expiry_status')->default('not yet checked');
        $table->timestamp('domain_expiration_date')->nullable();
        $table->string('domain_registrar')->nullable();
        $table->string('domain_expiry_check_failure_reason')->default('');
        $table->timestamp('domain_expiry_notified_at')->nullable();
        $table->timestamps();
    });

    $builder->create('monitor_checks', function ($table) {
        $table->id();
        $table->unsignedInteger('monitor_id');
        $table->string('status');
        $table->unsignedInteger('response_time_ms')->nullable();
        $table->text('failure_reason')->nullable();
        $table->timestamp('checked_at');
    });

    $builder->create('monitor_incidents', function ($table) {
        $table->id();
        $table->unsignedInteger('monitor_id');
        $table->timestamp('started_at');
        $table->timestamp('resolved_at')->nullable();
        $table->unsignedInteger('duration_seconds')->nullable();
        $table->text('failure_reason')->nullable();
        $table->softDeletes();
        $table->string('dismissal_reason')->nullable();
        $table->text('dismissal_note')->nullable();
        $table->unsignedBigInteger('dismissed_by')->nullable();
        $table->timestamp('archived_at')->nullable();
        $table->unsignedBigInteger('archived_by')->nullable();
    });

    $builder->create('monitor_check_aggregates', function ($table) {
        $table->id();
        $table->unsignedInteger('monitor_id');
        $table->string('bucket_type', 10);
        $table->timestamp('bucket_start');
        $table->unsignedInteger('avg_response_time_ms')->nullable();
        $table->unsignedInteger('min_response_time_ms')->nullable();
        $table->unsignedInteger('max_response_time_ms')->nullable();
        $table->unsignedInteger('total_checks')->default(0);
        $table->unsignedInteger('up_checks')->default(0);
        $table->unsignedInteger('down_checks')->default(0);
    });

    $builder->create('dns_lookups', function ($table) {
        $table->id();
        $table->unsignedInteger('monitor_id');
        $table->string('domain');
        $table->json('records');
        $table->timestamp('looked_up_at');
    });

    $builder->create('forge_sites', function ($table) {
        $table->id();
        $table->unsignedInteger('monitor_id');
        $table->unsignedBigInteger('forge_server_id');
        $table->unsignedBigInteger('forge_site_id');
        $table->string('server_name');
        $table->string('site_name');
        $table->timestamps();
    });

    $builder->create('monitor_notification_preferences', function ($table) {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->unsignedInteger('monitor_id');
        $table->boolean('email_enabled')->default(true);
        $table->boolean('database_enabled')->default(true);
        $table->boolean('sms_enabled')->default(false);
        $table->boolean('uptime_failed')->default(true);
        $table->boolean('uptime_recovered')->default(true);
        $table->boolean('certificate_failed')->default(true);
        $table->boolean('certificate_expires_soon')->default(true);
        $table->boolean('domain_expires_soon')->default(true);
        $table->timestamps();
    });

    // Uniform keys: a batch insert cannot have a different shape per row.
    $monitor = fn (array $overrides) => array_merge([
        'look_for_string' => '',
        'uptime_check_enabled' => true,
        'uptime_check_interval_in_minutes' => 5,
        'uptime_status' => 'up',
    ], $overrides);

    $legacy->table('monitors')->insert([
        $monitor(['id' => 4, 'owner_id' => 3, 'url' => 'https://acme.test', 'look_for_string' => 'Acme Corporation']),
        $monitor(['id' => 62, 'owner_id' => 3, 'url' => 'https://acme.test/up']),
        $monitor(['id' => 141, 'owner_id' => 3, 'url' => 'https://familylifecanada.com', 'uptime_status' => 'down', 'uptime_check_enabled' => false]),
        $monitor(['id' => 900, 'owner_id' => 5, 'url' => 'https://someone-else.test']),
    ]);

    $legacy->table('monitor_checks')->insert([
        ['monitor_id' => 4, 'status' => 'up', 'response_time_ms' => 210, 'checked_at' => now()->subMinutes(10)],
        ['monitor_id' => 4, 'status' => 'up', 'response_time_ms' => 190, 'checked_at' => now()->subMinutes(5)],
        ['monitor_id' => 4, 'status' => 'up', 'response_time_ms' => 400, 'checked_at' => now()->subDays(90)],
        ['monitor_id' => 900, 'status' => 'up', 'response_time_ms' => 100, 'checked_at' => now()->subMinutes(5)],
    ]);

    $legacy->table('monitor_incidents')->insert([
        ['monitor_id' => 141, 'started_at' => now()->subHours(3), 'resolved_at' => now()->subHours(2), 'duration_seconds' => 3600, 'failure_reason' => 'Connection timed out'],
        ['monitor_id' => 141, 'started_at' => now()->subMinutes(30), 'resolved_at' => null, 'duration_seconds' => null, 'failure_reason' => 'Still down'],
    ]);

    $legacy->table('monitor_check_aggregates')->insert([
        ['monitor_id' => 4, 'bucket_type' => 'daily', 'bucket_start' => now()->subDays(30)->startOfDay(), 'avg_response_time_ms' => 205, 'min_response_time_ms' => 100, 'max_response_time_ms' => 900, 'total_checks' => 288, 'up_checks' => 287, 'down_checks' => 1],
    ]);

    $legacy->table('dns_lookups')->insert([
        ['monitor_id' => 4, 'domain' => 'acme.test', 'records' => json_encode([['type' => 'A', 'name' => 'acme.test', 'value' => '10.0.0.1', 'ttl' => 300]]), 'looked_up_at' => now()->subDays(2)],
        ['monitor_id' => 4, 'domain' => 'acme.test', 'records' => json_encode([['type' => 'A', 'name' => 'acme.test', 'value' => '10.0.0.9', 'ttl' => 300]]), 'looked_up_at' => now()->subDay()],
        ['monitor_id' => 900, 'domain' => 'someone-else.test', 'records' => json_encode([]), 'looked_up_at' => now()],
    ]);

    $legacy->table('forge_sites')->insert([
        ['monitor_id' => 4, 'forge_server_id' => 7, 'forge_site_id' => 42, 'server_name' => 'web-1', 'site_name' => 'acme.test'],
        ['monitor_id' => 62, 'forge_server_id' => 7, 'forge_site_id' => 43, 'server_name' => 'web-1', 'site_name' => 'health'],
        ['monitor_id' => 900, 'forge_server_id' => 9, 'forge_site_id' => 99, 'server_name' => 'web-9', 'site_name' => 'someone-else.test'],
    ]);

    $legacy->table('monitor_notification_preferences')->insert([
        ['user_id' => 7, 'monitor_id' => 4, 'sms_enabled' => true, 'uptime_recovered' => false],
    ]);
});

it('imports one owner without touching the rest', function () {
    $this->artisan('monitoring:import:legacy', ['--connection' => 'legacy', '--owner' => [3]])
        ->assertSuccessful();

    expect(Monitor::count())->toBe(3)
        ->and(Monitor::pluck('url')->all())->not->toContain('https://someone-else.test');
});

it('groups the imported monitors into sites by host', function () {
    $this->artisan('monitoring:import:legacy', ['--connection' => 'legacy', '--owner' => [3]])
        ->assertSuccessful();

    expect(MonitoredSite::count())->toBe(2)
        ->and(MonitoredSite::where('name', 'acme.test')->first()->monitors)->toHaveCount(2)
        ->and(MonitoredSite::where('name', 'familylifecanada.com')->first()->monitors)->toHaveCount(1);
});

it('carries the monitor settings across', function () {
    $this->artisan('monitoring:import:legacy', ['--connection' => 'legacy', '--owner' => [3]])
        ->assertSuccessful();

    $homepage = Monitor::where('url', 'https://acme.test')->first();
    $disabled = Monitor::where('url', 'https://familylifecanada.com')->first();

    expect($homepage->look_for_string)->toBe('Acme Corporation')
        ->and($homepage->uptime_check_interval_in_minutes)->toBe(5)
        ->and($homepage->uptime_status)->toBe('up')
        ->and($disabled->uptime_check_enabled)->toBeFalse();
});

/**
 * The old model had no critical flag: every monitor was equally load-bearing.
 * Narrowing what "the site is down" means should be a decision somebody makes,
 * not something inherited from a default.
 */
it('marks everything imported as critical', function () {
    $this->artisan('monitoring:import:legacy', ['--connection' => 'legacy', '--owner' => [3]])
        ->assertSuccessful();

    expect(Monitor::where('critical', false)->count())->toBe(0);
});

it('brings recent checks and leaves the ancient ones behind', function () {
    $this->artisan('monitoring:import:legacy', ['--connection' => 'legacy', '--owner' => [3], '--checks-days' => 30])
        ->assertSuccessful();

    expect(MonitorCheck::count())->toBe(2);
});

/**
 * A ULID's leading bits are its timestamp, which is what lets a cursor resume
 * and a replay stay ordered. Minting imported ids from now would put a year of
 * history after everything that comes next.
 */
it('mints check ids from when the check was taken', function () {
    $this->artisan('monitoring:import:legacy', ['--connection' => 'legacy', '--owner' => [3]])
        ->assertSuccessful();

    $checks = MonitorCheck::orderBy('checked_at')->get();

    expect($checks)->toHaveCount(2)
        ->and(strcmp($checks[0]->check_id, $checks[1]->check_id))->toBeLessThan(0);
});

it('brings incidents across, open ones included', function () {
    $this->artisan('monitoring:import:legacy', ['--connection' => 'legacy', '--owner' => [3]])
        ->assertSuccessful();

    $site = MonitoredSite::where('name', 'familylifecanada.com')->first();

    expect(MonitorIncident::count())->toBe(2)
        ->and(MonitorIncident::ongoing()->count())->toBe(1)
        ->and(MonitorIncident::first()->site_id)->toBe($site->id);
});

it('brings aggregates and preferences across', function () {
    $this->artisan('monitoring:import:legacy', ['--connection' => 'legacy', '--owner' => [3]])
        ->assertSuccessful();

    expect(MonitorCheckAggregate::count())->toBe(1)
        ->and(MonitorCheckAggregate::first()->total_checks)->toBe(288)
        ->and(MonitorNotificationPreference::count())->toBe(1)
        ->and(MonitorNotificationPreference::first()->notifiable_id)->toBe(7)
        ->and(MonitorNotificationPreference::first()->sms_enabled)->toBeTrue()
        ->and(MonitorNotificationPreference::first()->uptime_recovered)->toBeFalse();
});

/**
 * A migration you can run twice and abandon is worth more than one that is
 * faster.
 */
it('can be run twice without duplicating anything', function () {
    $run = fn () => $this->artisan('monitoring:import:legacy', ['--connection' => 'legacy', '--owner' => [3]])
        ->assertSuccessful();

    $run();
    $run();

    expect(Monitor::count())->toBe(3)
        ->and(MonitoredSite::count())->toBe(2)
        ->and(MonitorCheck::count())->toBe(2)
        ->and(MonitorIncident::count())->toBe(2)
        ->and(MonitorCheckAggregate::count())->toBe(1)
        ->and(MonitorNotificationPreference::count())->toBe(1);
});

it('writes nothing on a dry run', function () {
    $this->artisan('monitoring:import:legacy', ['--connection' => 'legacy', '--owner' => [3], '--dry-run' => true])
        ->assertSuccessful();

    expect(Monitor::count())->toBe(0)
        ->and(MonitoredSite::count())->toBe(0)
        ->and(MonitorCheck::count())->toBe(0);
});

it('can reassign the owner on the way in', function () {
    $this->artisan('monitoring:import:legacy', ['--connection' => 'legacy', '--owner' => [3], '--as-owner' => 10])
        ->assertSuccessful();

    expect(Monitor::pluck('owner_id')->unique()->all())->toBe([10]);
});

it('leaves the source untouched', function () {
    $this->artisan('monitoring:import:legacy', ['--connection' => 'legacy', '--owner' => [3]])
        ->assertSuccessful();

    expect(DB::connection('legacy')->table('monitors')->count())->toBe(4)
        ->and(DB::connection('legacy')->table('monitor_checks')->count())->toBe(4);
});

it('needs a connection to read from', function () {
    $this->artisan('monitoring:import:legacy')->assertFailed();
});

/**
 * The pagination bug this exists to prevent.
 *
 * Every column the import pages by ties: an hourly bucket_start repeats once
 * per monitor, so a 31-monitor import has 31 rows sharing each value. An OFFSET
 * query has no defined order between pages when the sort key ties, so a row can
 * land on both pages or on neither — and landing on neither was silent, because
 * the summary counted what it had iterated, which always agrees with itself.
 *
 * Thirty monitors share one bucket_start here, read ten at a time, so the tie
 * spans three page boundaries.
 */
it('imports every row when a sort value ties across page boundaries', function () {
    $legacy = DB::connection('legacy');
    $bucket = now()->subDays(20)->startOfHour();

    $monitors = [];
    $aggregates = [];

    foreach (range(100, 129) as $id) {
        $monitors[] = [
            'id' => $id,
            'owner_id' => 3,
            'url' => "https://tied-{$id}.test",
            'look_for_string' => '',
            'uptime_check_enabled' => true,
            'uptime_check_interval_in_minutes' => 5,
            'uptime_status' => 'up',
        ];

        // Identical bucket_start on every row: only id can order them.
        $aggregates[] = [
            'monitor_id' => $id,
            'bucket_type' => 'hourly',
            'bucket_start' => $bucket,
            'avg_response_time_ms' => 100,
            'min_response_time_ms' => 50,
            'max_response_time_ms' => 200,
            'total_checks' => 12,
            'up_checks' => 12,
            'down_checks' => 0,
        ];
    }

    $legacy->table('monitors')->insert($monitors);
    $legacy->table('monitor_check_aggregates')->insert($aggregates);

    $this->artisan('monitoring:import:legacy', [
        '--connection' => 'legacy',
        '--owner' => [3],
        '--chunk' => 10,
    ])->assertSuccessful();

    // Thirty tied rows plus the one already in the fixture.
    expect(MonitorCheckAggregate::count())->toBe(31);
});

/**
 * The summary used to report what it had iterated, so an import that skipped
 * rows still looked like a success. It now reports what the source held.
 */
it('reports what the source held, not only what it took', function () {
    $this->artisan('monitoring:import:legacy', ['--connection' => 'legacy', '--owner' => [3]])
        ->expectsOutputToContain('incidents: 2 of 2')
        ->expectsOutputToContain('aggregates: 1 of 1')
        ->assertSuccessful();
});

/**
 * Nothing regenerates a Forge link — it is a decision somebody made about which
 * Forge site a monitor covers, not an observation — so leaving it behind means
 * making it again by hand.
 */
it('brings Forge links across', function () {
    $this->artisan('monitoring:import:legacy', ['--connection' => 'legacy', '--owner' => [3]])
        ->assertSuccessful();

    expect(MonitorForgeSite::count())->toBe(2);

    $link = MonitorForgeSite::first();

    expect($link->server_name)->toBe('web-1')
        ->and($link->forge_site_id)->toBe(42)
        ->and($link->monitor->url)->toBe('https://acme.test');
});

/**
 * DNS snapshots are a history, not a derived value: nothing in the hub
 * recreates them, and a row is the record of what DNS said on a given day.
 * Keying on monitor and domain alone would collapse every snapshot into the
 * most recent one.
 */
it('brings every DNS snapshot across, not just the latest per domain', function () {
    $this->artisan('monitoring:import:legacy', ['--connection' => 'legacy', '--owner' => [3]])
        ->assertSuccessful();

    expect(MonitorDnsLookup::count())->toBe(2);

    $records = MonitorDnsLookup::orderBy('looked_up_at')->get();

    expect($records->first()->records[0]['value'])->toBe('10.0.0.1')
        ->and($records->last()->records[0]['value'])->toBe('10.0.0.9');
});

it('leaves both tables untouched for a monitor filtered out by --owner', function () {
    $this->artisan('monitoring:import:legacy', ['--connection' => 'legacy', '--owner' => [3]])
        ->assertSuccessful();

    // Owner 5's monitor has one of each in the source and neither belongs here.
    expect(MonitorForgeSite::count())->toBe(2)
        ->and(MonitorDnsLookup::count())->toBe(2)
        ->and(MonitorForgeSite::where('forge_site_id', 99)->exists())->toBeFalse()
        ->and(MonitorDnsLookup::where('domain', 'someone-else.test')->exists())->toBeFalse();
});

it('adds nothing on a second run of either table', function () {
    $run = fn () => $this->artisan('monitoring:import:legacy', ['--connection' => 'legacy', '--owner' => [3]])
        ->assertSuccessful();

    $run();
    $run();

    expect(MonitorForgeSite::count())->toBe(2)
        ->and(MonitorDnsLookup::count())->toBe(2);
});

it('reports both passes against the source count', function () {
    $this->artisan('monitoring:import:legacy', ['--connection' => 'legacy', '--owner' => [3]])
        ->expectsOutputToContain('dns lookups: 2 of 2')
        ->expectsOutputToContain('forge sites: 2 of 2')
        ->assertSuccessful();
});

/**
 * Not every source has every table — an application that never used the Forge
 * integration has no `forge_sites`, and one that came from a different package
 * may have neither. That is an absence, not a fault.
 */
it('skips a table the source does not have rather than failing the import', function () {
    Schema::connection('legacy')->drop('forge_sites');
    Schema::connection('legacy')->drop('dns_lookups');

    $this->artisan('monitoring:import:legacy', ['--connection' => 'legacy', '--owner' => [3]])
        ->expectsOutputToContain('forge_sites: not present in the source, skipped')
        ->assertSuccessful();

    // Everything else still arrived.
    expect(Monitor::count())->toBe(3)
        ->and(MonitorForgeSite::count())->toBe(0);
});
