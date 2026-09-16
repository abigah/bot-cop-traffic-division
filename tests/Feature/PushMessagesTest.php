<?php

use Abigah\BotCopTrafficDivision\Concerns\IsMonitoredSite;
use Abigah\BotCopTrafficDivision\Enums\HeartbeatStatus;
use Abigah\BotCopTrafficDivision\Enums\UptimeStatus;
use Abigah\BotCopTrafficDivision\Events\CertificateCheckFailed;
use Abigah\BotCopTrafficDivision\Events\CertificateExpiresSoon;
use Abigah\BotCopTrafficDivision\Events\DomainExpiresSoon;
use Abigah\BotCopTrafficDivision\Events\HeartbeatMissed;
use Abigah\BotCopTrafficDivision\Events\HeartbeatRecovered;
use Abigah\BotCopTrafficDivision\Events\SiteExceptionReported;
use Abigah\BotCopTrafficDivision\Facades\Monitoring;
use Abigah\BotCopTrafficDivision\Listeners\MonitorEventSubscriber;
use Abigah\BotCopTrafficDivision\Models\MonitoredSite;
use Abigah\BotCopTrafficDivision\Notifications\CertificateCheckFailedNotification;
use Abigah\BotCopTrafficDivision\Notifications\CertificateExpiresSoonNotification;
use Abigah\BotCopTrafficDivision\Notifications\DomainExpiresSoonNotification;
use Abigah\BotCopTrafficDivision\Notifications\HeartbeatMissedNotification;
use Abigah\BotCopTrafficDivision\Notifications\HeartbeatRecoveredNotification;
use Abigah\BotCopTrafficDivision\Notifications\MonitoringNotification;
use Abigah\BotCopTrafficDivision\Notifications\SiteExceptionReportedNotification;
use Abigah\BotCopTrafficDivision\Notifications\UptimeCheckFailedNotification;
use Abigah\BotCopTrafficDivision\Notifications\UptimeCheckRecoveredNotification;
use Abigah\BotCopTrafficDivision\Support\CheckResult;
use Abigah\BotCopTrafficDivision\Support\PushMessage;
use Abigah\BotCopTrafficDivision\Tests\Fixtures\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 | What each kind of monitoring news says in a push.
 |
 | A push is read on a lock screen or a wrist, by whoever is holding the device,
 | after passing through a delivery provider this package knows nothing about.
 | So it says what happened and to what, briefly, and nothing else: no failure
 | output, no request data, no token, no trace and no error message, whatever
 | the subject has on record.
 */

beforeEach(function () {
    config()->set('monitoring.uptime.fire_failed_event_after_consecutive_failures', 1);

    $this->owner = User::create(['name' => 'Owner', 'email' => 'owner@test.dev']);
    $this->recipient = User::create(['name' => 'On call', 'email' => 'oncall@test.dev']);
    $this->site = MonitoredSite::create(['name' => 'Site', 'owner_id' => $this->owner->id]);

    Monitoring::resolveOwnerForSiteUsing(fn () => $this->owner);
    Monitoring::resolveRecipientsUsing(fn () => [$this->recipient]);
    Monitoring::resolveChannelsUsing(fn () => ['mail']);
});

/**
 * Every kind of news, with the push an ordinary subject of that kind produces.
 *
 * @return array<string, array{class-string<MonitoringNotification>, string, string, string, string}>
 */
function pushKinds(): array
{
    return [
        'uptime failure' => [UptimeCheckFailedNotification::class, 'uptime_failed', 'monitor', 'Uptime check failed', 'abigah.com failed its uptime check.'],
        'uptime recovery' => [UptimeCheckRecoveredNotification::class, 'uptime_recovered', 'monitor', 'Monitor recovered', 'abigah.com has recovered.'],
        'certificate invalid' => [CertificateCheckFailedNotification::class, 'certificate_failed', 'monitor', 'Certificate check failed', 'The SSL certificate for abigah.com is invalid.'],
        'certificate expiry' => [CertificateExpiresSoonNotification::class, 'certificate_expires_soon', 'monitor', 'Certificate expiring soon', 'The SSL certificate for abigah.com expires soon.'],
        'domain expiry' => [DomainExpiresSoonNotification::class, 'domain_expires_soon', 'monitor', 'Domain expiring soon', 'The domain registration for abigah.com expires soon.'],
        'heartbeat missed' => [HeartbeatMissedNotification::class, 'heartbeat_missing', 'heartbeat', 'Scheduled work missed', 'Nightly digest did not run as expected.'],
        'heartbeat recovery' => [HeartbeatRecoveredNotification::class, 'heartbeat_recovered', 'heartbeat', 'Scheduled work resumed', 'Nightly digest is running again.'],
        'site exception' => [SiteExceptionReportedNotification::class, 'exception_reported', 'exception', 'New server error', 'A kind of error not seen here before: RuntimeException.'],
    ];
}

dataset('push kinds', pushKinds());

/**
 * The event type the subscriber sends each notification for — the one list of
 * event types there is.
 *
 * @return array<class-string<MonitoringNotification>, string>
 */
function subscribedEventTypes(): array
{
    $eventMap = (new ReflectionClass(MonitorEventSubscriber::class))->getConstant('EVENT_MAP');

    // Read by name: a renamed or emptied map fails here, not as a puzzling
    // comparison further on.
    expect($eventMap)->toBeArray()->not->toBeEmpty();

    return collect($eventMap)
        ->mapWithKeys(fn (array $entry) => [$entry[1] => $entry[0]])
        ->all();
}

