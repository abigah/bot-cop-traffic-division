<?php

use Abigah\BotCopTrafficDivision\Facades\Monitoring;
use Abigah\BotCopTrafficDivision\Models\MonitoredSite;
use Abigah\BotCopTrafficDivision\Notifications\UptimeCheckFailedNotification;
use Abigah\BotCopTrafficDivision\Support\CheckResult;
use Abigah\BotCopTrafficDivision\Tests\Fixtures\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    config()->set('monitoring.uptime.fire_failed_event_after_consecutive_failures', 1);

    $this->recipient = User::create(['name' => 'On call', 'email' => 'oncall@test.dev']);
    $this->site = MonitoredSite::create(['name' => 'Site', 'owner_id' => 1]);
    $this->monitor = $this->site->monitors()->create(['url' => 'https://site.test/']);

    Monitoring::resolveOwnerForSiteUsing(fn () => $this->recipient);
    Monitoring::resolveRecipientsUsing(fn () => [$this->recipient]);
    Monitoring::resolveChannelsUsing(fn () => ['mail']);

    $this->monitor->recordUptimeResult(CheckResult::down('Connection refused'));
    $this->incident = $this->monitor->incidents()->ongoing()->first();
});

function muteUrl($incident, $recipient, bool $undo = false): string
{
    return URL::signedRoute('monitoring.incident.mute', array_filter([
        'incident' => $incident->getKey(),
        'notifiable' => $recipient->getKey(),
        'undo' => $undo ? 1 : null,
    ]));
}

/**
 * Someone woken at 3am should not have to log in to make the phone stop, so the
 * link is signed rather than authenticated.
 */
it('mutes an outage from a signed link with no session', function () {
    $this->get(muteUrl($this->incident, $this->recipient))
        ->assertOk()
        ->assertSee('Notifications muted');

    expect($this->incident->isMutedBy($this->recipient))->toBeTrue();
});

it('refuses an unsigned link', function () {
    $this->get("/incidents/{$this->incident->id}/mute/{$this->recipient->id}")
        ->assertForbidden();

    expect($this->incident->fresh()->isMutedBy($this->recipient))->toBeFalse();
});

it('unmutes again through the undo link', function () {
    $this->get(muteUrl($this->incident, $this->recipient))->assertOk();
    $this->get(muteUrl($this->incident, $this->recipient, undo: true))
        ->assertOk()
        ->assertSee('Notifications resumed');

    expect($this->incident->fresh()->isMutedBy($this->recipient))->toBeFalse();
});

it('is idempotent, so a second click changes nothing', function () {
    $this->get(muteUrl($this->incident, $this->recipient))->assertOk();
    $this->get(muteUrl($this->incident, $this->recipient))->assertOk();

    expect($this->incident->mutedBy()->count())->toBe(1);
});

it('puts the mute link in the failure email', function () {
    $notification = new UptimeCheckFailedNotification($this->monitor, ['mail']);

    $body = implode(' ', $notification->toMail($this->recipient)->introLines);

    expect($body)->toContain('Mute them')
        ->and($body)->toContain('/incidents/'.$this->incident->id.'/mute/'.$this->recipient->id);
});

/**
 * The subscriber has already found the outage, so the link names that one
 * rather than asking again for every recipient — and the link in the email and
 * the push can never disagree about which outage they mean.
 */
it('names the outage it was handed in the mute link, without looking it up again', function () {
    // No incident 777 exists: the id is carried, never looked up.
    $notification = new UptimeCheckFailedNotification($this->monitor, ['mail'], $this->recipient->getKey(), 777, true);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $body = implode(' ', $notification->toMail($this->recipient)->introLines);

    expect($queries)->toBe(0)
        ->and($body)->toContain('Mute them')
        ->and($body)->toContain('/incidents/777/mute/'.$this->recipient->id);
});

/**
 * Built directly with the two arguments it always took, a failure email still
 * finds the monitor's ongoing outage for its link — and offers none once there
 * is no outage left to mute.
 */
it('finds the ongoing outage for the mute link when it was handed none', function () {
    $body = implode(' ', (new UptimeCheckFailedNotification($this->monitor, ['mail']))->toMail($this->recipient)->introLines);

    expect($body)->toContain('/incidents/'.$this->incident->id.'/mute/'.$this->recipient->id);

    $this->monitor->recordUptimeResult(CheckResult::up(100));

    expect($this->incident->fresh()->isOngoing())->toBeFalse();

    $body = implode(' ', (new UptimeCheckFailedNotification($this->monitor, ['mail']))->toMail($this->recipient)->introLines);

    expect($body)->not->toContain('Mute them')
        ->and($body)->not->toContain('/mute/');
});

