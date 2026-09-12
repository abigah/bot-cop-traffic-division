# Changelog

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
