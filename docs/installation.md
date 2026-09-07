# Installing the hub

This package is what an extranet installs to own its own monitoring: sites,
monitors, heartbeats, check history, incidents, deployment windows, exception
reports, notification preferences and the prober-facing API.

It runs in one of two modes and everything except who performs the HTTP checks
is identical in both:

- **`local`** — this application checks its own monitors on its scheduler.
  Self-contained. Nothing external is needed.
- **`remote`** — a prober outside this infrastructure performs the checks and
  delivers raw results here. This application still owns every alerting
  decision.

**Install in `local` mode first.** It proves the schema, the data and the
alerting on their own, and switching to `remote` later is a config change plus a
shared secret. Nothing about the data changes.

## 1. Require the package

It is a private repository, not on Packagist, so Composer needs to be told where
it is:

```jsonc
// composer.json
"repositories": [
    { "name": "bot-cop-traffic-division", "type": "vcs", "url": "https://github.com/abigah/bot-cop-traffic-division" }
]
```

```sh
composer require abigah/bot-cop-traffic-division:^0.1
```

**Authentication.** Locally, a `gh` login with the `repo` scope is enough —
`gh auth setup-git` lets Composer clone it, and an SSH key with access does the
same. A build server has neither, so it needs one of:

- `COMPOSER_AUTH` as an environment variable holding a `github-oauth` entry, or
- a deploy key on the repository.

Prefer a fine-grained token with read-only contents access to this repository
alone. A personal token's `repo` scope grants far more than a build needs, and
build environments are not where broad credentials should live.

**Developing the package and the application together** is the one case for a
path repository instead, because edits land without a `composer update`:

```jsonc
{ "type": "path", "url": "../bot-cop-traffic-division", "options": { "symlink": true } }
```

Use it while both are moving; switch to the VCS entry before anything deploys,
or the build will look for a directory that is not there.

It requires Livewire 4 and the free Flux, both on Packagist. No licence, no
private Composer repository and no `auth.json` — the screens deliberately use no
Flux Pro component, so installing this package costs nothing beyond the package
itself.

```sh
php artisan vendor:publish --tag=monitoring-config
```

## 2. Decide the three models

The package asks the host for three things and assumes nothing about any of
them.

| Config | What it is | Default |
|---|---|---|
| `owner_model` | What a site belongs to, and what the dashboard is scoped to. A Team, Client, Project or Organisation. | `App\Models\User` |
| `site_model` | The unit of incidents, deployment windows and heartbeat dependency. | the package's `MonitoredSite` |
| `notifiable_model` | Who receives notifications and can mute an outage. | `App\Models\User` |

**A site is the important one.** It has many monitors and many heartbeats, and
"the site is down" means *a critical monitor is down*. An application with its
own site concept points `site_model` at that model and adds the
`IsMonitoredSite` trait to it. An application with none leaves the default
alone and gets `monitor_sites`, which works untouched.

Nothing the package needs is ever added to a host's table. Per-site state it
owns — whether the site hibernates, and the token its code pings with — lives in
`monitor_site_settings`, keyed by site id.

## 3. Wire the resolvers

In a service provider's `boot()`. Only the first three are required.

```php
use Abigah\BotCopTrafficDivision\Facades\Monitoring;

// Required.
Monitoring::resolveCurrentOwnerUsing(fn () => auth()->user()->currentTeam);
Monitoring::resolveRecipientsUsing(fn ($owner, $subject) => $owner->users);
// Anything returning an array of channel names: mail, database, vonage. This
// hands it to the package's own per-recipient preferences, which is what the
// preferences screen writes; a host wanting a rota, an escalation policy or
// quiet hours writes its own and never calls this.
Monitoring::resolveChannelsUsing(
    fn ($recipient, $subject, string $event) => Monitoring::preferenceChannelsFor($recipient, $subject, $event)
);

// Required when site_model is the host's own.
Monitoring::resolveSitesUsing(fn ($owner) => $owner->sites);
Monitoring::resolveOwnerForSiteUsing(fn ($site) => $site->team);

// Required before `monitoring:sites:backfill` or `monitoring:import:legacy`:
// which of the host's sites a monitor belongs to. Return null to leave one
// alone — the command reports it rather than guessing.
Monitoring::resolveSiteForMonitorUsing(fn ($monitor) => Site::firstWhere('domain', $monitor->host()));

// Optional: where a notification should send someone to look. With none, a
// notification simply carries no link rather than failing to render.
Monitoring::resolveUrlUsing(fn ($subject) => route('sites.show', $subject->site_id ?? $subject));
```