/**
 * The name a person knows a subject by: ordinary, or as long as it might
 * realistically get.
 */
function pushSubjectName(string $destination, bool $long): string
{
    return match ($destination) {
        'monitor' => $long
            ? implode('.', array_fill(0, 3, 'customer-portal-staging-environment-for-the-regional-office')).'.example-agency-hosting.co.uk'
            : 'abigah.com',
        'heartbeat' => $long
            ? 'Nightly reconciliation of every regional billing entity\'s customer invoices, refunds, chargebacks and ledger exports before the accounting close, including the quarterly tax remittance summaries for each province'
            : 'Nightly digest',
        'exception' => $long
            ? 'PaymentGatewayCommunicationTimedOutWhileCapturingTheCustomerInvoiceForTheRegionalBillingEntityDuringTheNightlyReconciliationRunAndTheQuarterlyTaxRemittanceSummaryExportForEachProvinceException'
            : 'RuntimeException',
    };
}

/**
 * A subject of the given kind carrying everything a push must never repeat —
 * failure output, request data, credentials, a token, a trace, an error
 * message — every one of them marked SECRET.
 *
 * @return array{Model, string} the subject, and the name a person knows it by
 */
function pushSubject(Model $site, string $destination, bool $long = false): array
{
    $name = pushSubjectName($destination, $long);

    if ($destination === 'monitor') {
        return [$site->monitors()->create([
            'url' => "https://deploy:SECRET-PASSWORD@{$name}/health?key=SECRET-QUERY#SECRET-FRAGMENT",
            'look_for_string' => 'SECRET-LOOK-FOR',
            'fail_for_string' => 'SECRET-FAIL-FOR',
            'clamped_reason' => 'SECRET-CLAMP',
            'certificate_issuer' => 'SECRET-ISSUER',
            'domain_expiry_check_failure_reason' => 'SECRET-WHOIS',
            'uptime_status' => UptimeStatus::DOWN->value,
            'uptime_check_failure_reason' => "500 Internal Server Error\nAuthorization: Bearer SECRET-BEARER\n#0 /var/www/app/Http/Kernel.php(12): handle()",
            'uptime_check_payload' => 'password=SECRET-PAYLOAD',
            'uptime_check_additional_headers' => ['Authorization' => 'Bearer SECRET-HEADER'],
            'certificate_status' => 'invalid',
            'certificate_check_failure_reason' => 'Handshake failed: SECRET-DIAGNOSTIC',
            'certificate_expiration_date' => now()->addDays(5),
            'domain_expiration_date' => now()->addDays(9),
            'domain_registrar' => 'SECRET-REGISTRAR',
        ]), $name];
    }

    if ($destination === 'heartbeat') {
        return [$site->heartbeats()->create([
            'name' => $name,
            'token' => 'SECRET-TOKEN-'.Str::random(51),
            'job_class' => 'App\\Jobs\\SECRET\\SendDigest',
            'interval_minutes' => 60,
            'status' => HeartbeatStatus::FAILED->value,
            'last_ping_at' => now()->subHours(3),
            'last_message' => "Authorization: Bearer SECRET-BEARER\n#0 /var/www/app/Console/Kernel.php(40)",
        ]), $name];
    }

    return [$site->siteExceptions()->create([
        'fingerprint' => 'SECRET-FINGERPRINT-'.Str::random(45),
        'exception_class' => 'App\\Domain\\Billing\\'.$name,
        'message' => 'Invalid API key sk_live_SECRET-MESSAGE for jane@example.com',
        'file' => '/var/www/app/Services/SECRET-PATH/Gateway.php',
        'line' => 42,
        'trace' => "#0 /var/www/app/Services/Gateway.php(42): charge()\n#1 {main} Authorization: Bearer SECRET-TRACE",
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]), $name];
}

/**
 * What changes about a subject between the event and a worker describing it.
 *
 * @return array<string, mixed>
 */
function livePushState(string $destination): array
{
    return match ($destination) {
        'monitor' => [
            'uptime_status' => UptimeStatus::UP->value,
            'uptime_check_failure_reason' => '',
            'certificate_status' => 'valid',
            'certificate_check_failure_reason' => '',
            'certificate_expiration_date' => now()->addYear(),
            'domain_expiration_date' => now()->addYear(),
            'domain_registrar' => 'Another registrar',
        ],
        'heartbeat' => [
            'status' => HeartbeatStatus::OK->value,
            'last_ping_at' => now(),
            'last_message' => null,
        ],
        'exception' => [
            'message' => 'A different message entirely',
            'occurrences' => 40,
            'last_seen_at' => now()->addHour(),
            'resolved_at' => now(),
        ],
    };
}

it('covers every kind of news the subscriber sends', function () {
    expect(collect(pushKinds())->mapWithKeys(fn (array $kind) => [$kind[0] => $kind[1]])->sortKeys()->all())
        ->toBe(collect(subscribedEventTypes())->sortKeys()->all());
});

it('describes each kind of news as its event, leading to its subject', function (string $class, string $eventType, string $destination) {
    [$subject] = pushSubject($this->site, $destination);

    $push = (new $class($subject, ['mail'], $this->owner->getKey()))->toPush($this->recipient)->toArray();

    expect($push['event_type'])->toBe($eventType)
        ->and($push['event_type'])->toBe(subscribedEventTypes()[$class])
        ->and($push['destination_type'])->toBe($destination)
        ->and($push['destination_id'])->toBe($subject->getKey())
        ->and($push['owner_id'])->toBe($this->owner->getKey());
})->with('push kinds');

