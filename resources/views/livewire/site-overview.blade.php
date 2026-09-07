<div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="space-y-1">
            <flux:breadcrumbs>
                <flux:breadcrumbs.item href="{{ route('monitoring.sites') }}">Sites</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ $site->name }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>

            <div class="flex items-center gap-2">
                <flux:heading size="xl">{{ $site->name }}</flux:heading>

                @if ($site->isDown())
                    <x-monitoring::status-pill status="down" />
                @else
                    <x-monitoring::status-pill status="up" />
                @endif

                @if ($site->hibernates)
                    <flux:badge size="sm" variant="subtle" icon="moon">Hibernates</flux:badge>
                @endif
            </div>
        </div>

        <flux:select wire:model.live="period" size="sm" class="w-32">
            <flux:select.option value="1h">Last hour</flux:select.option>
            <flux:select.option value="24h">24 hours</flux:select.option>
            <flux:select.option value="7d">7 days</flux:select.option>
            <flux:select.option value="30d">30 days</flux:select.option>
        </flux:select>
    </div>

    @if ($deployment)
        {{-- Alerting is suppressed while this is open, so it needs to be the
             first thing read: a quiet dashboard during a deploy means nothing. --}}
        <flux:callout variant="warning" icon="rocket-launch">
            <flux:callout.heading>Deployment underway</flux:callout.heading>
            <flux:callout.text>
                Started {{ $deployment->started_at->diffForHumans() }}. Alerting is suppressed
                for every monitor on this site until it finishes, or for
                {{ config('monitoring.deployment.max_window_minutes') }} minutes.
            </flux:callout.text>
        </flux:callout>
    @endif

    <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
        <flux:card class="space-y-1 text-center">
            <flux:text variant="subtle" class="text-xs">Uptime</flux:text>
            <flux:heading size="xl">{{ $stats['uptime'] }}%</flux:heading>
        </flux:card>
        <flux:card class="space-y-1 text-center">
            <flux:text variant="subtle" class="text-xs">Avg response</flux:text>
            <flux:heading size="xl">{{ $stats['avg_ms'] ? $stats['avg_ms'].'ms' : '--' }}</flux:heading>
        </flux:card>
        <flux:card class="space-y-1 text-center">
            <flux:text variant="subtle" class="text-xs">Checks</flux:text>
            <flux:heading size="xl">{{ $stats['total'] }}</flux:heading>
        </flux:card>
        <flux:card class="space-y-1 text-center">
            <flux:text variant="subtle" class="text-xs">Incidents</flux:text>
            <flux:heading size="xl">{{ $stats['incidents'] ?? 0 }}</flux:heading>
        </flux:card>
    </div>

    {{-- Monitors --}}
    <flux:card class="space-y-4">
        <div class="flex items-center justify-between">
            <flux:heading size="lg">Monitors</flux:heading>
            <flux:button size="sm" icon="plus" wire:click="newMonitor">Add monitor</flux:button>
        </div>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>URL</flux:table.column>
                <flux:table.column>Status</flux:table.column>
                <flux:table.column>Interval</flux:table.column>
                <flux:table.column>Last checked</flux:table.column>
                <flux:table.column />
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($monitors as $monitor)
                    <flux:table.row>
                        <flux:table.cell>
                            <flux:link href="{{ route('monitoring.monitor.history', ['monitor' => $monitor->id]) }}">
                                {{ $monitor->url }}
                            </flux:link>
                            @if ($monitor->critical)
                                <flux:badge size="sm" variant="subtle">Critical</flux:badge>
                            @endif
                            @if ($monitor->served_from_cache_at)
                                {{-- A cached 200 never reached the origin, so it
                                     proves nothing about whether the app is running. --}}
                                <flux:badge size="sm" color="amber" icon="exclamation-triangle">Served from cache</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            @if (! $monitor->uptime_check_enabled)
                                <x-monitoring::status-pill status="paused" />
                            @elseif ($monitor->isDown())
                                <x-monitoring::status-pill status="down" />
                            @else
                                <x-monitoring::status-pill status="up" />
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            {{ $monitor->uptime_check_interval_in_minutes }}m
                            @if ($monitor->clamped_interval_minutes)
                                {{-- The prober owns its own tiers; a clamp is
                                     shown rather than silently applied. --}}
                                <flux:badge size="sm" color="zinc">
                                    checked every {{ $monitor->clamped_interval_minutes }}m
                                </flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="text-zinc-500">
                            {{ $monitor->uptime_last_check_date?->diffForHumans() ?? 'Never' }}
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex justify-end gap-1">
                                <flux:button size="xs" variant="ghost" wire:click="editMonitor({{ $monitor->id }})">Edit</flux:button>
                                <flux:button size="xs" variant="ghost" wire:click="toggleMonitorPaused({{ $monitor->id }})">
                                    {{ $monitor->uptime_check_enabled ? 'Pause' : 'Resume' }}
                                </flux:button>
                                <flux:button
                                    size="xs"
                                    variant="ghost"
                                    wire:click="deleteMonitor({{ $monitor->id }})"
                                    wire:confirm="Delete this monitor? Its checks, incidents and history go with it."
                                >
                                    Delete
                                </flux:button>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5" class="text-center text-zinc-500">
                            No monitors on this site yet.
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>

    {{-- Heartbeats --}}
    <flux:card class="space-y-4">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <flux:heading size="lg">Scheduled work</flux:heading>

                @if ($suppressedHeartbeats > 0)
                    <flux:text variant="subtle" class="text-xs">
                        {{ $suppressedHeartbeats }} swallowed by an outage
                    </flux:text>
                @endif
            </div>

            <flux:button size="sm" icon="plus" wire:click="newHeartbeat">Declare work</flux:button>
        </div>

        @if ($revealedToken)
            {{-- Shown once here as a convenience. It is in the manifest and in
                 the database, so this is not the only copy. --}}
            <flux:callout icon="key">
                <flux:callout.heading>Ping this URL from the job</flux:callout.heading>
                <flux:callout.text>
                    <code class="break-all">{{ route('monitoring.ping', ['token' => $revealedToken]) }}</code>
                    <br>
                    Append <code>/start</code> and <code>/fail</code> for work that begins and ends.
                </flux:callout.text>
            </flux:callout>
        @endif

        @forelse ($heartbeats as $heartbeat)
            <div class="flex items-center justify-between border-b border-zinc-100 py-2 last:border-0 dark:border-zinc-800">
                <div>
                    <flux:text class="font-medium">{{ $heartbeat->name }}</flux:text>
                    <flux:text variant="subtle" class="text-xs">
                        @if ($heartbeat->isEvent())
                            Event · times out after {{ $heartbeat->timeoutMinutes() }}m
                        @else
                            Every {{ $heartbeat->interval_minutes }}m · {{ $heartbeat->graceMinutes() }}m grace
                        @endif
                        · last seen {{ $heartbeat->last_ping_at?->diffForHumans() ?? 'never' }}
                    </flux:text>
                </div>

                <div class="flex items-center gap-2">
                    <flux:button size="xs" variant="ghost" wire:click="editHeartbeat({{ $heartbeat->id }})">Edit</flux:button>
                    <flux:button
                        size="xs"
                        variant="ghost"
                        wire:click="rotateHeartbeatToken({{ $heartbeat->id }})"
                        wire:confirm="Mint a new token? Pings using the old one fail until every prober has pulled a fresh manifest."
                    >
                        New token
                    </flux:button>
                    <flux:button
                        size="xs"
                        variant="ghost"
                        wire:click="deleteHeartbeat({{ $heartbeat->id }})"
                        wire:confirm="Delete this heartbeat? Nothing will notice if the job stops running."
                    >
                        Delete
                    </flux:button>

                    @if ($heartbeat->awaitingFirstPingSinceRotation())
                        {{-- Nobody is paged while this is showing: the job is
                             still sending the old token and cannot know better
                             until it is deployed again. --}}
                        <flux:badge size="sm" color="amber" icon="key">
                            Waiting for a ping with the new token
                        </flux:badge>
                    @endif

                    @if ($heartbeat->tracks_deployments)
                        <flux:badge size="sm" variant="subtle" icon="rocket-launch">Deploys</flux:badge>
                    @endif

                    @if ($heartbeat->suppressed_since)
                        <flux:badge size="sm" variant="subtle" title="Missed while the site was down">
                            During an outage
                        </flux:badge>
                    @endif

                    @if (! $heartbeat->enabled)
                        <x-monitoring::status-pill status="paused" />
                    @elseif ($heartbeat->status->isAlerting())
                        <x-monitoring::status-pill status="down" :label="Str::headline($heartbeat->status->value)" />
                    @else
                        <x-monitoring::status-pill status="up" :label="Str::headline($heartbeat->status->value)" />
                    @endif
                </div>
            </div>
        @empty
            <flux:text variant="subtle">
                Nothing declared. A job pings <code>/monitoring/ping/{token}</code> from inside
                <code>handle()</code>, and stops being invisible when it stops running.
            </flux:text>
        @endforelse
    </flux:card>

    {{-- Exceptions --}}
    <flux:card class="space-y-4">
        <div class="flex items-center justify-between">
            <flux:heading size="lg">Server errors</flux:heading>
            @if ($resolvedErrors > 0)
                <flux:text variant="subtle" class="text-xs">{{ $resolvedErrors }} resolved</flux:text>
            @endif
        </div>

        @forelse ($exceptions as $exception)
            <div class="flex items-start justify-between gap-4 border-b border-zinc-100 py-3 last:border-0 dark:border-zinc-800">
                <div class="min-w-0 space-y-1">
                    <flux:text class="font-medium">{{ class_basename(str_replace('\\', '/', $exception->exception_class)) }}</flux:text>
                    <flux:text variant="subtle" class="truncate text-xs">{{ $exception->message }}</flux:text>
                    <flux:text variant="subtle" class="text-xs">
                        {{ $exception->file }}:{{ $exception->line }} ·
                        {{ $exception->occurrences }} {{ Str::plural('time', $exception->occurrences) }} ·
                        last {{ $exception->last_seen_at->diffForHumans() }}
                    </flux:text>
                </div>

                <flux:button
                    size="xs"
                    variant="ghost"
                    wire:click="resolveException({{ $exception->id }})"
                    title="A recurrence after this counts as new again"
                >
                    Resolve
                </flux:button>
            </div>
        @empty
            <flux:text variant="subtle">No unresolved errors.</flux:text>
        @endforelse
    </flux:card>

    {{-- Incidents --}}
    <flux:card class="space-y-4">
        <flux:heading size="lg">Recent incidents</flux:heading>

        @forelse ($incidents as $incident)
            <div class="flex items-center justify-between border-b border-zinc-100 py-2 last:border-0 dark:border-zinc-800">
                <div>
                    <flux:text class="font-medium">{{ $incident->monitor?->url }}</flux:text>
                    <flux:text variant="subtle" class="text-xs">{{ $incident->failure_reason }}</flux:text>
                </div>
                <div class="text-right">
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

    {{-- What the package knows about this site that the host's own model has no
         reason to store. --}}
    <flux:card class="space-y-4">
        <flux:heading size="lg">Settings</flux:heading>

        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="max-w-lg space-y-1">
                <flux:text class="font-medium">This site hibernates</flux:text>
                <flux:text variant="subtle" class="text-xs">
                    It is only awake when something wakes it, so every check costs a cold start.
                    Saying so floors its monitors at
                    {{ config('monitoring.uptime.hibernating_interval_minutes') }} minutes and leans
                    on scheduled work to cover the gap between them.
                </flux:text>
            </div>

            <flux:switch wire:click="toggleHibernates" :checked="$site->hibernates" />
        </div>

        <flux:separator />

        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="max-w-lg space-y-1">
                <flux:text class="font-medium">Ingest token</flux:text>
                <flux:text variant="subtle" class="text-xs">
                    What this site's own code reports server errors with. It grants nothing else.
                </flux:text>

                @if ($revealedIngestToken)
                    <flux:text class="pt-1 text-xs">
                        <code class="break-all">{{ route('monitoring.report', ['token' => $revealedIngestToken]) }}</code>
                    </flux:text>
                @endif
            </div>

            <div class="flex gap-2">
                <flux:button size="sm" variant="ghost" wire:click="revealIngestToken">Show</flux:button>
                <flux:button
                    size="sm"
                    variant="ghost"
                    wire:click="rotateIngestToken"
                    wire:confirm="Mint a new token? Reports using the old one are refused until every prober has pulled a fresh manifest."
                >
                    New token
                </flux:button>
            </div>
        </div>
    </flux:card>

    {{-- Monitor form --}}
    <flux:modal wire:model="showMonitorForm" class="space-y-4 md:w-160">
        <flux:heading size="lg">{{ $editingMonitorId ? 'Edit monitor' : 'Add a monitor' }}</flux:heading>

        <flux:input wire:model="monitorForm.url" label="URL" placeholder="https://example.com/" />
        <flux:input wire:model="monitorForm.name" label="Name" placeholder="Optional" />

        <div class="grid gap-4 sm:grid-cols-2">
            <flux:select wire:model="monitorForm.method" label="Method">
                <flux:select.option value="GET">GET</flux:select.option>
                <flux:select.option value="HEAD">HEAD</flux:select.option>
                <flux:select.option value="POST">POST</flux:select.option>
            </flux:select>

            <flux:input
                type="number"
                wire:model="monitorForm.interval"
                label="Check every (minutes)"
                :description="'The shortest this tenant allows is '.$this->minimumInterval().' minutes.'"
            />
        </div>

        <flux:input
            wire:model="monitorForm.look_for_string"
            label="Look for"
            description="The check fails if this string is missing from the response."
        />

        <flux:input
            wire:model="monitorForm.fail_for_string"
            label="Fail for"
            description="The check fails if this string is present."
        />

        <flux:checkbox
            wire:model="monitorForm.critical"
            label="Critical"
            description="A site is down when a critical monitor is. Non-critical ones still record checks and open their own incidents, but they never suppress a missed-job alert."
        />

        <flux:checkbox wire:model="monitorForm.enabled" label="Checking enabled" />

        <div class="grid gap-2 sm:grid-cols-2">
            <flux:checkbox wire:model="monitorForm.certificate" label="Check the certificate" />
            <flux:checkbox wire:model="monitorForm.domain" label="Check domain expiry" />
        </div>

        <div class="flex justify-end gap-2">
            <flux:button variant="ghost" wire:click="$set('showMonitorForm', false)">Cancel</flux:button>
            <flux:button variant="primary" wire:click="saveMonitor">Save</flux:button>
        </div>
    </flux:modal>

    {{-- Heartbeat form --}}
    <flux:modal wire:model="showHeartbeatForm" class="space-y-4 md:w-160">
        <flux:heading size="lg">{{ $editingHeartbeatId ? 'Edit scheduled work' : 'Declare scheduled work' }}</flux:heading>

        <flux:input wire:model="heartbeatForm.name" label="Name" placeholder="Nightly digest" />

        <flux:select wire:model.live="heartbeatForm.kind" label="Kind">
            <flux:select.option value="heartbeat">Heartbeat — runs on a schedule</flux:select.option>
            <flux:select.option value="event">Event — starts and finishes</flux:select.option>
        </flux:select>

        @if (($heartbeatForm['kind'] ?? 'heartbeat') === 'event')
            <flux:input
                type="number"
                wire:model="heartbeatForm.timeout_minutes"
                label="Times out after (minutes)"
                description="A start with no finish inside this window has timed out."
            />

            <flux:checkbox
                wire:model="heartbeatForm.tracks_deployments"
                label="This is the deploy"
                description="The site's deployment URLs signal this event, so a deploy that opens a suppression window and never closes it is noticed rather than only quietly timing out. One per site."
            />
        @else
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input type="number" wire:model="heartbeatForm.interval_minutes" label="Expected every (minutes)" />
                <flux:input
                    type="number"
                    wire:model="heartbeatForm.grace_minutes"
                    label="Grace (minutes)"
                    description="How late is still fine."
                />
            </div>
        @endif

        <flux:input
            wire:model="heartbeatForm.job_class"
            label="Job class"
            placeholder="App\Jobs\SendNightlyDigest"
            description="Optional. Only needed when a listener pings on the job's behalf rather than the job pinging from inside handle()."
        />

        <flux:checkbox wire:model="heartbeatForm.enabled" label="Enabled" />

        <div class="flex justify-end gap-2">
            <flux:button variant="ghost" wire:click="$set('showHeartbeatForm', false)">Cancel</flux:button>
            <flux:button variant="primary" wire:click="saveHeartbeat">Save</flux:button>
        </div>
    </flux:modal>
</div>
