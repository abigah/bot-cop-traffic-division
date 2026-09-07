<?php

namespace Abigah\BotCopTrafficDivision\Livewire;

use Abigah\BotCopTrafficDivision\Facades\Monitoring;
use Abigah\BotCopTrafficDivision\Models\MonitorCheck;
use Abigah\BotCopTrafficDivision\Models\MonitorProberStatus;
use Abigah\BotCopTrafficDivision\Models\MonitorProberUsage;
use Abigah\BotCopTrafficDivision\Services\MonitorQuery;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Who is doing the checking, and whether they are all still doing it.
 *
 * The list comes from configuration — adding a prober is a deploy, not a
 * registration flow — and everything beside each name is an observation. The
 * two are shown together on purpose: a prober that is configured and silent is
 * the failure this screen exists to make visible, and it is invisible
 * everywhere else, because the other prober's checks keep arriving and the
 * dashboard stays green.
 */
class Probers extends Component
{
    public function render(): View
    {
        $configured = collect(Monitoring::probers());
        $status = MonitorProberStatus::query()->get()->keyBy('prober_id');
        $month = now()->format('Y-m');

        $usage = MonitorProberUsage::query()
            ->where('month', $month)
            ->get()
            ->keyBy('prober_id');

        $monitorIds = MonitorQuery::forCurrentOwner()->monitorIds();

        /*
         | How many probers have checked each monitor. Probers may be
         | partitioned — one per organisation, each with its own sites — or
         | overlapping, several watching the same URL. Both are legitimate and
         | the hub is told neither, so it works it out from what has actually
         | arrived.
         |
         | It decides what a silent prober means. Where nothing else is
         | checking a monitor, silence means that monitor is unmonitored, which
         | is a different and worse thing than redundancy being down to one.
         */
        $coverage = MonitorCheck::query()
            ->whereIn('monitor_id', $monitorIds)
            ->whereNotNull('prober_id')
            ->where('checked_at', '>=', now()->subWeek())
            ->selectRaw('monitor_id, count(distinct prober_id) as probers')
            ->groupBy('monitor_id')
            ->pluck('probers', 'monitor_id');

        $overlapping = $coverage->contains(fn (int $count) => $count > 1);

        // A check one prober called down while another called up in the same
        // window. With one prober this is always zero; with two it is the
        // number that says how much they are disagreeing.
        $disagreements = MonitorCheck::query()
            ->whereIn('monitor_id', $monitorIds)
            ->where('disagreed', true)
            ->where('checked_at', '>=', now()->subWeek())
            ->selectRaw('prober_id, count(*) as total')
            ->groupBy('prober_id')
            ->pluck('total', 'prober_id');

        return view('monitoring::livewire.probers', [
            'probers' => $this->rows($configured, $status, $usage, $disagreements),
            'overlapping' => $overlapping,
            'soleCover' => $coverage->filter(fn (int $count) => $count === 1)->count(),
            'checksRemotely' => Monitoring::checksRemotely(),
            'month' => $month,

            // A prober that has delivered but is no longer configured. Its
            // history is still here and nothing it says will be accepted again.
            'unknown' => $status->keys()->diff($configured->keys())->values(),
        ]);
    }

    /** @return Collection<int, array<string, mixed>> */
    protected function rows(Collection $configured, Collection $status, Collection $usage, Collection $disagreements): Collection
    {
        return $configured
            ->map(function (array $prober, string $id) use ($status, $usage, $disagreements): array {
                $seen = $status->get($id);

                return [
                    'id' => $id,
                    'base_url' => $prober['base_url'] ?? null,
                    'location' => $seen?->location,
                    'has_secret' => Monitoring::secretsFor($id) !== [],
                    'status' => $seen,
                    'silent' => $seen === null || $seen->isSilent(),
                    'never_seen' => $seen === null,
                    'checks' => $usage->get($id)?->checks ?? 0,
                    'pings' => $usage->get($id)?->pings ?? 0,
                    'disagreements' => (int) ($disagreements[$id] ?? 0),
                ];
            })
            ->values();
    }
}