it('says briefly what happened, and to what', function (string $class, string $eventType, string $destination, string $title, string $body) {
    [$subject, $name] = pushSubject($this->site, $destination);

    $push = (new $class($subject, ['mail'], $this->owner->getKey()))->toPush($this->recipient)->toArray();

    expect($push['title'])->toBe($title)
        ->and($push['body'])->toBe($body)
        ->and($push['body'])->toContain($name)
        ->and(mb_strlen($push['title']))->toBeLessThanOrEqual(PushMessage::TITLE_MAX_LENGTH)
        ->and(mb_strlen($push['body']))->toBeLessThanOrEqual(PushMessage::BODY_MAX_LENGTH);
})->with('push kinds');

/**
 * The name is the only part of a push that can be any length, so it is the part
 * that gives way: the sentence around it stays whole, and as much of the name as
 * fits is kept.
 */
it('shortens a name too long for a push and keeps the sentence around it', function (string $class, string $eventType, string $destination, string $title, string $body) {
    [$head, $tail] = explode(pushSubjectName($destination, long: false), $body, 2);

    [$subject, $name] = pushSubject($this->site, $destination, long: true);

    $push = (new $class($subject, ['mail'], $this->owner->getKey()))->toPush($this->recipient)->toArray();

    $kept = mb_substr($push['body'], mb_strlen($head), mb_strlen($push['body']) - mb_strlen($head) - mb_strlen('…'.$tail));

    expect(mb_strlen($name))->toBeGreaterThan(PushMessage::BODY_MAX_LENGTH)
        ->and($push['title'])->toBe($title)
        ->and(mb_strlen($push['body']))->toBeLessThanOrEqual(PushMessage::BODY_MAX_LENGTH)
        ->and(str_starts_with($push['body'], $head))->toBeTrue()
        ->and($push['body'])->toEndWith('…'.$tail)
        ->and($name)->toStartWith($kept)
        ->and(mb_strlen($kept))->toBeGreaterThan(100);
})->with('push kinds');

/**
 * The limits belong to the push contract, not to this package's notifications,
 * so a delivery channel can rely on them: whatever the subject and site are
 * called, no push this package describes is longer.
 */
it('never exceeds the limits the push contract publishes', function (string $class, string $eventType, string $destination) {
    $longSite = str_repeat('Regional Billing and Customer Accounts Portal ', 6);

    foreach ([false, true] as $long) {
        [$subject] = pushSubject($this->site, $destination, $long);

        foreach ([null, 'Acme Shop', $longSite] as $siteName) {
            $push = (new $class($subject, ['mail'], $this->owner->getKey(), siteName: $siteName))->toPush($this->recipient);

            expect(mb_strlen($push->title))->toBeLessThanOrEqual(PushMessage::TITLE_MAX_LENGTH)
                ->and(mb_strlen($push->body))->toBeLessThanOrEqual(PushMessage::BODY_MAX_LENGTH);
        }
    }
})->with('push kinds');

it('never repeats failure output, request data, a token, a trace or an error message', function (string $class, string $eventType, string $destination) {
    foreach ([false, true] as $long) {
        [$subject] = pushSubject($this->site, $destination, $long);

        $push = (new $class($subject, ['mail'], $this->owner->getKey(), 777, true))->toPush($this->recipient)->toArray();
        $said = collect($push)->map(fn (mixed $value) => is_string($value) ? $value : json_encode($value))->implode(' | ');

        $secrets = ['SECRET', 'Bearer', 'Authorization', 'password', 'key=', '#0', '/var/www', 'Internal Server Error',
            'sk_live', 'jane@example.com', 'Invalid API key', 'Gateway.php', 'App\\Domain', 'deploy:'];

        foreach ($secrets as $secret) {
            expect(Str::contains($said, $secret, ignoreCase: true))->toBeFalse("The {$eventType} push repeats \"{$secret}\": {$said}");
        }

        if ($destination === 'heartbeat') {
            expect($said)->not->toContain($subject->token);
        }
    }
})->with('push kinds');

it('carries the outage and urgency it was handed, without asking the database', function (string $class, string $eventType, string $destination, string $title, string $body, ?int $incidentId, bool $timeSensitive) {
    [$subject] = pushSubject($this->site, $destination);

    // No incident 777 exists: the id is carried, never looked up — and neither
    // is the site's name, which is handed in as it was when the event happened.
    $notification = new $class($subject, ['mail'], $this->owner->getKey(), $incidentId, $timeSensitive, null, 'Acme Shop');

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $push = $notification->toPush($this->recipient)->toArray();

    expect($queries)->toBe(0)
        ->and($push['incident_id'])->toBe($incidentId)
        ->and($push['time_sensitive'])->toBe($timeSensitive);

    if ($destination !== 'monitor') {
        expect($push['body'])->toContain('Acme Shop');
    }
})->with('push kinds')->with([
    'routine, with no outage' => [null, false],
    'urgent, during an outage' => [777, true],
    'routine, during an outage' => [777, false],
]);

