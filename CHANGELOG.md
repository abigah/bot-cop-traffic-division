# Changelog

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