## 4. Configure and migrate

```php
// config/monitoring.php
'checker' => env('MONITORING_CHECKER', 'local'),
'tenant' => env('MONITORING_TENANT', 'your-slug'),
'owner_model' => \App\Models\Team::class,
'site_model' => \App\Models\Site::class,
'notifiable_model' => \App\Models\User::class,
```

```sh
php artisan migrate
```

Sixteen tables, all `monitor_*`-prefixed so nothing collides with a host's own
`sites`.

## 5. Register the scheduled work

```php
// routes/console.php
use Abigah\BotCopTrafficDivision\Facades\Monitoring;

Monitoring::schedule();
```

In `local` mode that registers uptime checking and the heartbeat sweep every
minute, certificates daily, domain expiry weekly, and the check rollup hourly.
In `remote` mode the first two are dropped — a prober is doing them — and
everything else stays, because certificate, domain-expiry and DNS checking never
leaves this side.

## 6. Bring existing monitoring across

Two commands, both safe to run twice, both with `--dry-run`.

```sh
# Attach monitors that have no site, grouped by host.
php artisan monitoring:sites:backfill --dry-run

# Import from an installation that predates this package. Reads only.
php artisan monitoring:import:legacy --connection=legacy --owner=3 --dry-run
```

`monitoring:import:legacy` reads the table layout this package was extracted
from — one `monitors` table carrying the uptime, certificate and domain columns,
with checks, aggregates, incidents and preferences hanging off it. It never
writes to the source.

| Option | Meaning |
|---|---|
| `--connection` | The connection holding the old tables. Configure it in `config/database.php`; it is only ever read. |
| `--owner` | Only import monitors with these legacy `owner_id` values. Repeatable. |
| `--as-owner` | Write this `owner_id` instead of the legacy one. |
| `--checks-days` | How much raw check history to bring. Default 30; the aggregates carry the rest. |
| `--dry-run` | Report what would happen and write nothing. |

Everything imported arrives **critical**, because the old model had no such flag
and every monitor was equally load-bearing. Narrowing what "the site is down"
means is a decision to make deliberately, monitor by monitor, not one to inherit
from a default.

## 7. Verify

```sh
php artisan monitoring:heartbeats:sweep      # should report 0 overdue on a fresh install
php artisan tinker --execute="Abigah\BotCopTrafficDivision\Models\Monitor::first()->recordUptimeResult(
    Abigah\BotCopTrafficDivision\Support\CheckResult::down('Testing the alerting path')
);"
```

Two consecutive failures is the default threshold, so run that twice to see an
incident open and a notification go out.

## The screens

Registered under `monitoring.route_prefix` and `monitoring.middleware`, so they
sit behind whatever authentication the host already uses. Set
`register_routes` to false to declare them yourself.

| Route | What it answers |
|---|---|
| `/monitoring` | Is anything down, has anything stopped running, is anything throwing errors it did not throw yesterday. |
| `/monitoring/sites` | Every site, worst first. Select several to add a monitor to each or change what counts as critical. |
| `/monitoring/sites/{site}` | One site: its monitors, its scheduled work, its errors, its incidents — and where all of it is edited. |
| `/monitoring/monitors/{monitor}` | One monitor in detail: recent checks, where each came from, certificate and domain, and the Forge panel. |
| `/monitoring/incidents` | Outages past and present, and dismissing one with a reason. |
| `/monitoring/preferences` | What the signed-in recipient hears about, and how. |
| `/monitoring/probers` | Who is doing the checking, and whether they are all still doing it. |

There is also a banner for the host's own layout, which renders nothing at all
when everything is up:

