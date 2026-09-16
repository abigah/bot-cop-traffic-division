# Changelog

## v0.1.11

**The screens ask the probers for what they are holding.**

Results batch on the prober's slow cadence because every delivery wakes an
application that sleeps between requests. That is the right trade while nobody
is looking and the wrong one the moment somebody is: the application is awake,
the wake has been paid for, and the screens were still showing checks that
stopped at the last batch.

Opening the dashboard, a site or a monitor's history now sends
`POST {prober}/tenants/{tenant}/flush` — empty-bodied, HMAC-signed, queued — if
the last delivery is older than `monitoring.flush_when_stale_after_minutes`
(default 5, zero to turn it off). The prober answers by delivering through the
same endpoint and cursor as any scheduled batch; nothing is pulled and there is
no second way for results to arrive.

It only ever buys the green. Anything down, and any monitor changing status at
all, was already delivered within the minute, so no screen was ever wrong about
an outage — what it was missing was the "still up" checks since the last batch
and the last-checked times that go with them.

**Needs a prober that serves the endpoint.** Older probers answer 404 and the
notice is logged and dropped, which leaves the screens exactly as stale as they
were before.

**Also carries the tests for v0.1.10's features.** Incident acknowledgement,
timed mutes and the push descriptions shipped in v0.1.10 ahead of their tests.
Nothing about those features changes here; they are only covered now.

## v0.1.10

Four things a host can build on: acknowledging an incident, muting one for a
while, a Push preference, and a description of every event fit for a push. They
arrive with three migrations and a few changes every host will see, whether or
not it uses any of the four, so those come first.

**Upgrading.**

Run `php artisan migrate` before the new code serves requests. Three migrations
add columns:

- `acknowledged_at` and `acknowledged_by` to `monitor_incidents`;
- `muted_until` to `monitor_incident_notification_mutes`;
- `push_enabled` to `monitor_notification_preferences`.

Migrate first because every preference row the package inserts now writes
`push_enabled`. Existing rows keep their meaning: nobody has acknowledged
anything, every mute lasts until recovery, and nobody is pushed to. All three
migrations roll back.

A published `config/monitoring.php` needs no change, because
`notification_channels.push` is null when it is absent. To offer Push, set it to
the host's channel class:

```php
'notification_channels' => [
    'push' => \App\Notifications\Channels\PushChannel::class,
],
```

A host that published the package's views (the `monitoring-views` tag) and
overrode `livewire/notification-preferences.blade.php` has to republish that
view or merge the new one into its copy. Until it does, the screen still labels
the `database` channel "In the app", offers no Push checkbox, and summarises
each site by raw channel names, including the push channel's class for a
recipient with Push on.

**Behaviour changes for all hosts.**

**An outage is open before it is announced.** `UptimeCheckFailed` used to fire
from inside `markUptimeDown()`, before the check was written and before the
incident was opened. It now fires from `recordUptimeResult()`, after both. So a
listener sees the check and the incident, and the first failure news of an
outage names its incident, whatever
`fire_failed_event_after_consecutive_failures` says.

A listener that throws still passes its error to the caller, but it no longer
loses the check: by then the check, the incident and the alert's sent time are
already written. The alert counts as sent, so it is not repeated before the
resend interval. A prober retrying the same delivery replays as nothing, rather
than counting the failure a second time.

Two smaller consequences follow:

- A recovery's push routes only to the outage that is ending. With no ongoing
  incident it carries none, never one that ended long ago.
- The mute link in a failure email uses the incident the notification was built
  with, so it is there from the very first email.

**The preferences screen says "Desktop".** The package's preferences screen
labels the `database` channel's checkbox "Desktop" where it said "In the app".
Only the label changed: the `database_enabled` column and the `database` channel
keep their names. A site's summary line lists its channels by those labels
("Email, Desktop") rather than by channel names such as `mail, database`.

**The mute link replaces a timed mute.** The signed mute link in a failure email
still means "until this outage is over". When the recipient has chosen a timed
mute somewhere else, following the link replaces it with a mute until recovery.

