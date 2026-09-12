<div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <flux:heading size="xl">Monitoring</flux:heading>

        <flux:select wire:model.live="period" size="sm" class="w-32">
            <flux:select.option value="1h">Last hour</flux:select.option>
            <flux:select.option value="24h">24 hours</flux:select.option>
            <flux:select.option value="7d">7 days</flux:select.option>
            <flux:select.option value="30d">30 days</flux:select.option>
        </flux:select>
    </div>

    <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
        <flux:card class="space-y-1 text-center">
            <flux:text variant="subtle" class="text-xs">Sites down</flux:text>
            <flux:heading size="xl">
                <span class="{{ $summary['sites_down'] > 0 ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400' }}">
                    {{ $summary['sites_down'] }}
                </span>
            </flux:heading>
            <flux:text variant="subtle" class="text-xs">of {{ $summary['sites'] }}</flux:text>
        </flux:card>

        <flux:card class="space-y-1 text-center">
            <flux:text variant="subtle" class="text-xs">Uptime</flux:text>
            <flux:heading size="xl">
                <span class="{{ $summary['uptime'] >= 99.9 ? 'text-green-600 dark:text-green-400' : ($summary['uptime'] >= 95 ? 'text-amber-600 dark:text-amber-400' : 'text-red-600 dark:text-red-400') }}">
                    {{ $summary['uptime'] }}%
                </span>
            </flux:heading>
            <flux:text variant="subtle" class="text-xs">{{ $summary['up'] }} up / {{ $summary['down'] }} down</flux:text>
        </flux:card>

        <flux:card class="space-y-1 text-center">
            <flux:text variant="subtle" class="text-xs">Avg response</flux:text>
            <flux:heading size="xl">{{ $stats['avg_ms'] ? $stats['avg_ms'].'ms' : '--' }}</flux:heading>
            <flux:text variant="subtle" class="text-xs">{{ $stats['total'] }} checks</flux:text>
        </flux:card>

        <flux:card class="space-y-1 text-center">
            <flux:text variant="subtle" class="text-xs">Open incidents</flux:text>
            <flux:heading size="xl">{{ $summary['ongoing_incidents'] }}</flux:heading>
            <flux:text variant="subtle" class="text-xs">{{ $summary['paused'] }} monitors paused</flux:text>
        </flux:card>
    </div>

    {{-- The three questions this screen exists to answer, in the order they
         matter: is anything down, has anything stopped running, is anything
         throwing errors it did not throw yesterday. --}}
    <div class="grid gap-4 lg:grid-cols-3">
        <flux:card class="space-y-3">
            <flux:heading size="lg">Down now</flux:heading>

            @forelse ($ongoing as $incident)
                <div class="border-b border-zinc-100 py-2 last:border-0 dark:border-zinc-800">
                    <flux:link href="{{ route('monitoring.monitor.history', ['monitor' => $incident->monitor_id]) }}">
                        {{ $incident->monitor?->url }}
                    </flux:link>
                    <flux:text variant="subtle" class="text-xs">
                        {{ $incident->started_at->diffForHumans() }} · {{ $incident->failure_reason }}
                    </flux:text>
                </div>
            @empty
                <flux:text variant="subtle">Everything is responding.</flux:text>
            @endforelse
        </flux:card>

        <flux:card class="space-y-3">
            <flux:heading size="lg">Stopped running</flux:heading>

            @forelse ($missedJobs as $heartbeat)
                <div class="border-b border-zinc-100 py-2 last:border-0 dark:border-zinc-800">
                    <flux:link href="{{ route('monitoring.site', ['site' => $heartbeat->site_id]) }}">
                        {{ $heartbeat->name }}
                    </flux:link>
                    <flux:text variant="subtle" class="text-xs">
                        {{ Str::headline($heartbeat->status->value) }} ·
                        last seen {{ $heartbeat->last_ping_at?->diffForHumans() ?? 'never' }}
                    </flux:text>
                </div>
            @empty
                <flux:text variant="subtle">Every declared job is running.</flux:text>
            @endforelse
        </flux:card>

        <flux:card class="space-y-3">
            <flux:heading size="lg">New errors</flux:heading>

            @forelse ($newErrors as $error)
                <div class="border-b border-zinc-100 py-2 last:border-0 dark:border-zinc-800">
                    <flux:link href="{{ route('monitoring.site', ['site' => $error->site_id]) }}">
                        {{ class_basename(str_replace('\\', '/', $error->exception_class)) }}
                    </flux:link>
                    <flux:text variant="subtle" class="truncate text-xs">{{ $error->message }}</flux:text>
                    <flux:text variant="subtle" class="text-xs">
                        {{ $error->occurrences }} {{ Str::plural('time', $error->occurrences) }}
                    </flux:text>
                </div>
            @empty
                <flux:text variant="subtle">Nothing new in the last day.</flux:text>
            @endforelse
        </flux:card>
    </div>

    <flux:card class="space-y-4">
        <flux:heading size="lg">Response time</flux:heading>

        @if (count($chart['labels']) === 0)
            <flux:text variant="subtle">No checks in this period yet.</flux:text>
        @else
            @php($slot = 1000 / max(1, count($chart['response_times'])))

            {{-- Deliberately plain: a sparkline the host can restyle, rather
                 than a charting library this package would have to pin.

                 Drawn here rather than by Alpine. An x-for has to sit on a
                 <template>, and inside an <svg> the parser makes that an SVG
                 element with no content to clone, so the loop throws and not a
                 single bar appears. --}}
            <div wire:key="chart-{{ $period }}" class="h-64">
                <svg viewBox="0 0 1000 240" preserveAspectRatio="none" class="h-full w-full">
                    @foreach ($chart['response_times'] as $index => $value)
                        @php($height = $value === null ? 0 : min(240, $value / 10))
                        <rect
                            x="{{ $index * $slot }}"
                            y="{{ 240 - $height }}"
                            width="{{ max(1, $slot - 1) }}"
                            height="{{ $height }}"
                            class="{{ ($chart['statuses'][$index] ?? null) === 'down' ? 'fill-red-500' : 'fill-sky-500' }}"
                        />
                    @endforeach
                </svg>
            </div>

            <div class="flex justify-between">
                <flux:text variant="subtle" class="text-xs">{{ $chart['labels'][0] ?? '' }}</flux:text>
                <flux:text variant="subtle" class="text-xs">{{ last($chart['labels']) }}</flux:text>
            </div>
        @endif
    </flux:card>
</div>
