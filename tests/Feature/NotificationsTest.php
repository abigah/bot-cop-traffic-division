<?php

use Abigah\BotCopTrafficDivision\Enums\HeartbeatStatus;
use Abigah\BotCopTrafficDivision\Events\HeartbeatMissed;
use Abigah\BotCopTrafficDivision\Events\HeartbeatRecovered;
use Abigah\BotCopTrafficDivision\Events\SiteExceptionReported;
use Abigah\BotCopTrafficDivision\Facades\Monitoring;
use Abigah\BotCopTrafficDivision\Models\MonitoredSite;
use Abigah\BotCopTrafficDivision\Notifications\HeartbeatMissedNotification;
use Abigah\BotCopTrafficDivision\Notifications\HeartbeatRecoveredNotification;
use Abigah\BotCopTrafficDivision\Notifications\SiteExceptionReportedNotification;
use Abigah\BotCopTrafficDivision\Notifications\UptimeCheckFailedNotification;
use Abigah\BotCopTrafficDivision\Notifications\UptimeCheckRecoveredNotification;
use Abigah\BotCopTrafficDivision\Support\CheckResult;
use Abigah\BotCopTrafficDivision\Tests\Fixtures\User;
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
