<div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
    <flux:heading size="xl">Incidents</flux:heading>

    {{--
        A row of buttons rather than flux:tabs, which is the one component in
        this package that would require Flux Pro. A commercial dependency for a
        segmented control is a poor trade when the package is otherwise
        installable by anyone.
    --}}
    <div class="flex flex-wrap gap-2" role="tablist">
        @foreach ([
            'ongoing' => 'Ongoing ('.$counts['ongoing'].')',
            'resolved' => 'Resolved ('.$counts['resolved'].')',
            'archived' => 'Archived ('.$counts['archived'].')',
            'all' => 'All',
        ] as $value => $label)
            <flux:button
                size="sm"
                :variant="$filter === $value ? 'primary' : 'ghost'"
                wire:click="$set('filter', '{{ $value }}')"
                aria-selected="{{ $filter === $value ? 'true' : 'false' }}"
                role="tab"
            >
                {{ $label }}
            </flux:button>
        @endforeach
    </div>

    <flux:card>
{{--
            No wire:key on the Flux rows below. Livewire's compiled wire-key support and
            Flux's component tags disagree inside a @forelse, and the view fails to
            compile with an unbalanced endif. Plain elements take a wire:key fine; Flux
            components do not. Removing it costs nothing here — these tables re-render
            whole rather than diffing row by row.
        --}}
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Monitor</flux:table.column>
                <flux:table.column>Started</flux:table.column>
                <flux:table.column>Duration</flux:table.column>
                <flux:table.column>Reason</flux:table.column>
                <flux:table.column />
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($incidents as $incident)
                    <flux:table.row>
                        <flux:table.cell>
                            <flux:link href="{{ route('monitoring.monitor.history', ['monitor' => $incident->monitor_id]) }}">
                                {{ $incident->monitor?->url }}
                            </flux:link>
                        </flux:table.cell>
                        <flux:table.cell class="whitespace-nowrap">
                            {{ $incident->started_at->diffForHumans() }}
                        </flux:table.cell>
                        <flux:table.cell class="whitespace-nowrap">
                            @if ($incident->isOngoing())
                                <x-monitoring::status-pill status="down" label="Ongoing" />
                            @else
                                {{ $incident->duration_for_humans }}
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="max-w-md truncate text-zinc-500">
                            {{ $incident->failure_reason }}
                            @if ($incident->dismissal_reason)
                                <flux:badge size="sm" variant="subtle">{{ Str::headline($incident->dismissal_reason) }}</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            @unless ($incident->archived_at)
                                <flux:button size="xs" variant="ghost" wire:click="openDismissModal({{ $incident->id }})">
                                    Dismiss
                                </flux:button>
                            @endunless
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5" class="py-8 text-center text-zinc-500">
                            Nothing here.
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>

        <div class="pt-4">{{ $incidents->links() }}</div>
    </flux:card>

    {{-- Dismissing is not resolving. An incident resolves when the site comes
         back; it is dismissed when a person decides it was a deploy or a test
         and not worth keeping in the record. --}}
    <flux:modal wire:model="showDismissModal" class="space-y-4">
        <flux:heading size="lg">Dismiss this incident</flux:heading>

        <flux:select wire:model="dismissalReason" label="Why?">
            <flux:select.option value="">Choose a reason</flux:select.option>
            <flux:select.option value="deployment">It was a deployment</flux:select.option>
            <flux:select.option value="testing">It was testing</flux:select.option>
            <flux:select.option value="third_party">A third party, not us</flux:select.option>
            <flux:select.option value="other">Something else</flux:select.option>
        </flux:select>

        <flux:textarea wire:model="dismissalNote" label="Note" placeholder="Optional" rows="3" />

        <div class="flex justify-end gap-2">
            <flux:button variant="ghost" wire:click="$set('showDismissModal', false)">Cancel</flux:button>
            <flux:button variant="primary" wire:click="dismissIncident">Dismiss</flux:button>
        </div>
    </flux:modal>
</div>