**Added: acknowledging an incident.**

`MonitorIncident::acknowledge($user, $at = null)` records who has an outage in
hand and when, and returns whether this call was the one recorded. The first
acknowledgement wins. The write is a single update that lands only while
`acknowledged_at` is empty, so two people answering at once, or a client
retrying, cannot replace who answered or when. It still lands after the outage
has resolved, because a late answer was still an answer. Either way the model is
refreshed to show the acknowledgement that stands.

`acknowledgedByUser()` resolves the person through `monitoring.notifiable_model`.
`acknowledged_by` is a plain column of the same type as `dismissed_by`, with its
own index and no foreign key to the host's table.

**Added: muting an incident for a while.**

`muteFor($user, $until)` silences one outage for one recipient until a time, or
until recovery when `$until` is null. Choosing again replaces the earlier choice
rather than adding a second one. `unmuteFor($user)` removes only that
recipient's mute. `isMutedBy($user, $at = null)` says whether their mute is live
at that moment, and now by default.

A mute whose time has passed is inactive from that moment, whether or not
anything has deleted its row: both the subscriber and `isMutedBy()` compare
against the clock. `mutedBy()` still returns every recorded choice, expired ones
included, because replacing a choice needs the row that is there. Use
`isMutedBy()` to ask whether a mute is live. Recoveries are never muted, whatever
kind of mute is held.

`muteFor()` writes its row even when the incident has already resolved. That
changes no delivery, because only ongoing incidents are consulted when deciding
who is muted. A host that wants muting a resolved incident to do nothing should
check `isOngoing()` first.

Times passed to `muteFor()`, `isMutedBy($at)` and `acknowledge($at)` are stored
and compared as given, as Laravel usually does, with no conversion between
timezones. Pass them in the application's timezone, as `now()->addMinutes(15)`
is.

**Added: a Push preference, delivered by the host.**

`monitoring.notification_channels.push` names a Laravel notification channel
class that the host supplies. The package delivers no push itself. It stores
whether each recipient wants one, in a new `push_enabled` column defaulting to
false. `channelsForEvent()` adds the class to a recipient's channels only when
that switch is on *and* the class is set. Leave the key null and nothing
changes: Push is not offered, and it is never added, whatever a saved preference
says.

When the key is set, the preferences screen offers a Push checkbox at site and
monitor level.

**Added: a description of every event for push, tied to no provider.**

`Support\PushMessage` is a readonly value object holding what a push channel
needs. `toArray()` gives these keys:

- `event_type`, `title` and `body`;
- `owner_id`;
- `destination_type` and `destination_id`, where tapping the push should lead.
  The type is `monitor`, `heartbeat` or `exception`, and anything else is
  refused;
- `incident_id`;
- `occurred_at`, an ISO-8601 UTC string in whole seconds with a trailing `Z`;
- `time_sensitive`.

It names no delivery provider. The host's channel turns it into whatever its
provider expects.

`PushMessage::TITLE_MAX_LENGTH` is 64 and `BODY_MAX_LENGTH` is 178, both counted
in Unicode code points. The package's own notifications stay within them: they
cut text between characters as a person sees them and end it with an ellipsis.
The class does not enforce them, though, so a channel that accepts messages
built elsewhere should check for itself.

`Contracts\ProvidesPushMessage` declares `toPush(object $notifiable): PushMessage`,
and all eight notifications implement it:

| Notification | `event_type` | destination |
|---|---|---|
| Uptime check failed | `uptime_failed` | `monitor` |
| Uptime check recovered | `uptime_recovered` | `monitor` |
| Certificate check failed | `certificate_failed` | `monitor` |
| Certificate expires soon | `certificate_expires_soon` | `monitor` |
| Domain expires soon | `domain_expires_soon` | `monitor` |
| Heartbeat missed | `heartbeat_missing` | `heartbeat` |
| Heartbeat recovered | `heartbeat_recovered` | `heartbeat` |
| Site exception reported | `exception_reported` | `exception` |

A push's words name what the event concerns and leave out anything that could
leak:

