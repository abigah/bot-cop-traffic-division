<div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="space-y-1">
            <flux:breadcrumbs>
                <flux:breadcrumbs.item href="{{ route('monitoring.sites') }}">Sites</flux:breadcrumbs.item>
                @if ($site)
                    <flux:breadcrumbs.item href="{{ route('monitoring.site', ['site' => $site->getKey()]) }}">
                        {{ $site->name }}
                    </flux:breadcrumbs.item>
                @endif
                <flux:breadcrumbs.item>{{ $monitor->host() }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>

            <div class="flex flex-wrap items-center gap-2">
                <flux:heading size="xl">{{ $monitor->url }}</flux:heading>

                @if (! $monitor->uptime_check_enabled)
                    <x-monitoring::status-pill status="paused" />
                @elseif ($monitor->isDown())
                    <x-monitoring::status-pill status="down" />
                @else
                    <x-monitoring::status-pill status="up" />
                @endif

                @if ($monitor->critical)
                    <flux:badge size="sm" variant="subtle">Critical</flux:badge>
                @endif
            </div>
        </div>

        <div class="flex items-center gap-3">
            <flux:switch wire:model.live="rawChecks" label="Raw checks" />

            <flux:select wire:model.live="period" size="sm" class="w-32">
                <flux:select.option value="1h">Last hour</flux:select.option>
                <flux:select.option value="24h">24 hours</flux:select.option>
                <flux:select.option value="7d">7 days</flux:select.option>
                <flux:select.option value="30d">30 days</flux:select.option>
            </flux:select>
        </div>
    </div>

    @if ($deploying)
        <flux:callout variant="warning" icon="rocket-launch">
            <flux:callout.heading>Deployment underway</flux:callout.heading>
            <flux:callout.text>Failures are being recorded but not alerted on.</flux:callout.text>
        </flux:callout>
    @endif

    @if ($ongoingIncident)
        <flux:callout variant="danger" icon="exclamation-triangle">
            <flux:callout.heading>Down since {{ $ongoingIncident->started_at->diffForHumans() }}</flux:callout.heading>
            <flux:callout.text>{{ $monitor->uptime_check_failure_reason }}</flux:callout.text>
        </flux:callout>
    @endif

    @if ($monitor->served_from_cache_at)
        {{-- The check succeeded and proved nothing: a cached 200 never reached
             the origin. Worth its own callout because it looks like uptime. --}}
        <flux:callout variant="warning" icon="archive-box">
            <flux:callout.heading>A response came from a cache</flux:callout.heading>
            <flux:callout.text>
                Last seen {{ $monitor->served_from_cache_at->diffForHumans() }}. A cached response
                never reached the origin, so it says nothing about whether the app is running.
                Bypass the cache for this URL at the CDN, and send
                <code>Cache-Control: no-store</code> from the app.
            </flux:callout.text>
        </flux:callout>
    @endif

    @if ($gaps->isNotEmpty())
        <flux:callout variant="warning" icon="minus-circle">
            <flux:callout.heading>Some checks never arrived</flux:callout.heading>
            <flux:callout.text>
                @foreach ($gaps as $gap)
                    {{ $gap->dropped_count }} between {{ $gap->oldest_at->diffForHumans() }}
                    and {{ $gap->newest_at->diffForHumans() }}@if (! $loop->last); @endif
                @endforeach
                — the prober's buffer overflowed while it could not deliver. The checks were
                performed; what they found is gone.
            </flux:callout.text>
        </flux:callout>
    @endif

    <div class="grid grid-cols-2 gap-4 md:grid-cols-5">
        <flux:card class="space-y-1 text-center">
            <flux:text variant="subtle" class="text-xs">Uptime</flux:text>
            <flux:heading size="lg">{{ $stats['uptime'] }}%</flux:heading>
        </flux:card>
        <flux:card class="space-y-1 text-center">
            <flux:text variant="subtle" class="text-xs">Average</flux:text>
            <flux:heading size="lg">{{ $stats['avg_ms'] ? $stats['avg_ms'].'ms' : '--' }}</flux:heading>
        </flux:card>
        <flux:card class="space-y-1 text-center">
            <flux:text variant="subtle" class="text-xs">Fastest</flux:text>
            <flux:heading size="lg">{{ $stats['min_ms'] ? $stats['min_ms'].'ms' : '--' }}</flux:heading>
        </flux:card>
        <flux:card class="space-y-1 text-center">
            <flux:text variant="subtle" class="text-xs">Slowest</flux:text>
            <flux:heading size="lg">{{ $stats['max_ms'] ? $stats['max_ms'].'ms' : '--' }}</flux:heading>
        </flux:card>
        <flux:card class="space-y-1 text-center">
            <flux:text variant="subtle" class="text-xs">Incidents</flux:text>
            <flux:heading size="lg">{{ $stats['incidents'] ?? 0 }}</flux:heading>
        </flux:card>
    </div>

    <flux:card class="space-y-4">
        <flux:heading size="lg">Recent checks</flux:heading>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>When</flux:table.column>
                <flux:table.column>Status</flux:table.column>
                <flux:table.column>Response</flux:table.column>
                <flux:table.column>From</flux:table.column>
                <flux:table.column>Detail</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($checks as $check)
                    <flux:table.row>
                        <flux:table.cell class="whitespace-nowrap">{{ $check->checked_at->diffForHumans() }}</flux:table.cell>
                        <flux:table.cell>
                            <x-monitoring::status-pill :status="$check->status" />
                            @if ($check->disagreed)
                                {{-- One location's failure that another prober
                                     contradicted. Kept, not believed. --}}
                                <flux:badge size="sm" color="zinc" title="Another prober said up in the same window">
                                    Not corroborated
                                </flux:badge>
                            @endif
                            @if ($check->served_from_cache)
                                <flux:badge size="sm" color="amber">Cached</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>{{ $check->response_time_ms ? $check->response_time_ms.'ms' : '--' }}</flux:table.cell>
                        <flux:table.cell class="text-zinc-500">
                            {{ $check->location ?? 'here' }}
                        </flux:table.cell>
                        <flux:table.cell class="max-w-md truncate text-zinc-500">
                            {{ $check->failure_reason ?? ($check->status_code ? 'HTTP '.$check->status_code : '') }}
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5" class="py-8 text-center text-zinc-500">
                            No checks recorded yet.
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>

    <div class="grid gap-4 lg:grid-cols-2">
        <flux:card class="space-y-3">
            <flux:heading size="lg">Certificate</flux:heading>

            @if (! $monitor->certificate_check_enabled)
                <flux:text variant="subtle">Not checked.</flux:text>
            @else
                <flux:text>
                    {{ Str::headline($monitor->certificate_status) }}
                    @if ($monitor->certificate_expiration_date)
                        · expires {{ $monitor->certificate_expiration_date->diffForHumans() }}
                    @endif
                </flux:text>
                @if ($monitor->certificate_issuer)
                    <flux:text variant="subtle" class="text-xs">{{ $monitor->certificate_issuer }}</flux:text>
                @endif
                @if ($monitor->certificate_check_failure_reason)
                    <flux:text variant="subtle" class="text-xs">{{ $monitor->certificate_check_failure_reason }}</flux:text>
                @endif
            @endif
        </flux:card>

        <flux:card class="space-y-3">
            <flux:heading size="lg">Domain</flux:heading>

            @if (! $monitor->domain_expiry_check_enabled)
                <flux:text variant="subtle">Not checked.</flux:text>
            @else
                <flux:text>
                    {{ Str::headline($monitor->domain_expiry_status) }}
                    @if ($monitor->domain_expiration_date)
                        · expires {{ $monitor->domain_expiration_date->diffForHumans() }}
                    @endif
                </flux:text>
                @if ($monitor->domain_registrar)
                    <flux:text variant="subtle" class="text-xs">{{ $monitor->domain_registrar }}</flux:text>
                @endif
            @endif
        </flux:card>
    </div>

    <livewire:monitoring-forge-sites :monitor-id="$monitor->id" :key="'forge-'.$monitor->id" />

    <flux:card class="space-y-3">
        <flux:heading size="lg">Incidents</flux:heading>

        @forelse ($incidents as $incident)
            <div class="flex items-center justify-between border-b border-zinc-100 py-2 last:border-0 dark:border-zinc-800">
                <flux:text variant="subtle" class="text-xs">{{ $incident->failure_reason }}</flux:text>
                <div class="text-right whitespace-nowrap">
                    <flux:text class="text-xs">{{ $incident->started_at->diffForHumans() }}</flux:text>
                    <flux:text variant="subtle" class="text-xs">
                        {{ $incident->isOngoing() ? 'ongoing' : $incident->duration_for_humans }}
                    </flux:text>
                </div>
            </div>
        @empty
            <flux:text variant="subtle">No incidents recorded.</flux:text>
        @endforelse
    </flux:card>
</div>
