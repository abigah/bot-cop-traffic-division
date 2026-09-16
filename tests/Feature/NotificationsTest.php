<?php

use Abigah\BotCopTrafficDivision\Contracts\ProvidesPushMessage;
use Abigah\BotCopTrafficDivision\Enums\HeartbeatStatus;
use Abigah\BotCopTrafficDivision\Enums\UptimeStatus;
use Abigah\BotCopTrafficDivision\Events\CertificateCheckFailed;
use Abigah\BotCopTrafficDivision\Events\CertificateExpiresSoon;
use Abigah\BotCopTrafficDivision\Events\DomainExpiresSoon;
use Abigah\BotCopTrafficDivision\Events\HeartbeatMissed;
use Abigah\BotCopTrafficDivision\Events\HeartbeatRecovered;
use Abigah\BotCopTrafficDivision\Events\SiteExceptionReported;
use Abigah\BotCopTrafficDivision\Facades\Monitoring;
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
use Abigah\BotCopTrafficDivision\Tests\Fixtures\PushingNotification;
use Abigah\BotCopTrafficDivision\Tests\Fixtures\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

beforeEach(function () {
    config()->set('monitoring.uptime.fire_failed_event_after_consecutive_failures', 1);

    $this->owner = User::create(['name' => 'Owner', 'email' => 'owner@test.dev']);
    $this->recipient = User::create(['name' => 'On call', 'email' => 'oncall@test.dev', 'phone' => '+15550000']);
    $this->other = User::create(['name' => 'Also on call', 'email' => 'also@test.dev']);

    $this->site = MonitoredSite::create(['name' => 'Site', 'owner_id' => $this->owner->id]);
    $this->monitor = $this->site->monitors()->create(['url' => 'https://site.test/']);

    Monitoring::resolveOwnerForSiteUsing(fn () => $this->owner);
    Monitoring::resolveRecipientsUsing(fn () => [$this->recipient, $this->other]);
    Monitoring::resolveChannelsUsing(fn () => ['mail', 'database']);
});

it('notifies every recipient when a monitor fails', function () {
    Notification::fake();

    $this->monitor->recordUptimeResult(CheckResult::down('Connection refused'));

    Notification::assertSentTo([$this->recipient, $this->other], UptimeCheckFailedNotification::class);
});

it('sends on exactly the channels the recipient asked for', function () {
    Notification::fake();

    Monitoring::resolveChannelsUsing(fn ($recipient) => $recipient->is($this->recipient)
        ? ['mail', 'vonage']
        : ['database']);

    $this->monitor->recordUptimeResult(CheckResult::down('Down'));

    Notification::assertSentTo(
        $this->recipient,
        UptimeCheckFailedNotification::class,
        fn ($notification) => $notification->channels === ['mail', 'vonage'],
    );

    Notification::assertSentTo(
        $this->other,
        UptimeCheckFailedNotification::class,
        fn ($notification) => $notification->channels === ['database'],
    );
});

it('says nothing to a recipient who wants nothing', function () {
    Notification::fake();

    Monitoring::resolveChannelsUsing(fn ($recipient) => $recipient->is($this->recipient) ? ['mail'] : []);

    $this->monitor->recordUptimeResult(CheckResult::down('Down'));

    Notification::assertSentTo($this->recipient, UptimeCheckFailedNotification::class);
    Notification::assertNotSentTo($this->other, UptimeCheckFailedNotification::class);
});

/**
 * The reference package muted email and left SMS running, which is not what
 * anybody means by muting an outage.
 */
it('mutes every channel for the recipient who asked, and only them', function () {
    $this->monitor->recordUptimeResult(CheckResult::down('Down'));

    $incident = $this->monitor->incidents()->ongoing()->first();
    $incident->mutedBy()->attach($this->recipient->getKey());

    Notification::fake();

    $this->monitor->recordUptimeResult(CheckResult::down('Still down', checkedAt: now()->addHours(2)));

    Notification::assertNotSentTo($this->recipient, UptimeCheckFailedNotification::class);
    Notification::assertSentTo($this->other, UptimeCheckFailedNotification::class);
});

/**
 * A mute says "I know, stop telling me about this outage" — it covers the bad
 * news and never the good. Silencing a recovery would leave someone believing a
 * site is still down long after it came back.
 */