- **A monitor** is named by its URL's host. The rest of the URL can carry
  credentials or a query string, so it stays out. A URL with no clean host falls
  back to the monitor's name, then its number.
- **A scheduled job** is named by its own name, or its number when that name
  gives nothing to read.
- **An exception** is named by its short class, never its message, file or
  trace.
- **Nothing else goes in:** no failure reason, status or expiry date.
  Control characters and invisible characters are stripped.
- **Heartbeat and exception pushes also name the site**, when the site has a
  `name`.

The subscriber works five things out once per event and hands the same answers
to every recipient: the owner, the incident, the Time Sensitive flag, the site's
name and the moment. A queue worker describing the push never looks those up
again, so a retried job reports when the event happened, not when a worker got
to it, and the site's name is the one it had then. The subject is another
matter: the worker reloads it, so a monitor's URL or name, or a scheduled job's
name, changed before delivery is what the push says.

Time Sensitive is true only in two cases: a critical monitor's uptime or
certificate failure, or a missed heartbeat or new exception while the site's
outage is open. Warnings and recoveries never are. `incident_id` depends on the
event:

- a monitor's failures and its recovery carry the monitor's ongoing incident;
- heartbeat and exception news carry the site's open incident;
- expiry warnings and heartbeat recoveries carry none.

**Breaking, for code that extends package classes.**

Public calls keep working. These protected APIs changed:

- `MonitorEventSubscriber::mutedRecipientKeys()` takes the event's
  `?MonitorIncident` instead of the subject.
- `MonitorEventSubscriber::ownerFor()` is now `ownerAndSiteFor()`, and returns
  `[$owner, $site]`.
- `MonitorEventSubscriber::siteIncidentFor(Model $subject, ?Model $site = null)`
  gained its second argument, which an override has to accept.
- The subscriber has new protected `incidentFor(Model $subject, string
  $eventType, ?Model $site = null)`, `isTimeSensitive()` and `siteNameFor()`.
- `Monitor::markUptimeDown()` returns `bool`, meaning whether to alert, and no
  longer fires `UptimeCheckFailed` itself.
- `MonitoringNotification`'s constructor gained optional trailing `ownerId`,
  `incidentId`, `timeSensitive`, `occurredAt` and `siteName` arguments.
  `new SomeNotification($subject, $channels)` still works. Pass the new
  arguments by name, since more may be added after them.
- `MonitoringNotification` implements `ProvidesPushMessage`. Its base `toPush()`
  throws `LogicException` until a subclass maps it, and so does
  `buildPushMessage()` on a notification built without an owner id. The new
  protected helpers are `buildPushMessage()`, `pushBody()`,
  `pushNameForMonitor()`, `pushNameForHeartbeat()`, `pushNameForException()` and
  `pushDestination()`.

**Known issue: anonymous exception classes in mail and SMS.**

An exception thrown from an anonymous class is still named in the mail subject
and SMS text by a label taken from the file it was declared in, such as
`Thrower.php:3$0`. `shortClass()` takes the last path segment of PHP's generated
class name. This is cosmetic and unchanged from earlier releases. The push names
the class the anonymous class extends.

## v0.1.9

**Fixed: the legacy import brought deleted incidents back.**

`monitoring:import:legacy` passed each incident's `deleted_at` alongside its
other columns, and `deleted_at` is not fillable on `MonitorIncident`, so it was
silently discarded: every incident deleted on the old install arrived undeleted
and showed on the incidents screen again. It is now forced, and the incident is
looked up with trashed rows included, so a second run finds one that arrived
deleted rather than adding a copy.

Installs that already imported can run the import again to put the deletions
back — but it rewrites every monitor from the source as well, so on an install
where monitors have been changed since, delete the affected incidents by hand
instead.

## v0.1.8

**Fixed: the dashboard's response time chart never drew a bar.**

The bars came from an Alpine `x-for` on a `<template>` inside the `<svg>`.
Inside SVG the HTML parser makes `<template>` an SVG element with no `content`,
so Alpine's loop threw on its first clone and the chart stayed empty above its
axis labels, whatever the data held. The bars are drawn in Blade now, so they
are in the page as served and need no JavaScript.