/**
 * A mute expires with the outage it silenced, so nobody accidentally mutes
 * themselves forever. The next outage is a new incident, and starts unmuted.
 */
it('does not carry over to the next outage', function () {
    $this->get(muteUrl($this->incident, $this->recipient))->assertOk();

    $this->monitor->recordUptimeResult(CheckResult::up(100));
    $this->monitor->recordUptimeResult(CheckResult::down('Down again'));

    $second = $this->monitor->incidents()->ongoing()->first();

    expect($second->is($this->incident))->toBeFalse()
        ->and($second->isMutedBy($this->recipient))->toBeFalse();
});

it('mutes an outage for a while with one row for the recipient', function () {
    $this->freezeTime();

    $this->incident->muteFor($this->recipient, now()->addMinutes(15));

    expect($this->incident->mutedBy()->count())->toBe(1)
        ->and($this->incident->mutedBy()->first()->pivot->muted_until)
        ->toBe(now()->addMinutes(15)->toDateTimeString());
});

/**
 * Choosing again replaces the earlier choice — a recipient who asked for fifteen
 * minutes and then for an hour wants an hour, not two mutes racing each other.
 */
it('replaces a recipient\'s earlier choice rather than adding another', function () {
    $this->freezeTime();

    $this->incident->muteFor($this->recipient, now()->addMinutes(15));
    $this->incident->muteFor($this->recipient, now()->addHour());

    expect($this->incident->mutedBy()->count())->toBe(1)
        ->and($this->incident->mutedBy()->first()->pivot->muted_until)
        ->toBe(now()->addHour()->toDateTimeString());
});

it('mutes until recovery when no expiry is given', function () {
    $this->incident->muteFor($this->recipient, null);

    expect($this->incident->mutedBy()->count())->toBe(1)
        ->and($this->incident->mutedBy()->first()->pivot->muted_until)->toBeNull()
        ->and($this->incident->isMutedBy($this->recipient))->toBeTrue()
        ->and($this->incident->isMutedBy($this->recipient, now()->addWeek()))->toBeTrue();
});

/**
 * An expired row is inactive the moment it expires, whether or not anything has
 * come along to delete it yet.
 */
it('counts a timed mute only until it expires', function () {
    $this->freezeTime();

    $this->incident->muteFor($this->recipient, now()->addMinutes(15));

    expect($this->incident->isMutedBy($this->recipient))->toBeTrue()
        ->and($this->incident->isMutedBy($this->recipient, now()->addMinutes(14)))->toBeTrue()
        ->and($this->incident->isMutedBy($this->recipient, now()->addMinutes(15)))->toBeFalse()
        ->and($this->incident->isMutedBy($this->recipient, now()->addMinutes(16)))->toBeFalse();

    $this->travel(16)->minutes();

    expect($this->incident->isMutedBy($this->recipient))->toBeFalse()
        ->and($this->incident->mutedBy()->count())->toBe(1);
});

it('does not count a mute whose expiry has already passed', function () {
    $this->incident->muteFor($this->recipient, now()->subMinute());

    expect($this->incident->isMutedBy($this->recipient))->toBeFalse();
});

it('unmutes only the recipient who asks', function () {
    $colleague = User::create(['name' => 'Colleague', 'email' => 'colleague@test.dev']);

    $this->incident->muteFor($this->recipient, null);
    $this->incident->muteFor($colleague, now()->addHour());

    $this->incident->unmuteFor($this->recipient);

    expect($this->incident->isMutedBy($this->recipient))->toBeFalse()
        ->and($this->incident->isMutedBy($colleague))->toBeTrue()
        ->and($this->incident->mutedBy()->count())->toBe(1);
});

it('keeps each recipient\'s own choice for the same outage', function () {
    $this->freezeTime();

    $colleague = User::create(['name' => 'Colleague', 'email' => 'colleague@test.dev']);

    $this->incident->muteFor($this->recipient, now()->addMinutes(15));
    $this->incident->muteFor($colleague, null);

    $this->travel(1)->hour();

    expect($this->incident->isMutedBy($this->recipient))->toBeFalse()
        ->and($this->incident->isMutedBy($colleague))->toBeTrue();
});

/**
 * The link in an email predates timed mutes and keeps meaning what it always
 * meant: quiet until this outage is over. Following it replaces a shorter mute.
 */
it('mutes until recovery from the signed link, replacing a timed mute', function () {
    $this->incident->muteFor($this->recipient, now()->addMinutes(15));

    $this->get(muteUrl($this->incident, $this->recipient))->assertOk();

    $this->travel(1)->day();

    expect($this->incident->mutedBy()->count())->toBe(1)
        ->and($this->incident->mutedBy()->first()->pivot->muted_until)->toBeNull()
        ->and($this->incident->isMutedBy($this->recipient))->toBeTrue();
});
