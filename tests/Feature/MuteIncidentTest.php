<?php

use Abigah\BotCopTrafficDivision\Facades\Monitoring;
use Abigah\BotCopTrafficDivision\Models\MonitoredSite;
use Abigah\BotCopTrafficDivision\Notifications\UptimeCheckFailedNotification;
use Abigah\BotCopTrafficDivision\Support\CheckResult;
use Abigah\BotCopTrafficDivision\Tests\Fixtures\User;
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