**Note for installs that build their own CSS.** The bars are coloured with
`fill-sky-500` and `fill-red-500`, used nowhere else. A Tailwind build that does
not scan this package's views purges them, and an unstyled `<rect>` fills black.
Point an `@source` at `vendor/abigah/bot-cop-traffic-division/resources/views`.

## v0.1.7

**Fixed: the import and backfill refused a resolver that declines.**

Before doing anything, both commands asked the site resolver about
`https://example.test/` and took null to mean no resolver was wired. Null is the
documented way to decline a monitor, so a resolver that only matches sites the
host already has — the kind that never invents rows in a table the package does
not own — stopped both commands with "Wire
Monitoring::resolveSiteForMonitorUsing()" while wired. A resolver that creates
sites fared worse: it was handed the made-up URL and made a site for it on every
run, dry runs included.

The check now asks only whether a resolver is wired.

**Documented: a resolver is called on a dry run.**

`--dry-run` stops the package creating sites of its own, but a host's resolver
is the host's code and is called the same way either way, so one that creates
sites creates them during a dry run. Said beside the resolver in the guide.

## v0.1.6

**Fixed: response-time statistics counted failed checks.**

`uptime` filtered on status; `avg`, `min` and `max` did not. A failed check
still carries a duration and it is not a response time — a Cloudflare challenge
refused at the edge comes back in two milliseconds, faster than any real page,
and a timeout takes the whole timeout. So a blocked monitor read as a monitor at
its best: twelve 403s at 2-22ms and three 429s at 422-940ms produced "Uptime 0%"
beside "Average 123ms", with a 2ms rejection reported as the site's fastest
response. All three columns, in `MonitorChartService` and in the chart series,
now describe only the checks that answered.

**Fixed: rolled-up averages were weighted by the wrong population.**

A mean of means must weight by the population its parts describe. Both the daily
rollup and `combinedStats` weighted `avg_response_time_ms` by `total_checks`,
counting hours full of failures as though they had contributed response times.
They now weight by `up_checks`.

**Note for existing installs.** `monitor_check_aggregates` rows written before
this change hold averages taken over every check, and the raw rows they came
from have been pruned, so they cannot be recomputed. Buckets with no failures
are unaffected — the two definitions agree there. Buckets that contain failures
keep their old, lower numbers; new buckets do not. A bucket in which nothing
answered now stores `null` for all three columns rather than a number, which the
schema already allowed.

## v0.1.5

**Changed: a shortfall now fails the import.**

Each pass already reported `imported of available`; a mismatch was a warning.
It is now a non-zero exit, because the failure this guards against is silent by
nature — the paging bug reported success while dropping around nine per cent of
a client's aggregate history, and a warning is not something a deploy script
reads.

**Changed: the import is no longer a query per row.**

Checks looked up each row's existence individually and aggregates wrote one at a
time. Over a link to another region that meant roughly four rows a second, and
75 minutes for a modest history. Checks now do one lookup and one batched insert
per chunk; aggregates upsert per chunk against their own unique key.

**Documented: `--checks-days` does not preserve raw history.**

The hourly rollup aggregates raw checks older than two hours and deletes them,
so importing thirty days of raw checks gets thirty days of rows the next
scheduled run folds into buckets. Nothing is lost — the aggregates carry that
history — but the raw table settling back to a couple of hours' worth reads like
a fault unless you knew. Said at the option, in the guide, and once at run time.

**Documented: pausing, and the DNS table with no writer.**

Both sections were written for v0.1.3 and v0.1.4 and neither reached the file:
the edits silently matched nothing. They are there now.

## v0.1.4

**Added: `monitoring:import:legacy` carries DNS lookups and Forge links.**

Two tables the hub has homes for were left behind entirely. Both now import,
keyset-paged like the rest, idempotent, and reported as `imported of available`.

