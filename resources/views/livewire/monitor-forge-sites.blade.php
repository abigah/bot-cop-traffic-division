{{-- Renders nothing at all when no Forge integration is configured, so the
     history screen is unchanged for applications that do not use Forge. --}}
<div>
    @if ($hasForgeProvider)
        <flux:card class="space-y-4">
            <div class="flex items-center justify-between">
                <div class="space-y-1">
                    <flux:heading size="lg">Forge</flux:heading>
                    <flux:text variant="subtle" class="text-xs">
                        A Forge site usually answers on more than one domain. The ones nobody
                        remembered to add are the ones that go down unnoticed.
                    </flux:text>
                </div>

                @if ($hasForgeAccess)
                    <flux:button size="sm" icon="link" wire:click="openLinkModal">Link a site</flux:button>
                @endif
            </div>

            @unless ($hasForgeAccess)
                <flux:callout icon="key">
                    <flux:callout.heading>No Forge credentials for this owner</flux:callout.heading>
                    <flux:callout.text>
                        The integration is configured but this owner has no usable token, so there
                        is nothing to list.
                    </flux:callout.text>
                </flux:callout>
            @endunless

            @forelse ($linkedSites as $forgeSite)
                <div class="flex items-center justify-between border-b border-zinc-100 py-2 last:border-0 dark:border-zinc-800">
                    <div>
                        <flux:text class="font-medium">{{ $forgeSite->site_name }}</flux:text>
                        <flux:text variant="subtle" class="text-xs">on {{ $forgeSite->server_name }}</flux:text>
                    </div>

                    <div class="flex gap-1">
                        <flux:button size="xs" variant="ghost" wire:click="openDomainsModal({{ $forgeSite->id }})">
                            Domains
                        </flux:button>
                        <flux:button size="xs" variant="ghost" wire:click="unlinkSite({{ $forgeSite->id }})">
                            Unlink
                        </flux:button>
                    </div>
                </div>
            @empty
                @if ($hasForgeAccess)
                    <flux:text variant="subtle">This monitor is not linked to a Forge site.</flux:text>
                @endif
            @endforelse
        </flux:card>

        <flux:modal wire:model="showLinkModal" class="space-y-4 md:w-140">
            <flux:heading size="lg">Link a Forge site</flux:heading>

            @if ($error)
                <flux:callout variant="danger">
                    <flux:callout.text>{{ $error }}</flux:callout.text>
                </flux:callout>
            @endif

            @if ($loadingServers)
                <flux:text variant="subtle">Asking Forge…</flux:text>
            @elseif ($forgeServers)
                <flux:select wire:model="selectedSite" label="Site">
                    <flux:select.option value="">Choose a site</flux:select.option>
                    @foreach ($forgeServers as $server)
                        @foreach ($server['sites'] as $site)
                            <flux:select.option value="{{ $server['id'] }}:{{ $site['id'] }}">
                                {{ $site['name'] }} — {{ $server['name'] }}
                            </flux:select.option>
                        @endforeach
                    @endforeach
                </flux:select>
            @elseif (! $error)
                <flux:text variant="subtle">Forge returned no servers for this owner.</flux:text>
            @endif

            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="$set('showLinkModal', false)">Cancel</flux:button>
                <flux:button variant="primary" wire:click="linkSite">Link</flux:button>
            </div>
        </flux:modal>

        <flux:modal wire:model="showDomainsModal" class="space-y-4 md:w-140">
            <flux:heading size="lg">Domains on this Forge site</flux:heading>

            @if ($domainsError)
                <flux:callout variant="danger">
                    <flux:callout.text>{{ $domainsError }}</flux:callout.text>
                </flux:callout>
            @endif

            @if ($loadingDomains)
                <flux:text variant="subtle">Asking Forge…</flux:text>
            @else
                @forelse ($domains as $domain)
                    <div class="flex items-center justify-between border-b border-zinc-100 py-2 last:border-0 dark:border-zinc-800">
                        <div>
                            <flux:text class="font-medium">{{ $domain }}</flux:text>
                            @if ($loop->first)
                                <flux:badge size="sm" variant="subtle">Primary</flux:badge>
                            @endif
                        </div>

                        @if (in_array('https://'.$domain, $watched, true))
                            <x-monitoring::status-pill status="up" label="Watched" />
                        @else
                            <flux:button size="xs" wire:click="createMonitorForDomain('{{ $domain }}')">
                                Watch it
                            </flux:button>
                        @endif
                    </div>
                @empty
                    @unless ($domainsError)
                        <flux:text variant="subtle">Forge lists no domains for this site.</flux:text>
                    @endunless
                @endforelse

                <flux:text variant="subtle" class="text-xs">
                    A new monitor joins this site, arrives non-critical, and looks for the first few
                    characters of the page title — so a check proves the application answered, not
                    just that a server did.
                </flux:text>
            @endif

            <div class="flex justify-end">
                <flux:button variant="ghost" wire:click="$set('showDomainsModal', false)">Close</flux:button>
            </div>
        </flux:modal>
    @endif
</div>