it('still says when a muted outage recovers', function () {
    $this->monitor->recordUptimeResult(CheckResult::down('Down'));
    $this->monitor->incidents()->ongoing()->first()->mutedBy()->attach($this->recipient->getKey());

    Notification::fake();

    $this->monitor->recordUptimeResult(CheckResult::up(100));

    Notification::assertSentTo($this->recipient, UptimeCheckRecoveredNotification::class);
});

/**
 * A timed mute is quiet while it lasts and over when it expires, whether or not
 * the expired row has been cleaned up — correctness cannot wait on a sweep.
 */
it('holds back failure news during a timed mute and resumes once it expires', function () {
    $this->monitor->recordUptimeResult(CheckResult::down('Down'));

    $incident = $this->monitor->incidents()->ongoing()->first();
    $incident->muteFor($this->recipient, now()->addMinutes(90));

    Notification::fake();

    // Past the hourly resend, still inside the mute.
    $this->travel(61)->minutes();
    $this->monitor->recordUptimeResult(CheckResult::down('Still down'));

    Notification::assertNotSentTo($this->recipient, UptimeCheckFailedNotification::class);
    Notification::assertSentTo($this->other, UptimeCheckFailedNotification::class);

    Notification::fake();

    // Past the next resend, and the mute ran out half an hour ago.
    $this->travel(61)->minutes();
    $this->monitor->recordUptimeResult(CheckResult::down('Still down'));

    expect($incident->mutedBy()->count())->toBe(1);

    Notification::assertSentTo($this->recipient, UptimeCheckFailedNotification::class);
    Notification::assertSentTo($this->other, UptimeCheckFailedNotification::class);
});

/**
 * A mute belongs to one outage. A live timed mute somewhere else must not leak
 * into this one — the "live" test and the "this incident" test only mean
 * anything together.
 */
it('does not let a timed mute on another outage silence this one', function () {
    $elsewhere = $this->site->monitors()->create(['url' => 'https://elsewhere.test/']);
    $elsewhere->recordUptimeResult(CheckResult::down('Down'));

    $elsewhereIncident = $elsewhere->incidents()->ongoing()->first();
    $elsewhereIncident->muteFor($this->other, now()->addHours(3));

    // A mute is set on an outage that is already open, so what it can silence
    // is a resend.
    $this->monitor->recordUptimeResult(CheckResult::down('Down'));
    $incident = $this->monitor->incidents()->ongoing()->first();

    Notification::fake();

    // Past the hourly resend, well inside the other outage's mute.
    $this->travel(61)->minutes();

    expect($incident)->not->toBeNull()
        ->and($incident->is($elsewhereIncident))->toBeFalse()
        ->and($incident->mutedBy()->count())->toBe(0)
        ->and($elsewhereIncident->isMutedBy($this->other))->toBeTrue();

    $this->monitor->recordUptimeResult(CheckResult::down('Still down'));

    Notification::assertSentTo($this->recipient, UptimeCheckFailedNotification::class);
    Notification::assertSentTo($this->other, UptimeCheckFailedNotification::class);
});

it('still says when an outage recovers, through a timed or an until-recovery mute', function () {
    $this->monitor->recordUptimeResult(CheckResult::down('Down'));

    $incident = $this->monitor->incidents()->ongoing()->first();
    $incident->muteFor($this->recipient, now()->addHour());
    $incident->muteFor($this->other, null);

    $heartbeat = $this->site->heartbeats()->create([
        'name' => 'Nightly digest',
        'token' => Str::random(48),
        'interval_minutes' => 60,
        'status' => HeartbeatStatus::MISSING->value,
    ]);

    Notification::fake();

    $this->travel(5)->minutes();

    // Both mutes are live, so anything delivered below got past them.
    expect($incident->isMutedBy($this->recipient))->toBeTrue()
        ->and($incident->isMutedBy($this->other))->toBeTrue();

    event(new HeartbeatRecovered($heartbeat));
    $this->monitor->recordUptimeResult(CheckResult::up(100));

    Notification::assertSentTo([$this->recipient, $this->other], HeartbeatRecoveredNotification::class);
    Notification::assertSentTo([$this->recipient, $this->other], UptimeCheckRecoveredNotification::class);
});