it('reports when the event happened, not when a worker described it', function (string $class, string $eventType, string $destination) {
    $this->freezeTime();

    [$subject] = pushSubject($this->site, $destination);
    $happenedAt = now()->subMinutes(3)->toImmutable();

    $queued = serialize(new $class($subject, ['mail'], $this->owner->getKey(), null, false, $happenedAt));

    $this->travel(17)->minutes();

    expect(unserialize($queued)->toPush($this->recipient)->toArray()['occurred_at'])
        ->toBe($happenedAt->utc()->format('Y-m-d\TH:i:s\Z'));
})->with('push kinds');

/**
 * A worker reloads the subject before describing the push, and by then a job
 * that failed may be running again, or a certificate renewed. The push is about
 * the event, so it is written from who the subject is — its name — and never
 * from the state a worker happens to find it in.
 */
it('describes the subject it was about, whatever state a worker finds it in', function (string $class, string $eventType, string $destination) {
    [$subject] = pushSubject($this->site, $destination);

    $notification = new $class($subject, ['mail'], $this->owner->getKey(), null, false, null, $this->site->name);
    $told = Arr::only($notification->toPush($this->recipient)->toArray(), ['title', 'body']);
    $queued = serialize($notification);

    $subject->newQuery()->whereKey($subject->getKey())->update(livePushState($destination));
    $this->site->newQuery()->whereKey($this->site->getKey())->update(['name' => 'Renamed since']);

    $reloaded = unserialize($queued);

    expect($reloaded->subject->getAttributes())->not->toEqual($subject->getAttributes())
        ->and(Arr::only($reloaded->toPush($this->recipient)->toArray(), ['title', 'body']))->toBe($told);
})->with('push kinds');

/**
 * Urgency is decided once for the event by the subscriber, and the push says
 * exactly what was decided: urgent only when the site is down — a critical
 * monitor failing, or a missed job or new error while a critical monitor's
 * outage is open. Warnings and recoveries never are.
 */
it('marks a push urgent only when the site is down', function (bool $critical, bool $outage, string $news, string $class, bool $urgent) {
    $this->freezeTime();

    $monitor = $this->site->monitors()->create(['url' => 'https://abigah.com/', 'critical' => $critical]);
    [$heartbeat] = pushSubject($this->site, 'heartbeat');
    [$exception] = pushSubject($this->site, 'exception');

    if ($outage) {
        $monitor->recordUptimeResult(CheckResult::down('Down'));
    }

    $this->travel(5)->minutes();
    $eventAt = now()->toImmutable();

    Notification::fake();

    match ($news) {
        'uptime failure' => $monitor->recordUptimeResult(CheckResult::down('Down')),
        'uptime recovery' => $monitor->recordUptimeResult(CheckResult::up(100)),
        'certificate failure' => event(new CertificateCheckFailed($monitor, 'The certificate has expired')),
        'certificate expiry' => event(new CertificateExpiresSoon($monitor)),
        'domain expiry' => event(new DomainExpiresSoon($monitor)),
        'heartbeat missed' => event(new HeartbeatMissed($heartbeat, HeartbeatStatus::MISSING)),
        'heartbeat recovery' => event(new HeartbeatRecovered($heartbeat)),
        'site exception' => event(new SiteExceptionReported($exception)),
    };

    $sent = Notification::sent($this->recipient, $class)->sole();

    $this->travel(17)->minutes();

    $push = $sent->toPush($this->recipient)->toArray();

    expect($push['time_sensitive'])->toBe($urgent)
        ->and($push['time_sensitive'])->toBe($sent->timeSensitive)
        ->and($push['incident_id'])->toBe($sent->incidentId)
        ->and($push['occurred_at'])->toBe($eventAt->utc()->format('Y-m-d\TH:i:s\Z'));
})->with([
    'a critical monitor fails' => [true, false, 'uptime failure', UptimeCheckFailedNotification::class, true],
    'a non-critical monitor fails' => [false, false, 'uptime failure', UptimeCheckFailedNotification::class, false],
    'a critical monitor\'s certificate fails' => [true, false, 'certificate failure', CertificateCheckFailedNotification::class, true],
    'a non-critical monitor\'s certificate fails' => [false, false, 'certificate failure', CertificateCheckFailedNotification::class, false],
    'a certificate nears expiry during an outage' => [true, true, 'certificate expiry', CertificateExpiresSoonNotification::class, false],
    'a domain nears expiry during an outage' => [true, true, 'domain expiry', DomainExpiresSoonNotification::class, false],
    'a critical monitor recovers' => [true, true, 'uptime recovery', UptimeCheckRecoveredNotification::class, false],
    'a heartbeat recovers during an outage' => [true, true, 'heartbeat recovery', HeartbeatRecoveredNotification::class, false],
    'a heartbeat is missed while the site is up' => [true, false, 'heartbeat missed', HeartbeatMissedNotification::class, false],
    'a heartbeat is missed during a critical outage' => [true, true, 'heartbeat missed', HeartbeatMissedNotification::class, true],
    'a heartbeat is missed during a non-critical outage' => [false, true, 'heartbeat missed', HeartbeatMissedNotification::class, false],
    'a new error while the site is up' => [true, false, 'site exception', SiteExceptionReportedNotification::class, false],
    'a new error during a critical outage' => [true, true, 'site exception', SiteExceptionReportedNotification::class, true],
    'a new error during a non-critical outage' => [false, true, 'site exception', SiteExceptionReportedNotification::class, false],
]);

dataset('monitor push kinds', array_filter(pushKinds(), fn (array $kind) => $kind[2] === 'monitor'));

