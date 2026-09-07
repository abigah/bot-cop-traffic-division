{{-- Renders nothing at all when everything is up. --}}
<div>
    @if ($incidents->isNotEmpty())
        <div class="border-b border-red-200 bg-red-50 dark:border-red-900 dark:bg-red-950">
            <div class="mx-auto max-w-7xl px-4 py-3 sm:px-6 lg:px-8">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <flux:icon.exclamation-triangle class="size-5 text-red-600 dark:text-red-400" />
                        <flux:text class="font-medium text-red-800 dark:text-red-200">
                            {{ $incidents->count() }} {{ Str::plural('monitor', $incidents->count()) }} down:
                            {{ $incidents->take(3)->map(fn ($incident) => $incident->monitor?->url)->filter()->join(', ') }}
                            @if ($incidents->count() > 3)
                                and {{ $incidents->count() - 3 }} more
                            @endif
                        </flux:text>
                    </div>

                    <flux:link href="{{ route('monitoring.incidents') }}" class="text-sm text-red-800 dark:text-red-200">
                        View incidents
                    </flux:link>
                </div>
            </div>
        </div>
    @endif
</div>