it('notifies about a missed heartbeat through the same path', function () {
    Notification::fake();

    $heartbeat = $this->site->heartbeats()->create([
        'name' => 'Nightly digest',
        'token' => Str::random(48),
        'interval_minutes' => 60,
    ]);

    event(new HeartbeatMissed($heartbeat, HeartbeatStatus::MISSING));

    Notification::assertSentTo([$this->recipient, $this->other], HeartbeatMissedNotification::class);
});

/**
 * A job that stopped during an outage and the outage itself are the same news.
 * Muting one has to mute the other, or the mute buys nothing.
 */
it('lets an outage mute cover the heartbeats that outage swallowed', function () {
    $this->monitor->recordUptimeResult(CheckResult::down('Down'));
    $this->monitor->incidents()->ongoing()->first()->mutedBy()->attach($this->recipient->getKey());

    $heartbeat = $this->site->heartbeats()->create([
        'name' => 'Nightly digest',
        'token' => Str::random(48),
        'interval_minutes' => 60,
    ]);

    Notification::fake();

    event(new HeartbeatMissed($heartbeat, HeartbeatStatus::MISSING));

    Notification::assertNotSentTo($this->recipient, HeartbeatMissedNotification::class);
    Notification::assertSentTo($this->other, HeartbeatMissedNotification::class);
});

