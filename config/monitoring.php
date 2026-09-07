<?php

use Abigah\BotCopTrafficDivision\Models\MonitoredSite;
use App\Models\User;

return [

    /*
    |--------------------------------------------------------------------------
    | Owner model
    |--------------------------------------------------------------------------
    |
    | The model a site belongs to via its `owner_id`, and what the dashboard and
    | site lists are scoped to. It defaults to the User model so that a
    | single-user install works untouched, but ownership usually belongs to a
    | tenant of some kind — a Team, Client, Project or Organisation — so point
    | this at that model instead and return an instance of it from
    | Monitoring::resolveCurrentOwnerUsing().
    |
    */

    'owner_model' => User::class,

    /*
    |--------------------------------------------------------------------------
    | Site model
    |--------------------------------------------------------------------------
    |
    | A site is the unit of incidents, deployment windows and heartbeat
    | dependency: it has many monitors and many heartbeats, and "the site is
    | down" means one of its critical monitors is down.
    |
    | Like the owner, the host supplies it. An application with its own site
    | concept points this at that model, adds the IsMonitoredSite trait to it,
    | and wires resolveSitesUsing() / resolveOwnerForSiteUsing(). An application
    | with no site concept leaves this alone and gets the package's own model on
    | the `monitor_sites` table, so a single-model install still works.
    |
    */

    'site_model' => MonitoredSite::class,

    /*
    |--------------------------------------------------------------------------
    | Notifiable model
    |--------------------------------------------------------------------------
    |
    | The model that receives notifications and can mute incidents. Backs the
    | `monitor_incident_notification_mutes` pivot and is the type yielded by
    | Monitoring::resolveRecipientsUsing().
    |
    */

    'notifiable_model' => User::class,

    /*
    |--------------------------------------------------------------------------
    | Checker
    |--------------------------------------------------------------------------
    |
    | Where uptime checks come from.
    |
    | `local`  — this application performs its own checks on the scheduler, the
    |            way the package has always worked. Nothing external is needed.
    | `remote` — a prober outside this infrastructure performs them and delivers
    |            results to the API routes below. Monitoring::schedule() stops
    |            registering the every-minute uptime job; everything downstream
    |            of recordUptimeResult() is unchanged, because a delivered
    |            result is replayed through exactly the same method a local
    |            check would have called.
    |
    | Certificate, domain-expiry and DNS checks stay here in both modes.
    |
    */

    'checker' => env('MONITORING_CHECKER', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Tenant slug
    |--------------------------------------------------------------------------
    |
    | What a prober calls this application. It travels in the manifest and has
    | to match the key this application has in the prober's own tenant config —
    | adding a tenant there is a deploy, not a registration flow, which is what
    | stops a client turning on faster checking from their own dashboard.
    |
    */

    'tenant' => env('MONITORING_TENANT', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Probers
    |--------------------------------------------------------------------------
    |
    | The probers allowed to talk to this application, keyed by prober id. Each
    | holds the shared secret used to sign both directions of the conversation
    | and the base URL this application pushes change notices and pings to.
    |
    | `secrets` is a list, current first: a verifier accepts any of them, which
    | is what lets a secret rotate without a gap. `location` is a label the
    | prober declares and this application only ever stores and displays —
    | nothing infers anything from it.
    |
    | One entry today. The contracts carry a prober id and location on every
    | payload so a second one, in another network, needs no schema change.
    |
    */

    'probers' => [

        'cf-prober-1' => [
            'base_url' => env('MONITORING_PROBER_URL'),
            'secrets' => array_values(array_filter([
                env('MONITORING_PROBER_SECRET'),
                env('MONITORING_PROBER_SECRET_PREVIOUS'),
            ])),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Schema version
    |--------------------------------------------------------------------------
    |
    | The contract version this application speaks, sent and expected in the
    | X-Monitoring-Schema header and carried inside every signed body. A
    | request announcing a version this application does not implement is
    | refused rather than guessed at.
    |
    */

    'schema_version' => 1,

    /*
    |--------------------------------------------------------------------------
    | Prober silence
    |--------------------------------------------------------------------------
    |
    | How long a configured prober may go without saying anything before it is
    | shown as silent. A prober reconciles on its own schedule whether or not
    | anything is due, so silence is not "nothing to report" — it is a prober
    | that has stopped, or one that can no longer reach here.
    |
    | This matters most with two of them: one going quiet while the other keeps
    | delivering looks exactly like everything being fine.
    |
    */

    'prober_silence_after_minutes' => 90,

    /*
    |--------------------------------------------------------------------------
    | Signature tolerance
    |--------------------------------------------------------------------------
    |
    | How far a signed request's timestamp may sit from this application's own
    | clock, in seconds, in either direction. This is what stops a captured
    | request being replayed tomorrow.
    |
    */

    'signature_tolerance_seconds' => 300,

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | When `register_routes` is true the package registers its own routes under
    | the given prefix and middleware. Set it to false to declare them yourself.
    |
    | `middleware` applies to the screens only. Route model binding is appended
    | to whatever is listed here, so a stack that omits it still resolves
    | {site} and {monitor}.
    |
    | Three sets of routes deliberately ignore it, because they are reached by
    | something that has no session: the prober's API authenticates by HMAC, the
    | deployment and mute links by a signature in the URL, and the ping and
    | report endpoints by a token in the path. Putting the web stack in front of
    | any of them would break them — CSRF on a prober's POST, or a login
    | redirect in front of a link someone clicked at 3am to stop being paged.
    |
    | The default is deliberately plain. `auth:sanctum` assumes a guard many
    | applications do not have, and `verified` assumes the user model
    | implements MustVerifyEmail; either would fail at boot on an application
    | that does neither. Tighten it to whatever this one actually uses.
    |
    */

    'register_routes' => true,

    'route_prefix' => '',

    'middleware' => ['web', 'auth'],

    'api_route_prefix' => 'monitoring',

    /*
     | Throttle for the token-authenticated ping and report endpoints, as
     | "attempts,minutes". These are unsigned by necessity — they are called by
     | arbitrary site code — so the rate limit is the backstop against a runaway
     | loop reporting the same error a thousand times a second.
     */
    'ingest_rate_limit' => '120,1',

    /*
    |--------------------------------------------------------------------------
    | Reporting periods
    |--------------------------------------------------------------------------
    |
    | How far back each period on the dashboard, history and stats screens
    | reaches. The keys are the values of Support\Period, which falls back to
    | its own defaults for anything missing here, so an application holding an
    | older published config still reports correctly.
    |
    */

    'period_hours' => [
        '1h' => 1,
        '24h' => 24,
        '7d' => 24 * 7,
        '30d' => 24 * 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Uptime checking
    |--------------------------------------------------------------------------
    */

    'uptime' => [

        // The uptime check fails if a site does not respond within this many seconds.
        'timeout_seconds' => 10,

        // How many monitors to check concurrently. Local mode only.
        'concurrent_checks' => 10,

        // Time between connection retries within a single check. Local mode only.
        'retry_after_milliseconds' => 100,

        // User agent used when reaching out to sites. Local mode only.
        'user_agent' => 'abigah/bot-cop-traffic-division uptime checker',

        // Extra headers sent with every uptime check. Local mode only.
        'additional_headers' => [],

        // Extra Guzzle options merged into every request. Local mode only.
        'guzzle_options' => [],

        // Fire the uptime-failed event only after this many consecutive failures.
        'fire_failed_event_after_consecutive_failures' => 2,

        // While a monitor stays down, re-fire the failed event every N minutes (0 disables).
        'resend_failed_notification_every_minutes' => 60,

        /*
         | The shortest interval a monitor may be given, mirrored from the
         | prober's per-tenant minimum so this application's forms do not offer
         | intervals it will clamp. The prober is authoritative: it clamps
         | anyway and reports the clamp back in the next delivery.
         */
        'minimum_interval_minutes' => 5,

        /*
         | Monitors on a site that hibernates are floored here instead, because
         | every check wakes the app. Heartbeats are what cover the gap.
         */
        'hibernating_interval_minutes' => 60,

    ],

    /*
    |--------------------------------------------------------------------------
    | Heartbeats
    |--------------------------------------------------------------------------
    |
    | Jobs and events ping in rather than being checked from outside. A
    | heartbeat is overdue when nothing has pinged within its interval plus
    | grace; an event has timed out when a `start` has no `finish` inside its
    | timeout. The prober judges both and this application receives verdicts —
    | except in local mode, where it judges them itself on the same rules.
    |
    */

    'heartbeats' => [

        'default_grace_minutes' => 10,

        'default_timeout_minutes' => 30,

    ],

    /*
    |--------------------------------------------------------------------------
    | Exception reporting
    |--------------------------------------------------------------------------
    |
    | Sites report 500-class errors, fingerprinted on class, file and line. The
    | first sighting of a fingerprint notifies; repeats are counted and
    | digested. This is not an error tracker — no traces explorer, no releases.
    |
    */

    'exceptions' => [

        // Truncate reported messages to this many characters before storing.
        'max_message_length' => 1000,

        // Store the trimmed trace when the reporter sends one.
        'store_traces' => true,

        /*
         | A fingerprint that has not been seen for this many days is forgotten,
         | so a recurrence after a fix counts as new. A deploy finishing and an
         | explicit resolve both reset it immediately.
         */
        'forget_after_days' => 30,

    ],

    /*
    |--------------------------------------------------------------------------
    | Certificate checking
    |--------------------------------------------------------------------------
    */

    'certificate' => [

        // Fire the expiring-soon event when a certificate expires within this many days.
        'expiring_soon_days' => 10,

    ],

    /*
    |--------------------------------------------------------------------------
    | Deployments
    |--------------------------------------------------------------------------
    |
    | Signal a deployment window per site via its signed start/finish URLs (see
    | the IsMonitoredSite trait). While a deployment is underway, uptime alerting
    | and incident creation are suppressed for every monitor on the site, so a
    | restart-induced blip does not page anyone. As a safety net, an unfinished
    | deployment stops suppressing after `max_window_minutes`.
    |
    */

    'deployment' => [

        // A deployment with no "finish" signal stops suppressing alerts after this many minutes.
        'max_window_minutes' => 30,

    ],

    /*
    |--------------------------------------------------------------------------
    | Forge integration
    |--------------------------------------------------------------------------
    |
    | An implementation of Contracts\ForgeSiteProvider, resolved from the
    | container. Set it and each monitor's history screen gains a panel for
    | linking the monitor to Laravel Forge sites and creating monitors for their
    | domains. Leave it null and the panel is not rendered.
    |
    */

    'forge_provider' => null,

];
