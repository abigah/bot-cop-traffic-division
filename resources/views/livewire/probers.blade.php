<div class="mx-auto max-w-5xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
    <div class="space-y-1">
        <flux:heading size="xl">Probers</flux:heading>
        <flux:text variant="subtle">
            Who performs the checks. The list is configuration — adding one is a deploy —
            and everything beside a name here is what has actually been heard from it.
        </flux:text>
    </div>

    @unless ($checksRemotely)
        <flux:callout icon="information-circle">
            <flux:callout.heading>This application checks its own monitors</flux:callout.heading>
            <flux:callout.text>
                Nothing below is doing any work until <code>MONITORING_CHECKER=remote</code>.
                Certificate, domain-expiry and DNS checking stays here in either mode.
            </flux:callout.text>
        </flux:callout>
    @endunless

    @forelse ($probers as $prober)
        <flux:card class="space-y-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="space-y-1">
                    <div class="flex items-center gap-2">
                        <flux:heading size="lg">{{ $prober['id'] }}</flux:heading>

                        @if ($prober['never_seen'])
                            <x-monitoring::status-pill status="paused" label="Never heard from" />
                        @elseif ($prober['silent'])
                            <x-monitoring::status-pill status="down" label="Silent" />
                            @unless ($overlapping)
                                {{-- Nothing else is checking these monitors, so
                                     this is not degraded redundancy — it is
                                     nobody looking. --}}
                                <flux:badge size="sm" color="red">Nothing else is checking</flux:badge>
                            @endunless
                        @else
                            <x-monitoring::status-pill status="up" label="Delivering" />
                        @endif

                        @if ($prober['location'])
                            <flux:badge size="sm" variant="subtle">{{ $prober['location'] }}</flux:badge>
                        @endif
                    </div>

                    <flux:text variant="subtle" class="text-xs">{{ $prober['base_url'] }}</flux:text>
                </div>

                <div class="text-right">
                    <flux:text class="text-sm">
                        {{ $prober['status']?->last_seen_at?->diffForHumans() ?? 'No contact yet' }}
                    </flux:text>
                    <flux:text variant="subtle" class="text-xs">last heard from</flux:text>
                </div>
            </div>

            @unless ($prober['has_secret'])
                {{-- Without a secret nothing it sends can be verified, so
                     nothing it sends is accepted. This is the state a prober
                     sits in before anyone has finished setting it up. --}}
                <flux:callout variant="warning" icon="key">
                    <flux:callout.heading>No secret configured</flux:callout.heading>
                    <flux:callout.text>
                        Every request from this prober is refused until one is set. It signs with
                        a shared secret and there is nothing here to check the signature against.
                    </flux:callout.text>
                </flux:callout>
            @endunless

            <div class="grid gap-3 {{ $overlapping ? 'sm:grid-cols-4' : 'sm:grid-cols-3' }}">
                <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                    <flux:text variant="subtle" class="text-xs">Results</flux:text>
                    <flux:text class="font-medium">
                        {{ $prober['status']?->last_results_at?->diffForHumans() ?? '—' }}
                    </flux:text>
                </div>

                <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                    <flux:text variant="subtle" class="text-xs">Checks this month</flux:text>
                    <flux:text class="font-medium">{{ number_format($prober['checks']) }}</flux:text>
                </div>

                <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                    <flux:text variant="subtle" class="text-xs">Pings this month</flux:text>
                    <flux:text class="font-medium">{{ number_format($prober['pings']) }}</flux:text>
                </div>

                @if ($overlapping)
                    {{-- Only meaningful where two probers watch the same URL.
                         Where they are partitioned by site nothing is ever
                         contradicted, and a permanent zero is noise. --}}
                    <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                        <flux:text variant="subtle" class="text-xs">Uncorroborated</flux:text>
                        <flux:text class="font-medium">
                            {{ $prober['disagreements'] }}
                            <flux:text variant="subtle" class="text-xs">in 7 days</flux:text>
                        </flux:text>
                    </div>
                @endif
            </div>

            @if ($prober['disagreements'] > 0)
                {{-- Failures this prober saw and another contradicted. A few is
                     a route having a bad day; a lot is one location that cannot
                     be trusted about this site. --}}
                <flux:text variant="subtle" class="text-xs">
                    {{ $prober['disagreements'] }} {{ Str::plural('failure', $prober['disagreements']) }}
                    only this prober saw. They were recorded and shown as degraded, and opened
                    no incident.
                </flux:text>
            @endif
        </flux:card>
    @empty
        <flux:card class="py-12 text-center">
            <flux:text variant="subtle">No probers configured.</flux:text>
        </flux:card>
    @endforelse

    @if ($soleCover > 0 && ! $overlapping)
        <flux:callout icon="information-circle">
            <flux:callout.heading>One prober covers each of these sites</flux:callout.heading>
            <flux:callout.text>
                No monitor here is being checked from more than one place, so a prober going
                silent means its sites stop being checked rather than being checked from one
                place instead of two. That is a fine way to run this — it is capacity rather
                than redundancy, and worth knowing which one you have.
            </flux:callout.text>
        </flux:callout>
    @endif

    @if ($unknown->isNotEmpty())
        <flux:callout variant="warning" icon="question-mark-circle">
            <flux:callout.heading>
                {{ $unknown->count() }} {{ Str::plural('prober', $unknown->count()) }} has delivered here but is no longer configured
            </flux:callout.heading>
            <flux:callout.text>
                {{ $unknown->join(', ') }} — the history it delivered is still here, and nothing
                it sends now will be accepted.
            </flux:callout.text>
        </flux:callout>
    @endif
</div>