Both were worth carrying for the same reason: nothing recreates either. A Forge
link is a decision about which Forge site a monitor covers, not an observation.
A DNS row records what DNS said on a particular day — and contrary to the
assumption in the report, the hub does not re-derive lookups. It has the table,
the model and the service, and nothing that writes to them; the on-demand button
that produced them in the package this was extracted from has not been rebuilt
here. Skipping the import would have lost the only copy.

DNS rows key on `(monitor_id, domain, looked_up_at)` rather than
`(monitor_id, domain)`, which would have collapsed every snapshot a domain ever
had into its most recent one.

A table the source does not have is now reported as absent and skipped rather
than failing the import, so a source that never used Forge imports the rest.

`--chunk` sets the page size.

## v0.1.3

**Fixed: a paused monitor still alerted.**

`uptime_check_enabled` was honoured only by the prober, which drops disabled
monitors when it plans work — but it plans from the last manifest it pulled, so
it keeps delivering for anything paused since. `recordUptimeResult()` consulted
nothing, so those results transitioned status, opened incidents and sent
notifications. Found on a live install: 31 monitors imported, paused
immediately, and their owner paged for the next half hour.

The result is now recorded and nothing is concluded from it. The guard is at the
seam, because local checks and delivered ones both pass through there and a
guard anywhere else would cover one and not the other.

**Fixed: `monitoring:import:legacy` could silently skip rows.**

It paged with an offset ordered by a column that ties heavily — an hourly
`bucket_start` repeats once per monitor — and OFFSET has no defined order
between pages when the sort key ties, so rows could land on both pages or on
neither. Duplicates were already absorbed; skips were silent, because the
summary reported what it had iterated rather than what the source held.

Now keyset-paginated on `(sort column, id)`, and each pass reports the source
count beside the imported one and warns when they differ. A regression test
covers a tie spanning three page boundaries: the old paging imported 20 of 31
rows.

`--chunk` sets the page size, for a small box or to exercise the paging.

## v0.1.2

**Fixed: responses to the prober were not signed.**

Every endpoint is signed in both directions, and only the inbound half was.
`GET /monitoring/manifest` returned a valid manifest with none of
`X-Monitoring-Schema`, `X-Monitoring-Timestamp` or `X-Monitoring-Signature`, so
a prober that verifies what it is given — as it must, since a manifest decides
what it will request for the next hour — refused it and kept the manifest it
already had.

Signing now happens in the middleware that verifies inbound, so it covers the
manifest and all three delivery responses rather than one endpoint at a time,
and it signs the bytes as returned. Refusals stay unsigned: there is nothing to
vouch for.

The signer itself was never wrong. It agreed with the conformance kit's vectors
the whole time, and nothing asserted that an endpoint ever called it — so the
new tests assert on what the endpoints return rather than on the signer, which
is the only way this class of bug is caught.

## v0.1.1

**Fixed: the screens ignored `monitoring.middleware`.**

`config('monitoring.middleware')` was documented and never read. The screens
were registered with route model binding alone — no auth, no session, no CSRF —
so a guest reaching `/monitoring` got a 500 from inside a query scoped to an
owner that could not be resolved, rather than a redirect to sign in. Reported
from a real install.

The mute link moved to `routes/mute.php` and a group of its own. Route-level
middleware adds to a group's rather than replacing it, so leaving it beside the
screens would have given it the auth stack it exists to avoid: someone woken at
3am should not have to log in to make the phone stop.

Route model binding is now appended to whatever the host lists rather than
assumed to be in it. The prober API, the deployment links and the token
endpoints are unchanged and still ignore this setting on purpose.

**Changed: the default middleware is `['web', 'auth']`.**

It was `['web', 'auth:sanctum', 'verified']`, which assumed a guard many
applications do not have and a user model implementing `MustVerifyEmail`. On an
application with neither — Passport, `web` and `api` guards only — that default
would have thrown. Tighten it to whatever the application actually uses;
`docs/installation.md` now says so.

## v0.1.0

First release. Sites, monitors, heartbeats, incidents, deployment windows,
exception reports, notification preferences, seven screens, the import tooling,
and a prober-facing API bound to the conformance kit.