it('notifies about a new server error through the same path', function () {
    Notification::fake();

    $exception = $this->site->siteExceptions()->create([
        'fingerprint' => str_repeat('a', 64),
        'exception_class' => 'RuntimeException',
        'message' => 'Payment gateway returned an unexpected response',
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);

    event(new SiteExceptionReported($exception));

    Notification::assertSentTo([$this->recipient, $this->other], SiteExceptionReportedNotification::class);
});

it('says nothing when the site has no owner to tell', function () {
    Notification::fake();

    Monitoring::resolveOwnerForSiteUsing(fn () => null);

    $orphan = MonitoredSite::create(['name' => 'Orphan', 'owner_id' => null]);
    $monitor = $orphan->monitors()->create(['url' => 'https://orphan.test/']);

    $monitor->recordUptimeResult(CheckResult::down('Down'));

    Notification::assertNothingSent();
});

/**
 * With no resolver wired, a notification falls back to this package's own
 * screens when they are registered — and to no link at all when they are not,
 * rather than failing to render while sending mail at 3am.
 */
it('links a heartbeat recovery at the site it belongs to', function () {
    $heartbeat = $this->site->heartbeats()->create([
        'name' => 'Nightly digest',
        'token' => Str::random(48),
        'interval_minutes' => 60,
        'status' => HeartbeatStatus::MISSING->value,
    ]);

    Notification::fake();

    event(new HeartbeatRecovered($heartbeat));

    Notification::assertSentTo($this->recipient, HeartbeatRecoveredNotification::class,
        function ($notification) {
            $mail = $notification->toMail($this->recipient);

            return str_contains((string) $mail->actionUrl, '/monitoring/sites/'.$this->site->id)
                && str_contains($mail->subject, 'running again');
        });
});

it('sends a usable email with no link when this application has no screens', function () {
    $heartbeat = $this->site->heartbeats()->create([
        'name' => 'Nightly digest',
        'token' => Str::random(48),
        'interval_minutes' => 60,
        'status' => HeartbeatStatus::MISSING->value,
    ]);

    // What an install that shows monitoring somewhere of its own, or not at
    // all, resolves to. The email still renders; it just has no button.
    Monitoring::resolveUrlUsing(fn () => null);

    $mail = (new HeartbeatRecoveredNotification($heartbeat, ['mail']))->toMail($this->recipient);

    expect($mail->actionUrl)->toBeNull()
        ->and($mail->subject)->toContain('running again');
});

/*
 | Push metadata.
 |
 | Whose news it is, which outage it belongs to and whether it is urgent are
 | worked out once for the event and handed to every recipient's notification,
 | so describing a push never goes back to the database once per person.
 */

/**
 * Asserts what every notification of one kind, sent to each of the given
 * recipients, carried for push delivery.
 *
 * @param  array<int, object>  $notifiables
 */
function expectPushSentTo(array $notifiables, string $notification, mixed $ownerId, ?int $incidentId, bool $timeSensitive): void
{
    foreach ($notifiables as $notifiable) {
        $sent = Notification::sent($notifiable, $notification)
            ->map(fn ($sent) => [
                'ownerId' => $sent->ownerId,
                'incidentId' => $sent->incidentId,
                'timeSensitive' => $sent->timeSensitive,
            ])
            ->values()
            ->all();

        expect($sent)->toBe([[
            'ownerId' => $ownerId,
            'incidentId' => $incidentId,
            'timeSensitive' => $timeSensitive,
        ]]);
    }
}

/**
 * A missed heartbeat or a new server error on the site — the news that belongs
 * to the site's outage rather than to a monitor of its own.
 *
 * @return array{object, class-string<MonitoringNotification>}
 */
function siteNews(MonitoredSite $site, string $kind): array
{
    return match ($kind) {
        'heartbeat' => [
            new HeartbeatMissed($site->heartbeats()->create([
                'name' => 'Nightly digest '.Str::random(8),
                'token' => Str::random(48),
                'interval_minutes' => 60,
            ]), HeartbeatStatus::MISSING),
            HeartbeatMissedNotification::class,
        ],
        'exception' => [
            new SiteExceptionReported($site->siteExceptions()->create([
                'fingerprint' => Str::random(64),
                'exception_class' => 'RuntimeException',
                'message' => 'Payment gateway returned an unexpected response',
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ])),
            SiteExceptionReportedNotification::class,
        ],
    };
}

/**
 * Uses the base helper exactly the way a notification's own toPush() will.
 */
function pushingNotification(Model $subject, array $channels, int|string|null $ownerId = null, ?int $incidentId = null, bool $timeSensitive = false): MonitoringNotification
{
    return new PushingNotification($subject, $channels, $ownerId, $incidentId, $timeSensitive);
}

it('hands a critical monitor failure its owner, its outage and its urgency', function () {
    Notification::fake();

    // The failure is told once its outage is on record (see
    // Monitor::recordUptimeResult()), so even the first page names it.
    $this->monitor->recordUptimeResult(CheckResult::down('Down'));

    $incident = $this->monitor->incidents()->ongoing()->first();

    expectPushSentTo([$this->recipient, $this->other], UptimeCheckFailedNotification::class,
        ownerId: $this->owner->getKey(), incidentId: $incident->getKey(), timeSensitive: true);

    Notification::fake();

    // Past the hourly resend: still the same outage.
    $this->travel(61)->minutes();
    $this->monitor->recordUptimeResult(CheckResult::down('Still down'));

    expectPushSentTo([$this->recipient, $this->other], UptimeCheckFailedNotification::class,
        ownerId: $this->owner->getKey(), incidentId: $incident->getKey(), timeSensitive: true);
});

/**
 * The page that first says a monitor is down is the one people act on, so it
 * names its outage however many failures it took to send.
 */
it('names the outage in the first failure news, whatever the threshold', function (int $threshold) {
    config()->set('monitoring.uptime.fire_failed_event_after_consecutive_failures', $threshold);

    Notification::fake();

    foreach (range(1, $threshold) as $ignored) {
        $this->travel(1)->minutes();
        $this->monitor->recordUptimeResult(CheckResult::down('Down'));
    }

    $incident = $this->monitor->incidents()->ongoing()->first();

    expectPushSentTo([$this->recipient, $this->other], UptimeCheckFailedNotification::class,
        ownerId: $this->owner->getKey(), incidentId: $incident->getKey(), timeSensitive: true);
})->with([1, 2, 3]);

/**
 * A monitor can be mid-outage with no incident on record — carried across by a
 * legacy import, or after its incidents were pruned. The resend that opens one
 * names it.
 */
it('names the outage a resend opens when none was on record', function () {
    config()->set('monitoring.uptime.fire_failed_event_after_consecutive_failures', 2);

    $this->monitor->forceFill([
        'uptime_status' => UptimeStatus::DOWN->value,
        'uptime_check_times_failed_in_a_row' => 5,
        'uptime_check_failed_event_fired_on_date' => now()->subHours(2),
        'uptime_status_last_change_date' => now()->subHours(3),
    ])->save();

    expect($this->monitor->incidents()->exists())->toBeFalse();

    Notification::fake();

    $this->monitor->recordUptimeResult(CheckResult::down('Still down'));

    $incident = $this->monitor->incidents()->ongoing()->first();

    expect($incident)->not->toBeNull();

    expectPushSentTo([$this->recipient, $this->other], UptimeCheckFailedNotification::class,
        ownerId: $this->owner->getKey(), incidentId: $incident->getKey(), timeSensitive: true);
});

/**
 * Sent for real rather than faked, so the email is written at the moment it
 * would be delivered — which, on a synchronous queue, is while the failure is
 * still being recorded.
 */
it('offers the mute link in the very first failure email', function () {
    Monitoring::resolveChannelsUsing(fn () => ['mail']);

    $bodies = [];

    Event::listen(NotificationSending::class, function (NotificationSending $sending) use (&$bodies): void {
        if ($sending->notification instanceof UptimeCheckFailedNotification && $sending->channel === 'mail') {
            $bodies[$sending->notifiable->getKey()] = implode(' ', $sending->notification->toMail($sending->notifiable)->introLines);
        }
    });

    $this->monitor->recordUptimeResult(CheckResult::down('Down'));

    $incident = $this->monitor->incidents()->ongoing()->first();

    expect($bodies)->toHaveCount(2);

    foreach ([$this->recipient, $this->other] as $recipient) {
        expect($bodies[$recipient->getKey()])
            ->toContain('Mute them')
            ->toContain('/incidents/'.$incident->getKey().'/mute/'.$recipient->getKey());
    }
});

/**
 * "The site is down" means a critical monitor is down. A non-critical monitor
 * still opens its own incident, but its failure is not urgent.
 */
it('does not call a non-critical monitor failure urgent', function () {
    $this->monitor->update(['critical' => false]);
    $this->monitor->recordUptimeResult(CheckResult::down('Down'));
    $incident = $this->monitor->incidents()->ongoing()->first();

    Notification::fake();

    $this->travel(61)->minutes();
    $this->monitor->recordUptimeResult(CheckResult::down('Still down'));

    expectPushSentTo([$this->recipient, $this->other], UptimeCheckFailedNotification::class,
        ownerId: $this->owner->getKey(), incidentId: $incident->getKey(), timeSensitive: false);
});

it('rates a certificate failure by whether its monitor is critical', function (bool $critical) {
    $this->monitor->update(['critical' => $critical]);

    Notification::fake();

    event(new CertificateCheckFailed($this->monitor, 'The certificate has expired'));

    expectPushSentTo([$this->recipient, $this->other], CertificateCheckFailedNotification::class,
        ownerId: $this->owner->getKey(), incidentId: null, timeSensitive: $critical);

    // During an outage the certificate failure is news about that outage — the
    // same one a mute on it would silence.
    $this->monitor->recordUptimeResult(CheckResult::down('Down'));
    $incident = $this->monitor->incidents()->ongoing()->first();

    Notification::fake();

    event(new CertificateCheckFailed($this->monitor, 'The certificate has expired'));

    expectPushSentTo([$this->recipient, $this->other], CertificateCheckFailedNotification::class,
        ownerId: $this->owner->getKey(), incidentId: $incident->getKey(), timeSensitive: $critical);
})->with(['critical' => true, 'not critical' => false]);

it('never calls an expiry warning urgent, nor part of an outage', function (string $event, string $notification) {
    $this->monitor->recordUptimeResult(CheckResult::down('Down'));

    expect($this->monitor->critical)->toBeTrue()
        ->and($this->monitor->incidents()->ongoing()->exists())->toBeTrue();

    Notification::fake();

    event(new $event($this->monitor));

    expectPushSentTo([$this->recipient, $this->other], $notification,
        ownerId: $this->owner->getKey(), incidentId: null, timeSensitive: false);
})->with([
    'certificate' => [CertificateExpiresSoon::class, CertificateExpiresSoonNotification::class],
    'domain' => [DomainExpiresSoon::class, DomainExpiresSoonNotification::class],
]);

/**
 * The recovery fires before its incident is resolved, so "the outage that just
 * ended" is the monitor's ongoing incident at that moment — never an earlier
 * outage that ended long ago.
 */
it('routes a recovery to the outage that just ended, without urgency', function () {
    $this->monitor->recordUptimeResult(CheckResult::down('Down'));
    $this->travel(10)->minutes();
    $this->monitor->recordUptimeResult(CheckResult::up(100));
    $earlier = $this->monitor->incidents()->first();

    $this->travel(10)->minutes();
    $this->monitor->recordUptimeResult(CheckResult::down('Down again'));
    $incident = $this->monitor->incidents()->ongoing()->first();

    Notification::fake();

    $this->travel(10)->minutes();
    $this->monitor->recordUptimeResult(CheckResult::up(100));

    expect($incident->is($earlier))->toBeFalse()
        ->and($incident->fresh()->isOngoing())->toBeFalse();

    expectPushSentTo([$this->recipient, $this->other], UptimeCheckRecoveredNotification::class,
        ownerId: $this->owner->getKey(), incidentId: $incident->getKey(), timeSensitive: false);
});

it('never calls a heartbeat recovery urgent, even during an outage', function () {
    $this->monitor->recordUptimeResult(CheckResult::down('Down'));

    $heartbeat = $this->site->heartbeats()->create([
        'name' => 'Nightly digest',
        'token' => Str::random(48),
        'interval_minutes' => 60,
        'status' => HeartbeatStatus::MISSING->value,
    ]);

    Notification::fake();

    event(new HeartbeatRecovered($heartbeat));

    expectPushSentTo([$this->recipient, $this->other], HeartbeatRecoveredNotification::class,
        ownerId: $this->owner->getKey(), incidentId: null, timeSensitive: false);
});

/**
 * A missed job or a new error is urgent only when it happens during the site's
 * outage — an open incident on a critical monitor — and then it belongs to that
 * outage.
 */
it('rates site news by whether the site is down', function (string $kind) {
    [$event, $notification] = siteNews($this->site, $kind);
    Notification::fake();
    event($event);

    expectPushSentTo([$this->recipient, $this->other], $notification,
        ownerId: $this->owner->getKey(), incidentId: null, timeSensitive: false);

    // A non-critical monitor's outage is its own business, not the site's.
    $minor = $this->site->monitors()->create(['url' => 'https://minor.test/', 'critical' => false]);
    $minor->recordUptimeResult(CheckResult::down('Down'));

    expect($minor->incidents()->ongoing()->exists())->toBeTrue();

    [$event, $notification] = siteNews($this->site, $kind);
    Notification::fake();
    event($event);

    expectPushSentTo([$this->recipient, $this->other], $notification,
        ownerId: $this->owner->getKey(), incidentId: null, timeSensitive: false);

    // A critical monitor down: the site is down.
    $this->travel(5)->minutes();
    $this->monitor->recordUptimeResult(CheckResult::down('Down'));
    $incident = $this->monitor->incidents()->ongoing()->first();

    [$event, $notification] = siteNews($this->site, $kind);
    Notification::fake();
    event($event);

    expectPushSentTo([$this->recipient, $this->other], $notification,
        ownerId: $this->owner->getKey(), incidentId: $incident->getKey(), timeSensitive: true);

    // And once it recovers, it is not.
    $this->travel(5)->minutes();
    $this->monitor->recordUptimeResult(CheckResult::up(100));

    [$event, $notification] = siteNews($this->site, $kind);
    Notification::fake();
    event($event);

    expectPushSentTo([$this->recipient, $this->other], $notification,
        ownerId: $this->owner->getKey(), incidentId: null, timeSensitive: false);
})->with(['heartbeat', 'exception']);

it('works out push metadata once per event, not once per recipient', function () {
    $this->monitor->recordUptimeResult(CheckResult::down('Down'));
    $incident = $this->monitor->incidents()->ongoing()->first();

    $crowd = collect(range(1, 5))
        ->map(fn (int $i) => User::create(['name' => "Crowd {$i}", 'email' => "crowd{$i}@test.dev"]))
        ->all();

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $queriesToTell = function (array $recipients) use (&$queries, $incident): int {
        Monitoring::resolveRecipientsUsing(fn () => $recipients);
        [$event, $notification] = siteNews($this->site, 'exception');
        Notification::fake();

        $queries = 0;
        event($event);
        $counted = $queries;

        expectPushSentTo($recipients, $notification,
            ownerId: $this->owner->getKey(), incidentId: $incident->getKey(), timeSensitive: true);

        return $counted;
    };

    $one = $queriesToTell([$this->recipient]);
    $seven = $queriesToTell([$this->recipient, $this->other, ...$crowd]);

    expect($one)->toBeGreaterThan(0)
        ->and($seven)->toBe($one);
});

it('still builds from the two arguments it always took', function () {
    $notification = new UptimeCheckFailedNotification($this->monitor, ['mail']);

    expect($notification)->toBeInstanceOf(ProvidesPushMessage::class)
        ->and($notification->subject)->toBe($this->monitor)
        ->and($notification->channels)->toBe(['mail'])
        ->and($notification->ownerId)->toBeNull()
        ->and($notification->incidentId)->toBeNull()
        ->and($notification->timeSensitive)->toBeFalse();
});

/**
 * Built outside the subscriber, a notification has no owner — and guessing one
 * from whoever is signed in would send the push to the wrong team.
 */
it('refuses to describe a push without knowing whose news it is', function () {
    pushingNotification($this->monitor, ['mail'])->toPush($this->recipient);
})->throws(LogicException::class, 'owner');

it('says plainly when a notification has not described itself for push', function () {
    $notification = new class($this->monitor, ['mail'], $this->owner->getKey()) extends MonitoringNotification {};

    $notification->toPush($this->recipient);
})->throws(LogicException::class, 'toPush()');

it('describes a push from what it was handed, without asking the database again', function (string $kind) {
    $this->freezeTime();

    $subject = match ($kind) {
        'monitor' => $this->monitor,
        'heartbeat' => $this->site->heartbeats()->create([
            'name' => 'Nightly digest',
            'token' => Str::random(48),
            'interval_minutes' => 60,
        ]),
        'exception' => $this->site->siteExceptions()->create([
            'fingerprint' => str_repeat('b', 64),
            'exception_class' => 'RuntimeException',
            'message' => 'Payment gateway returned an unexpected response',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]),
    };

    // No incident 777 exists: the id is carried, never looked up.
    $notification = pushingNotification($subject, ['mail'], $this->owner->getKey(), 777, true);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $message = $notification->toPush($this->recipient);

    expect($queries)->toBe(0)
        ->and($message->toArray())->toBe([
            'event_type' => 'uptime_failed',
            'title' => 'Site is down',
            'body' => 'site.test failed its uptime check.',
            'owner_id' => $this->owner->getKey(),
            'destination_type' => $kind,
            'destination_id' => $subject->getKey(),
            'incident_id' => 777,
            'occurred_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'time_sensitive' => true,
        ]);
})->with(['monitor', 'heartbeat', 'exception']);

/**
 * A push is described in a queue worker, once per recipient and per channel,
 * possibly long after the event and again on every retry. What it reports is
 * when the news was built, not when a worker got round to it.
 */
it('reports when the news happened, not when a worker got round to describing it', function () {
    $this->freezeTime();
    $builtAt = now()->utc()->format('Y-m-d\TH:i:s\Z');

    $queued = serialize(pushingNotification($this->monitor, ['mail'], $this->owner->getKey(), 777, true));

    $this->travel(17)->minutes();

    $message = unserialize($queued)->toPush($this->recipient);

    expect(now()->utc()->format('Y-m-d\TH:i:s\Z'))->not->toBe($builtAt)
        ->and($message->toArray()['occurred_at'])->toBe($builtAt);
});

it('keeps what the subscriber worked out through the queue', function () {
    $this->freezeTime();

    Notification::fake()->serializeAndRestore();

    $this->monitor->recordUptimeResult(CheckResult::down('Down'));
    $incident = $this->monitor->incidents()->ongoing()->first();
    $builtAt = now()->toImmutable();

    $this->travel(17)->minutes();

    expectPushSentTo([$this->recipient, $this->other], UptimeCheckFailedNotification::class,
        ownerId: $this->owner->getKey(), incidentId: $incident->getKey(), timeSensitive: true);

    Notification::assertSentTo([$this->recipient, $this->other], UptimeCheckFailedNotification::class,
        fn (UptimeCheckFailedNotification $notification) => $notification->occurredAt->equalTo($builtAt));
});

/**
 * Everyone told about one event is told about the same moment. Working out a
 * recipient's channels can be slow — a host's resolver may read preferences
 * from somewhere far away — and the last person in the list must not hear that
 * the outage began later than the first person did.
 */
it('gives everyone told about one event the same moment, however slow telling each of them is', function () {
    $this->freezeTime();
    $eventAt = now()->toImmutable();

    Monitoring::resolveChannelsUsing(function (): array {
        $this->travel(2)->seconds();

        return ['mail'];
    });

    Notification::fake();

    $this->monitor->recordUptimeResult(CheckResult::down('Down'));

    // Each recipient's channels took two seconds to work out.
    expect(now()->diffInSeconds($eventAt, absolute: true))->toBeGreaterThanOrEqual(4.0);

    $moments = collect([$this->recipient, $this->other])
        ->map(fn (User $recipient) => Notification::sent($recipient, UptimeCheckFailedNotification::class)->sole()->occurredAt)
        ->map(fn (CarbonImmutable $moment) => $moment->utc()->format('Y-m-d\TH:i:s.u\Z'))
        ->all();

    expect($moments)->toBe(array_fill(0, 2, $eventAt->utc()->format('Y-m-d\TH:i:s.u\Z')));
});

/**
 * A notification that knows better than the clock when its news happened says
 * so, and that moment is reported as it was — in UTC, whatever timezone it was
 * given in, and unmoved by anything done to the value afterwards.
 */
it('reports a more authoritative moment in UTC rather than when it was built', function () {
    $this->freezeTime();

    $notification = new class($this->monitor, ['mail'], $this->owner->getKey()) extends MonitoringNotification
    {
        public ?CarbonInterface $happenedAt = null;

        public function toPush(object $notifiable): PushMessage
        {
            return $this->buildPushMessage('uptime_failed', 'Site is down', 'site.test failed its uptime check.', $this->happenedAt);
        }
    };

    // Winnipeg is six hours behind UTC in March, so this is early on the 2nd there.
    $happenedAt = Carbon::parse('2026-03-01 18:30:15', 'America/Winnipeg');
    $notification->happenedAt = $happenedAt;

    $message = $notification->toPush($this->recipient);
    $happenedAt->addHour();

    expect($message->toArray()['occurred_at'])->toBe('2026-03-02T00:30:15Z')
        ->and($message->occurredAt->getTimezone()->getName())->toBe('UTC')
        ->and($message->occurredAt->toDateTimeString())->toBe('2026-03-02 00:30:15')
        ->and($notification->occurredAt->equalTo(now()))->toBeTrue();
});

/**
 * A recovery is news about the outage ending now. A monitor can be alerting
 * with no outage on record — carried across by an import, or pruned — and its
 * recovery must not reach back to one that ended long ago.
 */
it('never routes a recovery to an outage that ended long ago', function () {
    $earlier = $this->monitor->incidents()->create([
        'site_id' => $this->site->id,
        'started_at' => now()->subDays(2),
        'resolved_at' => now()->subDays(2)->addHour(),
        'duration_seconds' => 3600,
        'failure_reason' => 'Down',
    ]);

    $this->monitor->forceFill([
        'uptime_status' => UptimeStatus::DOWN->value,
        'uptime_check_times_failed_in_a_row' => 5,
        'uptime_check_failed_event_fired_on_date' => now()->subHours(2),
        'uptime_status_last_change_date' => now()->subHours(3),
    ])->save();

    expect($this->monitor->incidents()->ongoing()->exists())->toBeFalse()
        ->and($earlier->fresh()->isOngoing())->toBeFalse();

    Notification::fake();

    $this->monitor->recordUptimeResult(CheckResult::up(100));

    expectPushSentTo([$this->recipient, $this->other], UptimeCheckRecoveredNotification::class,
        ownerId: $this->owner->getKey(), incidentId: null, timeSensitive: false);
});

it('refuses to describe a push that leads nowhere a consumer can open', function () {
    pushingNotification($this->site, ['mail'], $this->owner->getKey())->toPush($this->recipient);
})->throws(LogicException::class, 'no push destination');