```blade
<livewire:monitoring-incident-banner />
```

Every screen reads through the owner resolved by `resolveCurrentOwnerUsing()`,
and an id in a URL cannot reach another owner's records.

## SMS

Mail and in-app notifications work as soon as the package is installed. SMS
needs two things, and the failure mode if either is missing is silence rather
than an error — so it is worth reading before assuming it works.

**Laravel needs to know where to send.** The Vonage channel's first act is to ask
the notifiable, and a notifiable that does not answer is skipped without a word:

```php
// The channel, verbatim.
if (! $to = $notifiable->routeNotificationFor('vonage', $notification)) {
    return;
}
```

Laravel's default routing handles `mail` and `database` and returns null for
everything else, so this method is not optional:

```php
// On the notifiable model.
public function routeNotificationForVonage(Notification $notification): ?string
{
    return $this->phone;
}
```

Where the number is stored is deliberately yours — a column, a JSON key, a
per-team on-call number, whatever your application already does. Laravel asks
only that it comes back from that one method.

**This package needs to know whether to offer it.** Channels are decided before
a notification is built, so without a way to tell, a recipient with no number is
offered SMS on the preferences screen, and every alert queues a job that builds a
message the channel then discards. `HasNotificationChannels::notifiableCanReceiveSms()`
is what prevents that, and by default it asks whether `$notifiable->phone` has
anything in it.

So:

- **Storing the number as `phone` on the notifiable** needs the routing method
  and nothing else. The default eligibility check already agrees with it.
- **Storing it anywhere else** needs the routing method *and* an override of
  `notifiableCanReceiveSms()`. Keep the two answering the same question, or the
  preferences screen and the actual send will disagree about who can be reached.

Credentials come from `laravel/vonage-notification-channel`, which ships its own
config and reads `VONAGE_KEY`, `VONAGE_SECRET` and `VONAGE_SMS_FROM` from the
environment. Nothing needs adding to `config/services.php`.

Finally, SMS is off per recipient until somebody turns it on: `sms_enabled`
defaults to false on a preference row, and a recipient who has set nothing gets
mail and an in-app notification. Configuring all of the above enables the
possibility, not the behaviour.

## A second prober

The contracts carry a prober id and location on every payload, so a second one
needs no schema change and no migration — only a config entry and a secret:

```php
'probers' => [
    'cf-prober-1' => ['base_url' => '...', 'secrets' => [env('MONITORING_PROBER_SECRET')]],
    'ic-prober-2' => ['base_url' => '...', 'secrets' => [env('MONITORING_PROBER_2_SECRET')]],
],
```

Two probers can be arranged two ways, and the difference matters more than it
looks.

**Partitioned** — each prober has its own sites and they never check the same
URL. One prober per organisation, each on its own Cloudflare account, is the
ordinary reason. This is capacity and isolation, not redundancy: if a prober
stops, its sites stop being checked and nothing takes over.

**Overlapping** — two probers check the same URL from different places. This is
redundancy, and it is what the agreement rule is for: a failure only one prober
saw, while the other said up in the same window, is recorded with its location
tag and shown as degraded. It opens no incident and wakes nobody, because one
data centre losing its route to an origin is not the site being down.

Nothing has to be declared. The hub works out which shape it is in from what has
actually arrived, and `/monitoring/probers` says what a silent prober costs
accordingly — "nothing else is checking these" where they are partitioned, and
the disagreement counts only where they overlap.

A prober can go quiet without anything looking wrong, which is why that screen
exists at all: with a second prober delivering, the checks keep arriving and the
dashboard stays green. Silence is judged by `prober_silence_after_minutes`,
since a prober reconciles on its own schedule whether or not anything is due.

Worth being deliberate about what a second prober buys. Two deployments of the
same Worker on two Cloudflare accounts are two vantage points and two accounts —
real, and not the same as two vendors. A Cloudflare-wide problem still takes
both. The design's stronger claim is a Laravel prober beside the Worker one: two
vendors, two networks and two codebases passing the same conformance kit, which
is redundancy that a single bug cannot cancel.

## Deploy windows

