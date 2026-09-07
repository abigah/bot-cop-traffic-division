<div class="mx-auto max-w-4xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
    <div class="space-y-1">
        <flux:heading size="xl">What you hear about</flux:heading>
        <flux:text variant="subtle">
            Set nothing and you get email and a notification in the app for everything —
            the right default for a system whose job is telling people things.
        </flux:text>
    </div>

    @forelse ($sites as $site)
        @php
            $sitePreference = $sitePreferences->get($site->getKey());
            $isOpen = $openSite && $openSite->getKey() === $site->getKey();
        @endphp

        <flux:card class="space-y-4">
            <button
                type="button"
                class="flex w-full items-center justify-between text-left"
                wire:click="toggleSite('{{ $site->getKey() }}')"
            >
                <div>
                    <flux:heading size="lg">{{ $site->name }}</flux:heading>
                    <flux:text variant="subtle" class="text-xs">
                        @if ($sitePreference)
                            {{ implode(', ', $sitePreference->channelsForEvent('uptime_failed')) ?: 'Nothing' }}
                        @else
                            Following the defaults
                        @endif
                    </flux:text>
                </div>

                <flux:icon :name="$isOpen ? 'chevron-up' : 'chevron-down'" class="size-5 text-zinc-400" />
            </button>

            @if ($isOpen)
                <flux:separator />

                <div class="space-y-3">
                    <flux:text class="font-medium">How to reach you</flux:text>

                    <div class="flex flex-wrap gap-6">
                        @foreach (['email_enabled' => 'Email', 'database_enabled' => 'In the app', 'sms_enabled' => 'SMS'] as $field => $label)
                            <flux:checkbox
                                :label="$label"
                                :checked="$sitePreference?->{$field} ?? ($field !== 'sms_enabled')"
                                wire:click="toggle('{{ $site->getKey() }}', null, '{{ $field }}')"
                            />
                        @endforeach
                    </div>
                </div>

                <flux:separator />

                <div class="space-y-3">
                    <flux:text class="font-medium">What to tell you about</flux:text>

                    <div class="grid gap-2 sm:grid-cols-2">
                        @foreach ($events as $field => $label)
                            <flux:checkbox
                                :label="$label"
                                :checked="$sitePreference?->{$field} ?? true"
                                wire:click="toggle('{{ $site->getKey() }}', null, '{{ $field }}')"
                            />
                        @endforeach
                    </div>
                </div>

                @if ($site->monitors->isNotEmpty())
                    <flux:separator />

                    <div class="space-y-3">
                        <flux:text class="font-medium">Exceptions for one monitor</flux:text>
                        <flux:text variant="subtle" class="text-xs">
                            A monitor with its own settings ignores the site's entirely, rather than
                            adding to them.
                        </flux:text>

                        @foreach ($site->monitors as $monitor)
                            @php $monitorPreference = $monitorPreferences->get($monitor->id); @endphp

                            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-100 py-2 last:border-0 dark:border-zinc-800">
                                <flux:text class="text-sm">{{ $monitor->url }}</flux:text>

                                <div class="flex items-center gap-4">
                                    @if ($monitorPreference)
                                        @foreach (['email_enabled' => 'Email', 'database_enabled' => 'In the app', 'sms_enabled' => 'SMS'] as $field => $label)
                                            <flux:checkbox
                                                :label="$label"
                                                :checked="$monitorPreference->{$field}"
                                                wire:click="toggle('{{ $site->getKey() }}', {{ $monitor->id }}, '{{ $field }}')"
                                            />
                                        @endforeach

                                        <flux:button size="xs" variant="ghost" wire:click="followSite({{ $monitor->id }})">
                                            Follow the site
                                        </flux:button>
                                    @else
                                        <flux:button
                                            size="xs"
                                            variant="ghost"
                                            wire:click="toggle('{{ $site->getKey() }}', {{ $monitor->id }}, 'email_enabled')"
                                        >
                                            Set separately
                                        </flux:button>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            @endif
        </flux:card>
    @empty
        <flux:card class="py-12 text-center">
            <flux:text variant="subtle">No sites to set preferences for yet.</flux:text>
        </flux:card>
    @endforelse
</div>
