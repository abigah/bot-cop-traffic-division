<div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl">Sites</flux:heading>
            <flux:text variant="subtle">
                {{ $summary['sites'] }} {{ Str::plural('site', $summary['sites']) }},
                {{ $summary['monitors'] }} {{ Str::plural('monitor', $summary['monitors']) }},
                {{ $summary['uptime'] }}% uptime over 24 hours
            </flux:text>
        </div>

        <div class="flex items-center gap-3">
            <flux:input wire:model.live.debounce.300ms="search" placeholder="Search sites or URLs" icon="magnifying-glass" size="sm" class="w-56" />

            <flux:select wire:model.live="filter" size="sm" class="w-40">
                <flux:select.option value="all">All sites</flux:select.option>
                <flux:select.option value="down">Down</flux:select.option>
                <flux:select.option value="quiet">Needs a look</flux:select.option>
            </flux:select>

            @if ($this->canCreateSites())
                <flux:button size="sm" icon="plus" wire:click="$set('showSiteForm', true)">Add site</flux:button>
            @endif
        </div>
    </div>

    @if ($selected)
        <flux:card class="flex flex-wrap items-center justify-between gap-3 py-3">
            <flux:text class="font-medium">
                {{ count($selected) }} {{ Str::plural('site', count($selected)) }} selected
            </flux:text>

            <div class="flex flex-wrap gap-2">
                <flux:button size="sm" wire:click="openBulkForm">Add a monitor to each</flux:button>
                <flux:button size="sm" variant="ghost" wire:click="bulkSetCritical(true)">Mark critical</flux:button>
                <flux:button size="sm" variant="ghost" wire:click="bulkSetCritical(false)">Mark non-critical</flux:button>
                <flux:button size="sm" variant="ghost" wire:click="$set('selected', [])">Clear</flux:button>
            </div>
        </flux:card>
    @endif

    @if ($summary['sites_down'] > 0)
        <flux:callout variant="danger" icon="exclamation-triangle">
            <flux:callout.heading>
                {{ $summary['sites_down'] }} {{ Str::plural('site', $summary['sites_down']) }} down
            </flux:callout.heading>
        </flux:callout>
    @endif

    @forelse ($sites as $row)
        <flux:card class="space-y-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="space-y-1">
                    <div class="flex items-center gap-2">
                        <flux:checkbox wire:model.live="selected" value="{{ $row['id'] }}" />

                        <flux:link href="{{ route('monitoring.site', ['site' => $row['id']]) }}" variant="ghost">
                            <flux:heading size="lg">{{ $row['name'] }}</flux:heading>
                        </flux:link>

                        @if ($row['down'])
                            <x-monitoring::status-pill status="down" />
                        @elseif ($row['degraded'])
                            <x-monitoring::status-pill status="degraded" label="Degraded" />
                        @elseif ($row['status_weight'] > 0)
                            <x-monitoring::status-pill status="quiet" label="Needs a look" />
                        @else
                            <x-monitoring::status-pill status="up" />
                        @endif

                        @if ($row['hibernates'])
                            <flux:badge size="sm" variant="subtle" icon="moon">Hibernates</flux:badge>
                        @endif
                    </div>

                    <flux:text variant="subtle" class="text-xs">
                        {{ $row['monitor_count'] }} {{ Str::plural('monitor', $row['monitor_count']) }}
                        ({{ $row['critical_count'] }} critical{{ $row['paused_count'] ? ", {$row['paused_count']} paused" : '' }})
                    </flux:text>
                </div>

                <div class="text-right">
                    @if ($row['response_ms'])
                        <flux:heading size="lg">{{ round($row['response_ms']) }}ms</flux:heading>
                        <flux:text variant="subtle" class="text-xs">last response</flux:text>
                    @endif
                </div>
            </div>

            {{-- The three signals, side by side. Reading them apart is how the
                 middle one goes unnoticed for a week. --}}
            <div class="grid gap-3 sm:grid-cols-3">
                <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                    <flux:text variant="subtle" class="text-xs">Uptime</flux:text>
                    <flux:text class="font-medium">
                        @if ($row['down'])
                            A critical monitor is down
                        @elseif ($row['degraded'])
                            A non-critical monitor is down
                        @else
                            All monitors responding
                        @endif
                    </flux:text>
                </div>

                <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                    <flux:text variant="subtle" class="text-xs">Scheduled work</flux:text>
                    <flux:text class="font-medium">
                        @if ($row['heartbeat_count'] === 0)
                            <span class="text-zinc-400">None declared</span>
                        @elseif ($row['missed_jobs'] > 0)
                            {{ $row['missed_jobs'] }} of {{ $row['heartbeat_count'] }} not running
                        @else
                            {{ $row['heartbeat_count'] }} running
                        @endif
                    </flux:text>
                </div>

                <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                    <flux:text variant="subtle" class="text-xs">Errors today</flux:text>
                    <flux:text class="font-medium">
                        @if ($row['new_errors'] === 0)
                            <span class="text-zinc-400">None</span>
                        @else
                            {{ $row['errors_today'] }} across {{ $row['new_errors'] }} {{ Str::plural('kind', $row['new_errors']) }}
                        @endif
                    </flux:text>
                </div>
            </div>
        </flux:card>
    @empty
        <flux:card class="space-y-3 py-12 text-center">
            <flux:heading size="lg">
                {{ $search !== '' || $filter !== 'all' ? 'Nothing matches' : 'No sites yet' }}
            </flux:heading>
            <flux:text variant="subtle">
                @if ($search !== '' || $filter !== 'all')
                    Try a different search or filter.
                @elseif ($this->canCreateSites())
                    Add one, or bring existing monitors across with
                    <code>monitoring:sites:backfill</code>.
                @else
                    This application supplies its own site model, so sites are created wherever
                    it already creates them. <code>monitoring:sites:backfill</code> attaches
                    existing monitors to them.
                @endif
            </flux:text>
        </flux:card>
    @endforelse

    <flux:modal wire:model="showSiteForm" class="space-y-4">
        <flux:heading size="lg">Add a site</flux:heading>

        <flux:input
            wire:model="newSiteName"
            label="Name"
            placeholder="example.com"
            description="A site is the unit of incidents, deploy windows and scheduled work. Its monitors come next."
        />

        <div class="flex justify-end gap-2">
            <flux:button variant="ghost" wire:click="$set('showSiteForm', false)">Cancel</flux:button>
            <flux:button variant="primary" wire:click="createSite">Add</flux:button>
        </div>
    </flux:modal>

    <flux:modal wire:model="showBulkForm" class="space-y-4 md:w-140">
        <flux:heading size="lg">Add a monitor to {{ count($selected) }} {{ Str::plural('site', count($selected)) }}</flux:heading>

        <flux:input
            wire:model="bulkForm.path"
            label="Path"
            placeholder="/up"
            description="Appended to each site's own host. Laravel's health route is the usual second monitor — note that it must never be served from cache, or the check never reaches the origin."
        />

        <flux:input type="number" wire:model="bulkForm.interval" label="Check every (minutes)" />

        <flux:input
            wire:model="bulkForm.look_for_string"
            label="Look for"
            description="Optional. The check fails if this string is missing."
        />

        <flux:checkbox
            wire:model="bulkForm.critical"
            label="Critical"
            description="Whether a failure here means the site is down."
        />

        <div class="flex justify-end gap-2">
            <flux:button variant="ghost" wire:click="$set('showBulkForm', false)">Cancel</flux:button>
            <flux:button variant="primary" wire:click="bulkAddMonitor">Add</flux:button>
        </div>
    </flux:modal>
</div>