A site's signed deployment URLs suppress alerting for every monitor on it while
a deploy is underway, and stop suppressing after `deployment.max_window_minutes`
so a missing "finish" cannot silence a site forever.

That safety net is quiet, though: a deploy that hangs looks like a deploy that
finished. Declare an event on the site and tick **"this is the deploy"**, and the
deployment URLs signal it — in `remote` mode by pinging every configured prober,
in `local` mode by applying it here — so a deploy that starts and never finishes
becomes a verdict rather than a window quietly lapsing.

## Later: switching to `remote`

1. Generate the shared secret:

   ```sh
   php artisan monitoring:prober:secret
   ```

   It writes `MONITORING_PROBER_SECRET` into `.env` and prints the `wrangler
   secret put` line to run on the prober with the same value. Both ends must
   hold the same bytes; there is nothing to register with and nobody to ask.

   Rotating later is `--rotate`, which keeps the current secret as
   `MONITORING_PROBER_SECRET_PREVIOUS`. Both sides accept any secret in their
   list, so the old one keeps verifying until the prober has the new one — which
   is what makes a rotation gapless rather than a brief outage.

   Use `--show` to print a value without touching `.env`, for a platform where
   the environment is managed elsewhere.

2. Add this application to the prober's tenant config with its `base_url`, and
   deploy the prober. Tenants are a config file, not a registration flow.
3. Set `MONITORING_PROBER_URL` to where that prober answers. It has no default
   and is required in `remote` mode — a package has no business shipping one
   deployment's address as a fallback, and a wrong-but-plausible default fails
   as a manifest that never loads rather than as an error.
4. Set `MONITORING_CHECKER=remote`.

Between steps 1 and 2 the two ends disagree, and every delivery fails its
signature check. That is the expected state in between, not a fault.

The prober pulls `GET /monitoring/manifest`, checks what it finds, and delivers
to `POST /monitoring/results`. This application replays those through the same
seam its own checks used, so incidents, thresholds and notifications behave
exactly as they did the day before.

Run both modes side by side for a while first and compare timelines. They are
answering the same questions — the heartbeat site rule on both sides is bound to
the same conformance fixture — so a difference is worth understanding before
`local` is switched off.

## Forge

Optional, and off unless asked for. Point `monitoring.forge_provider` at a class
implementing `Contracts\ForgeSiteProvider` and each monitor's history screen
grows a panel for linking it to Forge sites and watching their domains; leave it
null and nothing renders.

The package never talks to Forge itself. Where the credentials live and how they
are scoped to an owner is the host's business — three methods, and a
`ForgeAccessException` whose message is shown to the user as written, so a token
missing a scope reads as guidance rather than as a stack trace.

What it is for is the gap between deployed and monitored: a Forge site usually
answers on a primary domain and several aliases, and the aliases nobody
remembered to add are the ones that go down unnoticed. A monitor started from
that panel joins the same site, arrives non-critical — an alias with no history
should not, on its first check, declare the whole site down — and looks for the
first few characters of the page title, so the check proves the application
answered rather than that a server did.

## Endpoints

| Route | Auth | Who calls it |
|---|---|---|
| `GET /monitoring/manifest` | HMAC | the prober |
| `POST /monitoring/results` | HMAC | the prober |
| `POST /monitoring/heartbeats` | HMAC | the prober |
| `POST /monitoring/exceptions` | HMAC | the prober |
| `GET\|POST /monitoring/ping/{token}[/start\|/fail]` | token in path, throttled | a site's own jobs |
| `POST /monitoring/report/{token}` | token in path, throttled | a site's exception handler |
| `GET /deployments/site/{site}/start\|finish` | signed URL | a deploy script |
| `GET /incidents/{incident}/mute/{notifiable}` | signed URL | a notification recipient |

The last four are unsigned by necessity: they are called by arbitrary site code
or clicked from an email, and neither can hold a tenant secret. A ping token
grants nothing but the ability to say that one heartbeat fired.

In `remote` mode a prober owns the ping and report endpoints and the client
package points at it instead. They exist here so heartbeats and exception
reporting work on an extranet that has no prober yet.
