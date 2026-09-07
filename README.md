# abigah/bot-cop-traffic-division

The monitoring hub for Bot Cop Traffic Division. An extranet installs this
package and gets sites, monitors, heartbeats, check history, incidents,
deployment windows, exception reports, per-recipient notification preferences
and a dashboard — fed either by its own scheduler or by a prober running outside
its infrastructure.

> Status: feature complete and not yet installed anywhere. See
> [docs/installation.md](docs/installation.md).

## The shape of it

A **site** is the unit of incidents, deployment windows and heartbeat
dependency. It has many **monitors** (a URL with its own method, headers,
strings and interval, one or more of them marked `critical`) and many
**heartbeats** (jobs and events that ping in rather than being checked from
outside). "The site is down" means a critical monitor is down.

Uptime results reach the package through one method, `recordUptimeResult()`, and
everything downstream of it is the same whichever mode is running:

- **`local`** — this application checks its own monitors on the scheduler.
- **`remote`** — a [prober](https://github.com/abigah/bot-cop-traffic-prober)
  performs the checks from outside and delivers raw results here. It applies no
  alerting rules of its own; this application replays what it is sent and
  reaches its own conclusions with its own thresholds, so the alerting rules
  live in exactly one place.

Certificate, domain-expiry and DNS checks stay here in both modes.

## The contracts

The prober and this package share nothing but
[`bot-cop-traffic-contracts`](https://github.com/abigah/bot-cop-traffic-contracts):
JSON Schemas, example payloads, HMAC vectors and rule fixtures, pinned here as a
submodule at `tests/contracts` and consumed by the test suite. A second prober,
in another language on another platform, is another thing that passes the same
kit.

## Development

```sh
composer contracts:init   # after a fresh clone
composer install
composer check            # lint and tests
```

## Installing it

A private repository, not on Packagist. Add the VCS entry and require a tagged
version — see [docs/installation.md](docs/installation.md):

```sh
composer require abigah/bot-cop-traffic-division:^0.1
```

It goes public and onto Packagist once the contracts have stopped moving.