/**
 * A monitor is named by its host and by nothing else in its URL. A URL can be
 * saved that PHP's own parser gives up on — an impossible port will do it — and
 * a legacy import checks nothing, so the name never falls back to the URL
 * itself: credentials, paths and query strings are exactly where secrets sit.
 * With no host to be found, the monitor's own name stands in, and failing that
 * its number.
 *
 * A host is recognised by what it may contain, not by what it may not: letters,
 * digits, marks, dots and hyphens, not starting with a mark, dot or hyphen, or a
 * bracketed IPv6 address. Anything else — an escaped delimiter, a query typed
 * without its question mark, a NUL, a fullwidth slash, a variation selector or
 * filler that shows nothing — means the URL is not naming a host, and nothing of
 * it is said.
 *
 * A name with nothing left to read once it gives way — one character built from
 * hundreds of marks — is replaced by the monitor's number too.
 */
it('names a monitor by its host alone, however malformed its URL', function (string $class, string $eventType, string $destination, string $title, string $body, string $url, ?string $name, ?string $label) {
    $monitor = $this->site->monitors()->create(['url' => $url, 'name' => $name]);
    $label ??= "Monitor #{$monitor->getKey()}";

    $push = (new $class($monitor, ['mail'], $this->owner->getKey()))->toPush($this->recipient)->toArray();
    $said = str_replace($label, '', $push['title'].' '.$push['body']);

    expect($push['title'])->toBe($title)
        ->and($push['body'])->toBe(str_replace('abigah.com', $label, $body))
        ->and(Str::contains($said, 'SECRET', ignoreCase: true))->toBeFalse("The {$eventType} push repeats its URL: {$push['body']}");

    foreach (['/', '?', '@', '#', ':', '%', '&', ';', '=', ','] as $part) {
        expect($said)->not->toContain($part);
    }
})->with('monitor push kinds')->with([
    'an ordinary URL' => ['https://abigah.com/health?key=SECRET', null, 'abigah.com'],
    'an internationalised host' => ['https://bücher.example/health?key=SECRET', null, 'bücher.example'],
    'a punycode host' => ['https://xn--bcher-kva.example/health?key=SECRET', null, 'xn--bcher-kva.example'],
    'an IPv6 address and a port' => ['https://[::1]:8443/health?key=SECRET', null, '[::1]'],
    'a fully qualified host with its trailing dot' => ['https://abigah.com./health?key=SECRET', null, 'abigah.com.'],
    'a decomposed internationalised host' => ["https://bu\u{0308}cher.example/health?key=SECRET", null, "bu\u{0308}cher.example"],
    'a Devanagari host' => ['https://क्षि.example/health?key=SECRET', null, 'क्षि.example'],
    'an IPv4-mapped IPv6 address' => ['https://[::ffff:1.2.3.4]/health?key=SECRET', null, '[::ffff:1.2.3.4]'],
    'an IPv6 address with a zone' => ['https://[fe80::1%25en0]/health?key=SECRET', null, null],
    'credentials and an impossible port' => ['https://deploy:SECRET@abigah.com:99999/health?key=SECRET', null, 'abigah.com'],
    'a port past the last, a path, a query and a fragment' => ['https://abigah.com:65536/SECRETPATH?token=SECRETQ#frag', null, 'abigah.com'],
    'credentials and no host' => ['https://SECRET:SECRET@:99999/health?key=SECRET', null, null],
    'a password where the host should be' => ['https://SECRET@/health?key=SECRET', null, null],
    'a space where the host ends' => ['https://abigah.com SECRET/health?key=SECRET', null, null],
    'not a URL at all, on a named monitor' => ['not a url SECRET/path?key=SECRET#SECRET@x', 'Status page', 'Status page'],
    'not a URL at all, on an unnamed monitor' => ['not a url SECRET/path?key=SECRET#SECRET@x', null, null],
    'escaped credentials before the host' => ['https://deploy%3ASECRET%40abigah.com/', null, null],
    'an escaped path and query' => ['https://abigah.com%2Fhealth%3Ftoken%3DSECRET', null, null],
    'an escaped path and query, and an impossible port' => ['https://abigah.com%2Fhealth%3Ftoken%3DSECRET:99999/', null, null],
    'an escaped path' => ['https://abigah.com%2FSECRET', null, null],
    'an escaped NUL' => ['https://abigah.com%00SECRET/', null, null],
    'a NUL' => ["https://abigah.com\0SECRET/", null, null],
    'a query with no question mark' => ['https://abigah.com&token=SECRET', null, null],
    'parameters after a semicolon' => ['https://abigah.com;token=SECRET', null, null],
    'an equals sign' => ['https://abigah.com=SECRET', null, null],
    'a comma' => ['https://abigah.com,SECRET', null, null],
    'a fullwidth path and query' => ['https://abigah.com／health？token=SECRET', null, null],
    'a variation selector hiding what follows the host' => ["https://abigah.com\u{FE0F}SECRET/health?key=SECRET", null, null],
    'a combining grapheme joiner' => ["https://abigah\u{034F}SECRET.com/health?key=SECRET", null, null],
    'a Hangul filler' => ["https://abigah.com\u{3164}SECRET/health?key=SECRET", null, null],
    'combining marks alone' => ["https://\u{0301}\u{0301}/health?key=SECRET", null, null],
    'a dot alone' => ['https://./health?key=SECRET', null, null],
    'a hyphen alone' => ['https://-/health?key=SECRET', null, null],
    'dots alone' => ['https://.../health?key=SECRET', null, null],
    'a leading hyphen' => ['https://-abigah.com/health?key=SECRET', null, null],
    'a host that is one character too long to fit' => ['https://a'.str_repeat("\u{0301}", 300).'.example/health?key=SECRET', null, null],
    'no host, and a name that is one character too long to fit' => ['not a url SECRET/path?key=SECRET', 'S'.str_repeat("\u{0301}", 300), null],
]);

