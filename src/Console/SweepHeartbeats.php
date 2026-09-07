<?php

namespace Abigah\BotCopTrafficDivision\Console;

use Abigah\BotCopTrafficDivision\Enums\HeartbeatKind;
use Abigah\BotCopTrafficDivision\Enums\HeartbeatStatus;
use Abigah\BotCopTrafficDivision\Events\HeartbeatMissed;
use Abigah\BotCopTrafficDivision\Facades\Monitoring;
use Abigah\BotCopTrafficDivision\Models\MonitorHeartbeat;
use Abigah\BotCopTrafficDivision\Services\MonitorChecker;
use Abigah\BotCopTrafficDivision\Support\HeartbeatSiteRule;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Judges work that should have pinged and did not.
 *
 * In `remote` mode a prober does this and delivers verdicts, because that is
 * where the last ping time lives. In `local` mode nothing else will, so this
 * does it — against the same rule, bound to the same fixture, so an extranet
 * can run both modes side by side and compare timelines without wondering
 * whether the two are answering different questions.
 */
class SweepHeartbeats extends Command
{
    protected $signature = 'monitoring:heartbeats:sweep';

    protected $description = 'Judge overdue heartbeats and unfinished events against the site rule';

    public function handle(HeartbeatSiteRule $rule, MonitorChecker $checker): int
    {
        $now = Carbon::now();
        $judged = 0;

        foreach ($this->due($now) as $heartbeat) {
            $site = Monitoring::siteModel()::find($heartbeat->site_id);

            if ($site === null) {
                continue;
            }

            $signal = $this->signalFor($heartbeat, $now);
            $decision = $rule->judge($signal, $site->siteRuleState(), $now);

            /*
             | The site was last seen up, but too long ago to be evidence. One
             | extra request settles it — and it is only ever spent here, on a
             | heartbeat that is already overdue, which is what keeps "probe
             | before you page" from meaning "probe everything".
             */
            if ($decision['action'] === 'probe-then-judge') {
                $checker->check($site->criticalMonitors()->where('uptime_check_enabled', true)->get());

                $decision = $rule->judge($signal, $site->fresh()->siteRuleState(), $now);
            }

            $this->act($heartbeat, $decision, $now);
            $judged++;
        }

        $this->info("Swept {$judged} overdue ".str('heartbeat')->plural($judged).'.');

        return self::SUCCESS;
    }

    /**
     * Heartbeats with something to answer for. Everything else is simply not
     * late yet, and asking about it would be work with no possible outcome.
     *
     * @return Collection<int, MonitorHeartbeat>
     */
    protected function due(Carbon $now): Collection
    {
        return MonitorHeartbeat::query()
            ->enabled()
            ->get()
            ->filter(fn (MonitorHeartbeat $heartbeat) => $heartbeat->isOverdue($now));
    }

    /** @return array<string, mixed> */
    protected function signalFor(MonitorHeartbeat $heartbeat, Carbon $now): array
    {
        return [
            'kind' => $heartbeat->kind->value,
            'state' => $heartbeat->kind === HeartbeatKind::EVENT ? 'start_without_finish' : 'overdue',
            'last_ping_at' => $heartbeat->last_ping_at?->toIso8601ZuluString(),
            'interval_minutes' => $heartbeat->interval_minutes,
            'grace_minutes' => $heartbeat->graceMinutes(),
            'timeout_minutes' => $heartbeat->timeoutMinutes(),
        ];
    }

    /** @param  array{action: string, verdict_status?: string}  $decision */
    protected function act(MonitorHeartbeat $heartbeat, array $decision, Carbon $now): void
    {
        match ($decision['action']) {
            // The outage is the story. Record when this heartbeat started being
            // swallowed by it, so the incident can show it as context.
            'suppress' => $heartbeat->suppressed_since === null
                ? $heartbeat->forceFill(['suppressed_since' => $now])->save()
                : null,

            'deliver' => $this->deliver($heartbeat, $decision['verdict_status'] ?? 'missing'),

            // 'hold' — the site came back a moment ago and the queue has not
            // caught up. Reach no conclusion; the next sweep will.
            default => null,
        };
    }

    protected function deliver(MonitorHeartbeat $heartbeat, string $status): void
    {
        $verdict = HeartbeatStatus::from($status);

        // A heartbeat stays missing for as long as it stays missing. Only the
        // transition into that state is news.
        $alreadyAlerting = $heartbeat->status->isAlerting();

        $heartbeat->forceFill(['status' => $verdict])->save();

        /*
         | Recorded either way — a heartbeat that is genuinely broken should
         | still be visible — but nobody is told while it is waiting for its
         | first ping since the token changed. Until one arrives, a missed ping
         | is this application's own doing rather than the job's.
         */
        if (! $alreadyAlerting && ! $heartbeat->awaitingFirstPingSinceRotation()) {
            event(new HeartbeatMissed($heartbeat, $verdict, $heartbeat->last_message));
        }
    }
}
