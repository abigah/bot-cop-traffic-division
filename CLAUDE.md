# bot-cop-traffic-division

The hub for Bot Cop Traffic Division, an uptime and application monitoring
system. Extranets install this Laravel package and it owns everything the
prober deliberately refuses to know: sites, monitors, heartbeats, check history,
incidents, deployment windows, mutes, notification preferences, the dashboard,
and the prober-facing API.

## Read first

- `docs/installation.md` — installing it, both checker modes, the screens, and
  what SMS needs.
- `tests/contracts/README.md` and `tests/contracts/docs/` — the conformance kit:
  schemas, examples, must-reject cases, HMAC vectors and rule fixtures. It is
  the specification; the tests here consume it rather than restating it.


## The seam

Every uptime result — checked locally or delivered by a prober — enters through
`Monitor::recordUptimeResult()`. Everything downstream of it is this package's
own business and works identically either way: status transitions, the
consecutive-failure threshold, the resend interval, check history, hourly
aggregates, incident open/resolve, deployment suppression, mutes, recipients and
channels.

The prober performs checks and delivers raw results. **It never decides that a
site is down for alerting purposes**, and never knows about deployments, mutes,
preferences or incidents. If a rule about *whether to notify* is being written
into the prober, it is in the wrong repo.

## Conventions

- Namespace `Abigah\BotCopTrafficDivision`, config key `monitoring`, facade
  `Monitoring`. The vocabulary stays "monitoring" even though the package is
  named for the product family — the design doc and the reference package both
  read that way.
- PHP 8.3+, Laravel 12/13, Livewire 4 with Flux Pro, Pest with Testbench.
  `composer test`, `composer lint`, `composer check`.
- **All package tables are `monitor_*`-prefixed**, including `monitor_sites`, so
  they never collide with a host's own `sites` table.
- **Site is the unit** of incidents, deployment windows and heartbeat
  dependency. The host supplies the model through `monitoring.site_model` the
  same way it supplies `owner_model`; the package ships a default for hosts with
  no site concept. Code asks `Monitoring::sitesFor()` /
  `Monitoring::ownerForSite()`, never a hard-coded relation.
- "The site is down" means **a critical monitor is down**. Non-critical monitors
  open their own incidents but never suppress heartbeat alerting.
- Every inbound and outbound prober payload is versioned
  (`X-Monitoring-Schema`) and HMAC-signed per prober. Verify the raw body bytes
  *before* parsing them, compare in constant time, try every configured secret,
  and refuse an unknown schema version rather than guessing.
- Secrets come from env, never from committed files.
- The conformance kit in `tests/contracts` is the source of truth for the
  contracts; tests here consume it rather than restating it. It is a submodule
  pinned to a commit — fresh clone: `composer contracts:init`. Change a contract
  in `bot-cop-traffic-contracts` first, commit and push it there, then
  `composer contracts:update` and commit the moved pin here. **Never edit
  `tests/contracts/` in place.** The prober pins the same commit; both sides of
  a contract change move together.
- Never edit anything under `vendor/`.