/**
 * The news that is about a site's work rather than a URL: a job's name or an
 * exception's class does not say which site it happened on, the way a
 * monitor's host does.
 *
 * @return array<string, array{class-string<MonitoringNotification>, string, string, string}>
 */
function siteNamedPushKinds(): array
{
    return [
        'heartbeat missed' => [HeartbeatMissedNotification::class, 'heartbeat', ':subject on :site did not run as expected.', 'Nightly digest on Acme Shop did not run as expected.'],
        'heartbeat recovery' => [HeartbeatRecoveredNotification::class, 'heartbeat', ':subject on :site is running again.', 'Nightly digest on Acme Shop is running again.'],
        'site exception' => [SiteExceptionReportedNotification::class, 'exception', 'A kind of error not seen on :site before: :subject.', 'A kind of error not seen on Acme Shop before: RuntimeException.'],
    ];
}

dataset('site-named push kinds', siteNamedPushKinds());

/**
 * The event the subscriber sends each site-named notification for.
 *
 * @param  class-string<MonitoringNotification>  $class
 */
function pushSiteNews(string $class, Model $subject): object
{
    return match ($class) {
        HeartbeatMissedNotification::class => new HeartbeatMissed($subject, HeartbeatStatus::MISSING),
        HeartbeatRecoveredNotification::class => new HeartbeatRecovered($subject),
        SiteExceptionReportedNotification::class => new SiteExceptionReported($subject),
    };
}

it('names the site a missed job or a new error happened on', function (string $class, string $destination, string $sentence, string $example) {
    $this->site->update(['name' => 'Acme Shop']);
    [$subject, $name] = pushSubject($this->site, $destination);

    Notification::fake();
    event(pushSiteNews($class, $subject));

    $push = Notification::sent($this->recipient, $class)->sole()->toPush($this->recipient)->toArray();

    expect($push['body'])->toBe($example)
        ->and($push['body'])->toBe(strtr($sentence, [':subject' => $name, ':site' => 'Acme Shop']));
})->with('site-named push kinds');

/**
 * Both names can be any length, so both give way — each keeps at least half the
 * room the sentence leaves, and a short one gives what it does not need to the
 * other. The sentence around them stays whole.
 */
it('shares the room between a long name and a long site name, and keeps the sentence whole', function (string $class, string $destination, string $sentence) {
    $longSite = 'Northern Territories Regional Billing and Customer Accounts Portal, the Staging Environment Replica Hosted for the Quarterly Audit Team and the Provincial Tax Remittance Office Mirror';
    [$head, $middle, $tail] = preg_split('/:subject|:site/', $sentence);

    expect(mb_strlen($longSite))->toBeGreaterThan(PushMessage::BODY_MAX_LENGTH);

    foreach ([[true, true], [false, true], [true, false]] as [$longSubject, $siteIsLong]) {
        [$subject, $name] = pushSubject($this->site, $destination, $longSubject);
        $site = $siteIsLong ? $longSite : 'Acme Shop';

        $body = (new $class($subject, ['mail'], $this->owner->getKey(), null, false, null, $site))->toPush($this->recipient)->toArray()['body'];

        expect(mb_strlen($body))->toBeLessThanOrEqual(PushMessage::BODY_MAX_LENGTH)
            ->and(str_starts_with($body, $head))->toBeTrue()
            ->and($body)->toContain($middle)
            ->and($body)->toEndWith($tail);

        match (true) {
            $longSubject && $siteIsLong => expect(substr_count($body, '…'))->toBe(2)
                ->and($body)->toContain(mb_substr($name, 0, 60))
                ->and($body)->toContain(mb_substr($site, 0, 60)),
            $siteIsLong => expect(substr_count($body, '…'))->toBe(1)
                ->and($body)->toContain($name)
                ->and($body)->toContain(mb_substr($site, 0, 100)),
            default => expect(substr_count($body, '…'))->toBe(1)
                ->and($body)->toContain(mb_substr($name, 0, 100))
                ->and($body)->toContain($site),
        };
    }
})->with('site-named push kinds');

/**
 * A host's site model may have no name, or a blank one, and a notification
 * built directly is handed none: the push still says what happened, in the
 * words it would use with no site at all.
 */
