<?php

namespace Abigah\BotCopTrafficDivision\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Whether a missed job is its own problem or part of an outage already underway.
 *
 * A missed heartbeat during an outage is the outage. Paging separately for it
 * turns one incident into a dozen alarms, and the one thing worse than being
 * told late is being told twelve times.
 *
 * This is a pure function bound to `fixtures/heartbeat-site-rule.json` in the
 * conformance kit, and the prober binds its own implementation to the same
 * cases. Whichever side is judging, the answer is the same one — which is what
 * lets an extranet run local and remote side by side and compare timelines.
 *
 * The shapes below are the fixture's, deliberately: it is the specification,
 * and a translation layer between it and this is somewhere for the two to drift
 * apart.
 */
class HeartbeatSiteRule
{
    /**
     * How long a site status stays evidence about now.
     *
     * Without a threshold the rule cannot terminate: "probe when the site was
     * last seen up" would probe forever, because the probe's own result is also
     * up. Two minutes is short enough to catch a site that fell over since the
     * last check and long enough not to re-probe one checked seconds ago.
     */
    public const SITE_STATUS_FRESH_SECONDS = 120;

    /**
     * @param  array{kind: string, state: string, last_ping_at?: string|null, interval_minutes?: int|null, grace_minutes?: int|null, timeout_minutes?: int|null}  $signal
     * @param  array{critical_status: string, critical_checked_at?: string|null, down_since?: string|null, recovered_at?: string|null}  $site
     * @return array{action: string, verdict_status?: string}
     */
    public function judge(array $signal, array $site, CarbonInterface $now, bool $extranetReachable = true): array
    {
        /*
         | Recovery is delivered whatever else is true, including during an
         | outage. It is not a page — it is what lets the hub close the
         | heartbeat's own state — so suppressing it would strand that state
         | until the next ping.
         */
        if ($signal['state'] === 'pinged_again') {
            return ['action' => 'deliver', 'verdict_status' => 'recovered'];
        }

        /*
         | A hub that cannot be reached at all is treated as a site that is
         | down, for every one of its heartbeats. Undeliverable results plus a
         | site actually down is what wakes the emergency contacts; a missed job
         | on its own is not.
         */
        if (! $extranetReachable) {
            return ['action' => 'suppress'];
        }

        if ($site['critical_status'] === 'down') {
            return ['action' => 'suppress'];
        }

        /*
         | An explicit failure needs no probe and no grace period. The site
         | plainly answered — it just told us something broke — and a job that
         | ran and failed is news whether or not the site came back a minute
         | ago.
         */
        if ($signal['state'] === 'explicit_fail') {
            return ['action' => 'deliver', 'verdict_status' => 'failed'];
        }

        /*
         | Last seen up is not good enough to page on: the likeliest reason a
         | job stopped pinging is that the whole site went down a moment ago. So
         | one extra request is spent finding out — only here, only in the case
         | that actually matters.
         */
        if ($this->statusIsStale($site, $now)) {
            return ['action' => 'probe-then-judge'];
        }

        /*
         | The site came back a moment ago and the queue has not caught up. A
         | verdict here pages for an outage that is already over. Wait one full
         | cycle; still missing after that is the web tier coming back without
         | the scheduler, which is a real verdict and the one this whole rule
         | exists to protect.
         */
        if ($this->withinRecoveryGrace($signal, $site, $now)) {
            return ['action' => 'hold'];
        }

        return match ($signal['state']) {
            'start_without_finish' => ['action' => 'deliver', 'verdict_status' => 'timed_out'],
            default => ['action' => 'deliver', 'verdict_status' => 'missing'],
        };
    }

    /** @param  array<string, mixed>  $site */
    protected function statusIsStale(array $site, CarbonInterface $now): bool
    {
        if ($site['critical_status'] === 'stale') {
            return true;
        }

        $checkedAt = $site['critical_checked_at'] ?? null;

        if ($checkedAt === null) {
            return true;
        }

        return Carbon::parse($checkedAt)->diffInSeconds($now, absolute: true) > self::SITE_STATUS_FRESH_SECONDS;
    }

    /**
     * @param  array<string, mixed>  $signal
     * @param  array<string, mixed>  $site
     */
    protected function withinRecoveryGrace(array $signal, array $site, CarbonInterface $now): bool
    {
        $recoveredAt = $site['recovered_at'] ?? null;

        if ($recoveredAt === null) {
            return false;
        }

        // One full cycle of whatever this signal is: interval plus grace for a
        // heartbeat, the timeout for an event.
        $cycleMinutes = ($signal['kind'] ?? 'heartbeat') === 'event'
            ? (int) ($signal['timeout_minutes'] ?? 0)
            : (int) ($signal['interval_minutes'] ?? 0) + (int) ($signal['grace_minutes'] ?? 0);

        return $now->lessThan(Carbon::parse($recoveredAt)->addMinutes($cycleMinutes));
    }
}