it('says what happened without a site when there is no site name to give', function (string $class, string $destination, string $sentence, string $example, string $case) {
    $withoutSite = collect(pushKinds())->firstWhere(0, $class)[4];

    if ($case === 'built directly') {
        [$subject] = pushSubject($this->site, $destination);

        expect((new $class($subject, ['mail'], $this->owner->getKey()))->toPush($this->recipient)->toArray()['body'])->toBe($withoutSite);

        return;
    }

    $site = $this->site;

    if ($case === 'a blank site name') {
        $site->update(['name' => " \u{200B}\t "]);
    }

    if ($case === 'a host site model with no name') {
        Schema::dropIfExists('nameless_host_sites');
        Schema::create('nameless_host_sites', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->timestamps();
        });

        $model = new class extends Model
        {
            use IsMonitoredSite;

            protected $table = 'nameless_host_sites';

            protected $guarded = [];
        };

        config()->set('monitoring.site_model', $model::class);
        $site = $model::create(['owner_id' => $this->owner->getKey()]);
    }

    [$subject] = pushSubject($site, $destination);

    Notification::fake();

    // A strict host would hear about a missing attribute being read.
    Model::preventAccessingMissingAttributes();

    try {
        event(pushSiteNews($class, $subject));
    } finally {
        Model::preventAccessingMissingAttributes(false);
    }

    expect(Notification::sent($this->recipient, $class)->sole()->toPush($this->recipient)->toArray()['body'])->toBe($withoutSite);
})->with('site-named push kinds')->with(['built directly', 'a blank site name', 'a host site model with no name']);

/**
 * The site is read once for the event, to find whose news it is, and that same
 * read gives its name and — for a missed job or a new error — the site whose
 * open outage the news belongs to.
 */
it('reads the site once for whose news it is, what it is called and its outage', function (string $class, string $destination) {
    $this->site->update(['name' => 'Acme Shop']);
    [$subject] = pushSubject($this->site, $destination);

    Notification::fake();

    $siteReads = 0;
    DB::listen(function (QueryExecuted $query) use (&$siteReads): void {
        if (str_contains($query->sql, 'monitor_sites')) {
            $siteReads++;
        }
    });

    event(pushSiteNews($class, $subject));

    expect(Notification::sent($this->recipient, $class)->sole()->siteName)->toBe('Acme Shop')
        ->and($siteReads)->toBe(1);
})->with('site-named push kinds');

/**
 * Bidirectional overrides, zero-width characters, variation selectors and
 * fillers can make a name read as something it is not, or hide what follows it,
 * and a lock screen shows them exactly as given.
 */
it('leaves out invisible formatting characters that could disguise a name', function () {
    [$heartbeat] = pushSubject($this->site, 'heartbeat');
    $heartbeat->update(['name' => "Nightly\u{200D}\u{034F} \u{202E}\u{115F}digest\u{2066}\u{FE0F}\u{E0100}\u{17B4}"]);

    $push = (new HeartbeatMissedNotification($heartbeat, ['mail'], $this->owner->getKey(), null, false, null, "Acme\u{200B}\u{180B} \u{3164}Shop\u{202C}\u{FFA0}\u{1160}\u{17B5}\u{FE00}"))
        ->toPush($this->recipient)
        ->toArray();

    expect($push['body'])->toBe('Nightly digest on Acme Shop did not run as expected.')
        ->and(preg_match('/[\p{Cf}\x{034F}\x{115F}\x{1160}\x{17B4}\x{17B5}\x{180B}-\x{180F}\x{3164}\x{FE00}-\x{FE0F}\x{FFA0}\x{E0100}-\x{E01EF}]/u', $push['title'].$push['body']))->toBe(0);
});

/**
 * PHP names an anonymous class after what it extends, followed by the file and
 * line it was declared on. The push names the class it extends.
 */
it('names an anonymous exception class by the class it extends', function (string $exceptionClass, string $named) {
    [$exception] = pushSubject($this->site, 'exception');
    $exception->exception_class = $exceptionClass;

    $push = (new SiteExceptionReportedNotification($exception, ['mail'], $this->owner->getKey()))->toPush($this->recipient)->toArray();

    expect($push['body'])->toBe("A kind of error not seen here before: {$named}.");
})->with([
    'extending a global class' => ['RuntimeException@anonymous'."\0".'/var/www/app/Services/Gateway.php:42$0', 'RuntimeException'],
    'extending a namespaced class' => ['App\\Exceptions\\GatewayException@anonymous'."\0".'/var/www/app/Services/Gateway.php:42$1', 'GatewayException'],
]);

/**
 * An error with no class a person could read — an anonymous class extending
 * nothing, which PHP names only "class", or a name that is nothing but
 * invisible characters — is still news, and the push says it plainly rather
 * than leaving a gap in the sentence.
 */
it('says a new error is unnamed when its class gives nothing to read', function (string $exceptionClass) {
    [$exception] = pushSubject($this->site, 'exception');
    $exception->exception_class = $exceptionClass;

    $body = fn (?string $siteName) => (new SiteExceptionReportedNotification($exception, ['mail'], $this->owner->getKey(), siteName: $siteName))
        ->toPush($this->recipient)
        ->toArray()['body'];

    expect($body(null))->toBe('An unnamed kind of error not seen here before.')
        ->and($body('Acme Shop'))->toBe('An unnamed kind of error not seen on Acme Shop before.');
})->with([
    'an anonymous class extending nothing' => ['class@anonymous'."\0".'/var/www/app/Services/Gateway.php:42$0'],
    'zero-width spaces' => ["\u{200B}\u{200B}"],
    'a namespace and an invisible class' => ["App\\Exceptions\\\u{200B}\u{202E}"],
    'nothing at all' => [''],
    'combining marks alone' => ["App\\Exceptions\\\u{0301}\u{0301}"],
    'one character too long to fit' => ['App\\Exceptions\\E'.str_repeat("\u{0301}", 300)],
]);

/**
 * A job whose name is nothing a person could read is called by its number, the
 * way a monitor with no host and no name is — and so is one whose name leaves
 * nothing to read once it gives way, like one character built from hundreds of
 * marks.
 */
it('numbers a scheduled job whose name gives nothing to read', function (string $class, string $sentence, string $siteSentence, string $blankName) {
    [$heartbeat] = pushSubject($this->site, 'heartbeat');
    $heartbeat->update(['name' => $blankName]);
    $label = "Heartbeat #{$heartbeat->getKey()}";

    $body = fn (?string $siteName) => (new $class($heartbeat, ['mail'], $this->owner->getKey(), siteName: $siteName))
        ->toPush($this->recipient)
        ->toArray()['body'];

    expect($body(null))->toBe(strtr($sentence, [':subject' => $label]))
        ->and($body('Acme Shop'))->toBe(strtr($siteSentence, [':subject' => $label, ':site' => 'Acme Shop']));
})->with([
    'heartbeat missed' => [HeartbeatMissedNotification::class, ':subject did not run as expected.', ':subject on :site did not run as expected.'],
    'heartbeat recovery' => [HeartbeatRecoveredNotification::class, ':subject is running again.', ':subject on :site is running again.'],
])->with([
    'zero-width spaces' => ["\u{200B}\u{200B}"],
    'whitespace and formatting characters' => [" \u{200B}\t\u{202E} "],
    'nothing at all' => [''],
    'combining marks alone' => ["\u{0301}\u{0301}"],
    'invisible marks and fillers' => ["\u{FE0F}\u{034F}\u{3164}\u{E0100}"],
    'one character too long to fit' => ['e'.str_repeat("\u{0301}", 300)],
]);

/**
 * Shortening text for a push, as the notifications do it.
 */
function fittedPushText(string $text, int $max): string
{
    return (new ReflectionMethod(MonitoringNotification::class, 'fitPushText'))->invoke(null, $text, $max);
}

it('fits nothing into no room', function (int $max) {
    expect(fittedPushText('abigah.com', $max))->toBe('')
        ->and(fittedPushText('', $max))->toBe('');
})->with([0, -1, -40]);

/**
 * A flag is two code points and an accent can be a letter followed by a
 * combining mark. Cutting between them leaves a stray letter or a broken
 * symbol on a lock screen, so text is shortened by whole characters as a person
 * sees them — and still never past the limit, counted in code points.
 */
it('shortens by whole characters as a person sees them', function (string $text, int $max, string $fitted) {
    expect(fittedPushText($text, $max))->toBe($fitted)
        ->and(mb_strlen(fittedPushText($text, $max)))->toBeLessThanOrEqual($max);
})->with([
    'flags, cut mid-flag' => [str_repeat('🇨🇦', 10), 6, '🇨🇦🇨🇦…'],
    'flags, cut between flags' => [str_repeat('🇨🇦', 10), 7, '🇨🇦🇨🇦🇨🇦…'],
    'decomposed accents, cut mid-letter' => [str_repeat("e\u{0301}", 10), 4, "e\u{0301}…"],
    'decomposed accents, cut between letters' => [str_repeat("e\u{0301}", 10), 5, "e\u{0301}e\u{0301}…"],
    'room for the ellipsis alone' => [str_repeat('🇨🇦', 10), 1, '…'],
]);

it('never splits a flag or an accent when a name gives way in a push', function (string $unit) {
    [$heartbeat] = pushSubject($this->site, 'heartbeat');

    // Both parities, with a site name and without, so some cut falls inside a
    // character wherever the room happens to end.
    foreach (['', 'x'] as $lead) {
        foreach ([null, 'Acme Shop'] as $siteName) {
            $name = $lead.str_repeat($unit, 100);
            $heartbeat->update(['name' => $name]);

            $body = (new HeartbeatMissedNotification($heartbeat, ['mail'], $this->owner->getKey(), siteName: $siteName))
                ->toPush($this->recipient)
                ->toArray()['body'];

            preg_match_all('/\X/u', Str::before($body, '…'), $kept);
            preg_match_all('/\X/u', $name, $whole);

            expect(mb_strlen($body))->toBeLessThanOrEqual(PushMessage::BODY_MAX_LENGTH)
                ->and($body)->toContain('…')
                ->and(count($kept[0]))->toBeGreaterThan(40)
                ->and($kept[0])->toBe(array_slice($whole[0], 0, count($kept[0])));
        }
    }
})->with(['flags' => '🇨🇦', 'decomposed accents' => "e\u{0301}"]);

/**
 * A name can be as long as the database holds. Splitting all of it into
 * characters as a person sees them takes seconds for a long run of flags, so
 * shortening looks no further into the text than the room there is.
 */
it('shortens an enormous name without reading all of it', function (string $text, string $fitted) {
    $started = hrtime(true);
    $result = fittedPushText($text, PushMessage::BODY_MAX_LENGTH);
    $milliseconds = (hrtime(true) - $started) / 1e6;

    expect(mb_strlen($text))->toBe(100_000)
        ->and($result)->toBe($fitted)
        ->and($milliseconds)->toBeLessThan(500.0);
})->with([
    'a hundred thousand code points of flags' => [str_repeat('🇨🇦', 50_000), str_repeat('🇨🇦', intdiv(PushMessage::BODY_MAX_LENGTH - 1, 2)).'…'],
    'a hundred thousand letters' => [str_repeat('a', 100_000), str_repeat('a', PushMessage::BODY_MAX_LENGTH - 1).'…'],
]);
